<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RetireDemoRecords extends Command
{
    protected $signature = 'analista:retire-demo-records {--dry-run : Show matching sample records without changing data} {--hide-sample-projects : Retain untouched sample listings as private drafts}';

    protected $description = 'Remove identified legacy evaluator fixtures while preserving real accounts and dependent records';

    private const EVALUATORS = [
        'evaluator@analista.test' => 'Sofia Reyes',
        'miguel@analista.test' => 'Miguel Santos',
        'danica@analista.test' => 'Danica Cruz',
        'rafael@analista.test' => 'Rafael Navarro',
        'bea@analista.test' => 'Bea Flores',
        'nico@analista.test' => 'Nico Villanueva',
        'pending@analista.test' => 'Jamie Torres',
    ];

    private const PROJECTS = [
        'Bayanihan: a community volunteer coordination platform',
        'StudySpace: finding a place to focus on campus',
        'HarvestLink: connecting growers with community buyers',
        'AquaSense: monitoring water use through a small IoT prototype',
        'ClearPath: an accessible student service request portal',
        'SafeArchive: managing access to a small digital records collection',
        'CampusQueue: student service appointment prototype',
    ];

    public function handle(): int
    {
        $users = User::whereIn('email', array_keys(self::EVALUATORS))->get()->filter(fn (User $user) => $this->isSeededEvaluator($user));
        $projects = $this->option('hide-sample-projects') ? Project::whereIn('title', self::PROJECTS)->get()->filter(fn (Project $project) => $this->isUntouchedSampleProject($project)) : collect();
        $this->info($users->count().' legacy sample evaluator accounts; '.$projects->count().' untouched sample projects.');
        if ($this->option('dry-run') || ($users->isEmpty() && $projects->isEmpty())) {
            return self::SUCCESS;
        }

        // Snapshot the complete SQLite database before changing any legacy records.
        if (! app()->environment('testing')) {
            if (DB::getDriverName() !== 'sqlite') {
                $this->error('This cleanup requires a database backup. The automatic snapshot supports SQLite only.');
                return self::FAILURE;
            }
            $backupDirectory = storage_path('app/private/backups');
            File::ensureDirectoryExists($backupDirectory);
            $backup = $backupDirectory.'/before-demo-retirement-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
            DB::unprepared('VACUUM INTO '.DB::connection()->getPdo()->quote($backup));
            $this->line('A private database snapshot was saved before cleanup.');
        }

        $removed = 0;
        $archived = 0;
        $hidden = 0;
        DB::transaction(function () use ($users, $projects, &$removed, &$archived, &$hidden) {
            foreach ($users as $candidate) {
                $user = User::whereKey($candidate->id)->lockForUpdate()->first();
                if (! $user || ! $this->isSeededEvaluator($user)) continue;
                if ($this->hasDependentRecords($user)) {
                    $user->forceFill(['is_suspended' => true, 'accepting_requests' => false, 'evaluator_status' => 'rejected', 'moderation_note' => 'Retired sample evaluator account. Related records were preserved.'])->save();
                    $archived++;
                } else {
                    $user->delete();
                    $removed++;
                }
            }
            foreach ($projects as $candidate) {
                $project = Project::whereKey($candidate->id)->lockForUpdate()->first();
                if (! $project || ! $this->isUntouchedSampleProject($project)) continue;
                $project->update(['status' => 'draft', 'is_public' => false]);
                $hidden++;
            }
            // This announcement refers only to the original seed, not genuine applications.
            DB::table('activities')->where('title', 'Sample applications are ready to review')
                ->where('body', 'Demo data: one evaluator application and one thesis project are awaiting your decision.')
                ->whereIn('user_id', User::where('email', 'admin@analista.test')->select('id'))->delete();
        });
        $this->info("Removed {$removed} sample evaluators; archived {$archived} with existing records; retained {$hidden} sample projects as private drafts.");
        return self::SUCCESS;
    }

    private function isSeededEvaluator(User $user): bool
    {
        return $user->role === 'evaluator'
            && (self::EVALUATORS[$user->email] ?? null) === $user->name
            && (str_starts_with((string) $user->bio, 'Sample profile for exploring Analista.')
                || str_starts_with((string) $user->bio, 'Sample evaluator application awaiting an administrator decision.'));
    }

    private function isUntouchedSampleProject(Project $project): bool
    {
        return in_array($project->title, self::PROJECTS, true)
            && $project->school === 'Sample Community College'
            && in_array($project->owner?->email, ['student@analista.test', 'lia@analista.test'], true)
            && ($project->created_at?->equalTo($project->updated_at) ?? false)
            && str_starts_with($project->summary, 'A sample ')
            && ! $project->documents()->exists()
            && ! $project->reviewRequests()->exists();
    }

    private function hasDependentRecords(User $user): bool
    {
        foreach (['projects' => ['user_id'], 'project_user' => ['user_id'], 'document_versions' => ['user_id'],
            'review_requests' => ['evaluator_id', 'requested_by'], 'feedback' => ['user_id'], 'ratings' => ['user_id', 'evaluator_id'],
            'reports' => ['user_id'], 'activities' => ['user_id'], 'invitations' => ['invited_by'], 'tutorials' => ['user_id'],
            'tutorial_user' => ['user_id'], 'sessions' => ['user_id']] as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->where($column, $user->id)->exists()) return true;
            }
        }
        return DB::table('reports')->where('reportable_type', 'user')->where('reportable_id', $user->id)->exists();
    }
}

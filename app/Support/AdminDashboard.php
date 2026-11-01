<?php

namespace App\Support;

use App\Models\AdminAction;
use App\Models\EvaluatorVerification;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReviewRequest;
use App\Models\SensitiveFileAccess;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Read-only administration queries, limited to the requested section. */
final class AdminDashboard
{
    public static function data(Request $request): array
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $section = $request->query('section', 'overview');
        if (! is_string($section) || ! in_array($section, ['overview', 'evaluators', 'projects', 'reviews', 'reports', 'users', 'tutorials', 'invitations', 'history', 'file-access'], true)) {
            $section = 'overview';
        }

        $statusOptions = self::statusOptions($section);
        $rules = ['q' => ['nullable', 'string', 'max:200']];
        if ($statusOptions) {
            $rules['status'] = ['nullable', 'string', Rule::in(array_keys($statusOptions))];
        }
        if (in_array($section, ['users', 'file-access'], true)) {
            $rules['role'] = ['nullable', 'string', Rule::in(['student', 'evaluator', 'admin'])];
        }
        if ($section === 'file-access') {
            $rules['file_type'] = ['nullable', 'string', Rule::in(array_keys(SensitiveFileAccess::TYPE_LABELS))];
        }
        if ($section === 'history') {
            $rules['action'] = ['nullable', 'string', Rule::in(array_keys(AdminAction::ACTION_LABELS))];
        }
        $validated = $request->validate($rules);
        $filters = [
            'q' => trim($validated['q'] ?? ''),
            'status' => $section === 'reports'
                ? (array_key_exists('status', $validated) ? ($validated['status'] ?? '') : 'open')
                : ($validated['status'] ?? ($section === 'projects' ? 'submitted' : '')),
            'role' => $validated['role'] ?? '',
            'action' => $validated['action'] ?? '',
            'file_type' => $validated['file_type'] ?? '',
        ];
        $filterSelects = [];
        if ($statusOptions) {
            $filterSelects[] = ['name' => 'status', 'label' => 'Status', 'options' => $statusOptions];
        }
        if (in_array($section, ['users', 'file-access'], true)) {
            $filterSelects[] = ['name' => 'role', 'label' => 'Role', 'options' => ['' => 'All roles', 'student' => 'Students', 'evaluator' => 'Evaluators', 'admin' => 'Administrators']];
        }
        if ($section === 'file-access') {
            $filterSelects[] = ['name' => 'file_type', 'label' => 'File type', 'options' => ['' => 'All file types', ...SensitiveFileAccess::TYPE_LABELS]];
        }
        if ($section === 'history') {
            $filterSelects[] = ['name' => 'action', 'label' => 'Action', 'options' => ['' => 'All actions', ...AdminAction::ACTION_LABELS]];
        }

        $stats = [
            'students' => User::where('role', 'student')->count(),
            'evaluators' => User::where('role', 'evaluator')->where('evaluator_status', 'approved')->where('is_suspended', false)->count(),
            'projects' => Project::count(),
            'ready_projects' => Project::where('status', 'approved')->count(),
            'drafts' => Project::where('status', 'draft')->count(),
            'active_users' => User::where('is_suspended', false)->count(),
            'ongoing_reviews' => ReviewRequest::where('status', 'accepted')->count(),
            'completed_reviews' => ReviewRequest::where('status', 'completed')->count(),
            'overdue_reviews' => ReviewRequest::overdue()->count(),
            'pending_evaluators' => User::where('role', 'evaluator')
                ->whereHas('latestEvaluatorVerification', fn (Builder $query) => $query->where('status', 'pending'))->count(),
            'pending_projects' => Project::where('status', 'pending')->count(),
            'pending_reports' => Report::whereIn('status', ['pending', 'under_review'])->count(),
            'tutorials' => Tutorial::where('is_published', true)->count(),
        ];

        $evaluators = $projects = $reviews = $reports = $users = $tutorials = $invitations = $history = $fileAccesses = null;
        $reportSubjects = ['project' => collect(), 'user' => collect()];
        $historyReports = collect();
        $historySubjects = ['project' => collect(), 'user' => collect()];
        $query = null;
        $q = $filters['q'];
        $status = $filters['status'];

        if ($section === 'evaluators') {
            $query = User::where('role', 'evaluator');
            self::search($query, $q, ['name', 'email', 'headline']);
            if ($status === 'incomplete') {
                $query->whereDoesntHave('latestEvaluatorVerification');
            } elseif ($status === 'suspended') {
                $query->where('is_suspended', true);
            } elseif ($status !== '') {
                $query->where('evaluator_status', $status);
            }
            $evaluators = $query->orderByRaw("CASE WHEN evaluator_status = 'pending' THEN 0 ELSE 1 END")
                ->latest()->paginate(20, ['*'], 'evaluators_page')->withQueryString();
        } elseif ($section === 'projects') {
            $query = Project::with('owner')->withCount('documents');
            self::search($query, $q, ['title', 'school', 'course', 'category'], 'owner');
            if ($status === 'submitted') {
                $query->where('status', '!=', 'draft');
            } elseif ($status !== 'all') {
                $query->where('status', $status);
            }
            $projects = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->latest()->paginate(20, ['*'], 'projects_page')->withQueryString();
        } elseif ($section === 'reviews') {
            $query = ReviewRequest::query()->select(['id', 'project_id', 'evaluator_id', 'document_version_id', 'status', 'agreed_date', 'created_at'])
                ->with(['project:id,title', 'evaluator:id,name', 'document:id,version']);
            if ($q !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->where(function (Builder $search) use ($q, $pattern) {
                    $search->whereHas('project', fn (Builder $project) => $project->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas('evaluator', fn (Builder $evaluator) => $evaluator->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                    if (ctype_digit($q) && strlen($q) <= 18) {
                        $search->orWhere('id', (int) $q);
                    }
                });
            }
            if ($status === 'overdue') {
                $query->overdue();
            } elseif ($status !== '') {
                $query->where('status', $status === 'ongoing' ? 'accepted' : $status);
            }
            $today = today()->toDateString();
            $reviews = $query->orderByRaw("CASE WHEN status = 'accepted' AND agreed_date < ? THEN 0 WHEN status = 'accepted' THEN 1 ELSE 2 END", [$today])
                ->orderByRaw("CASE WHEN status = 'accepted' AND agreed_date < ? THEN agreed_date ELSE NULL END", [$today])
                ->orderByDesc('id')->paginate(20, ['*'], 'reviews_page')->withQueryString();
        } elseif ($section === 'reports') {
            $query = Report::query()->select(['id', 'reportable_type', 'reportable_id', 'reason', 'status', 'created_at']);
            self::search($query, $q, ['reason', 'reportable_type'], 'reporter', ['id', 'reportable_id']);
            if ($status === 'open') {
                $query->whereIn('status', ['pending', 'under_review']);
            } elseif ($status !== '') {
                $query->where('status', $status);
            }
            $reports = $query->orderByRaw("CASE WHEN status = 'resolved' THEN 1 ELSE 0 END")
                ->latest()->orderByDesc('id')->paginate(10, ['*'], 'reports_page')->withQueryString();
            $projectIds = $reports->where('reportable_type', 'project')->pluck('reportable_id')->unique();
            $userIds = $reports->where('reportable_type', 'user')->pluck('reportable_id')->unique();
            $reportSubjects = [
                'project' => $projectIds->isEmpty() ? collect() : Project::whereIn('id', $projectIds)->get(['id', 'title'])->keyBy('id'),
                'user' => $userIds->isEmpty() ? collect() : User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id'),
            ];
        } elseif ($section === 'users') {
            $query = User::query();
            self::search($query, $q, ['name', 'email']);
            if ($status !== '') {
                $query->where('is_suspended', $status === 'suspended');
            }
            if ($filters['role'] !== '') {
                $query->where('role', $filters['role']);
            }
            $users = $query->latest()->paginate(20, ['*'], 'users_page')->withQueryString();
        } elseif ($section === 'tutorials') {
            $query = Tutorial::with('author');
            self::search($query, $q, ['title', 'category', 'video_creator']);
            if ($status !== '') {
                $query->where('is_published', $status === 'published');
            }
            $tutorials = $query->latest()->paginate(20, ['*'], 'tutorials_page')->withQueryString();
        } elseif ($section === 'invitations') {
            $query = Invitation::query();
            self::search($query, $q, ['email']);
            if ($status === 'accepted') {
                $query->whereNotNull('accepted_at');
            } elseif ($status === 'pending') {
                $query->whereNull('accepted_at')->where('expires_at', '>', now());
            } elseif ($status === 'expired') {
                $query->whereNull('accepted_at')->where('expires_at', '<=', now());
            }
            $invitations = $query->latest()->paginate(20, ['*'], 'invitations_page')->withQueryString();
        } elseif ($section === 'history') {
            $query = AdminAction::with('actor:id,name');
            if ($q !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->where(function (Builder $search) use ($q, $pattern) {
                    $search->whereRaw("target_type LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('actor', fn (Builder $actor) => $actor->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                    if (ctype_digit($q) && strlen($q) <= 18) {
                        $search->orWhere('id', (int) $q)->orWhere('target_id', (int) $q);
                    }
                    $search->orWhere(function (Builder $reported) use ($pattern) {
                        $reported->where('target_type', 'report')->whereIn('target_id', Report::query()->select('id')
                            ->where(function (Builder $report) use ($pattern) {
                                $report->whereRaw("reason LIKE ? ESCAPE '!'", [$pattern])
                                    ->orWhereHas('reporter', fn (Builder $reporter) => $reporter->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                                    ->orWhere(function (Builder $projectReport) use ($pattern) {
                                        $projectReport->where('reportable_type', 'project')->whereIn('reportable_id', Project::query()->select('id')
                                            ->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]));
                                    })->orWhere(function (Builder $userReport) use ($pattern) {
                                        $userReport->where('reportable_type', 'user')->whereIn('reportable_id', User::query()->select('id')
                                            ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                                    });
                            }));
                    });
                });
            }
            if ($filters['action'] !== '') {
                $query->where('action', $filters['action']);
            }
            $history = $query->orderByDesc('id')->paginate(20, ['*'], 'history_page')->withQueryString();
            $reportIds = $history->where('target_type', 'report')->pluck('target_id')->unique();
            if ($reportIds->isNotEmpty()) {
                $historyReports = Report::whereIn('id', $reportIds)->select(['id', 'user_id', 'reportable_type', 'reportable_id', 'reason', 'created_at'])
                    ->with('reporter:id,name')->get()->keyBy('id');
                $projectIds = $historyReports->where('reportable_type', 'project')->pluck('reportable_id')->unique();
                $userIds = $historyReports->where('reportable_type', 'user')->pluck('reportable_id')->unique();
                $historySubjects = [
                    'project' => $projectIds->isEmpty() ? collect() : Project::whereIn('id', $projectIds)->get(['id', 'title'])->keyBy('id'),
                    'user' => $userIds->isEmpty() ? collect() : User::whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id'),
                ];
            }
        } elseif ($section === 'file-access') {
            $query = SensitiveFileAccess::with(['actor:id,name', 'project:id,title']);
            if ($q !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->where(function (Builder $search) use ($q, $pattern) {
                    $search->whereHas('actor', fn (Builder $actor) => $actor->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                    if (ctype_digit($q) && strlen($q) <= 18) {
                        foreach (['id', 'file_id', 'project_id', 'verification_id'] as $column) {
                            $search->orWhere($column, (int) $q);
                        }
                    }
                });
            }
            if ($filters['role'] !== '') {
                $query->where('actor_role', $filters['role']);
            }
            if ($filters['file_type'] !== '') {
                $query->where('file_type', $filters['file_type']);
            }
            $fileAccesses = $query->orderByDesc('id')->paginate(20, ['*'], 'file_access_page')->withQueryString();
        }

        $trends = [];
        $overviewCharts = [];
        if ($section === 'overview') {
            $projectCounts = DB::table('projects')->select('status')->selectRaw('COUNT(*) AS aggregate_count')
                ->groupBy('status')->pluck('aggregate_count', 'status')->map(fn ($count) => (int) $count)->all();
            $reviewCounts = DB::table('review_requests')->select('status')->selectRaw('COUNT(*) AS aggregate_count')
                ->groupBy('status')->pluck('aggregate_count', 'status')->map(fn ($count) => (int) $count)->all();
            $overviewCharts = [
                'projects' => self::statusChart($projectCounts, [
                    'draft' => 'Saved drafts', 'approved' => 'Ready for review', 'completed' => 'Completed',
                    'pending' => 'Pending review', 'needs_changes' => 'Needs changes', 'rejected' => 'Rejected', 'hidden' => 'Hidden',
                ], ['draft', 'approved', 'completed']),
                'reviews' => self::statusChart($reviewCounts, [
                    'accepted' => 'Ongoing', 'completed' => 'Completed', 'pending' => 'Pending', 'declined' => 'Declined',
                ], ['accepted', 'completed', 'pending', 'declined'], true),
            ];
            $end = now();
            $start = $end->copy()->subDays(7);
            $previousStart = $start->copy()->subDays(7);
            foreach ([
                ['New accounts', User::query(), 'created_at'],
                ['New projects', Project::query(), 'created_at'],
                ['Evaluator applications', EvaluatorVerification::query(), 'created_at'],
                ['Completed reviews', ReviewRequest::where('status', 'completed'), 'completed_at'],
            ] as [$label, $metricQuery, $dateColumn]) {
                $trends[] = [
                    'label' => $label,
                    'current' => (clone $metricQuery)->where($dateColumn, '>=', $start)->where($dateColumn, '<=', $end)->count(),
                    'previous' => (clone $metricQuery)->where($dateColumn, '>=', $previousStart)->where($dateColumn, '<', $start)->count(),
                ];
            }
        }
        $searchPlaceholder = match ($section) {
            'evaluators' => 'Search name, email, or headline',
            'projects' => 'Search title, school, course, or creator',
            'reviews' => 'Search project, evaluator, or review ID',
            'reports' => 'Search report ID, reason, or reporter',
            'users' => 'Search name or email',
            'tutorials' => 'Search title, category, or creator',
            'invitations' => 'Search invited email address',
            'history' => 'Search project, reporter, administrator, or ID',
            'file-access' => 'Search account name or record ID',
            default => '',
        };
        $resultCount = match ($section) {
            'evaluators' => $evaluators->total(), 'projects' => $projects->total(),
            'reviews' => $reviews->total(),
            'reports' => $reports->total(), 'users' => $users->total(),
            'tutorials' => $tutorials->total(), 'invitations' => $invitations->total(),
            'history' => $history->total(),
            'file-access' => $fileAccesses->total(),
            default => 0,
        };

        return compact('section', 'stats', 'filters', 'filterSelects', 'searchPlaceholder', 'resultCount',
            'evaluators', 'projects', 'reviews', 'reports', 'reportSubjects', 'users', 'tutorials', 'invitations', 'history', 'historyReports', 'historySubjects', 'fileAccesses', 'trends', 'overviewCharts');
    }

    private static function statusChart(array $counts, array $labels, array $required, bool $segments = false): array
    {
        $total = array_sum($counts);
        $unknownStatuses = array_diff(array_keys($counts), array_keys($labels));
        sort($unknownStatuses, SORT_STRING);
        $items = [];
        $offset = 0.0;
        foreach ([...array_keys($labels), ...$unknownStatuses] as $status) {
            $status = (string) $status;
            $count = $counts[$status] ?? 0;
            if ($count === 0 && ! in_array($status, $required, true)) {
                continue;
            }
            $label = $labels[$status] ?? Str::limit(Str::headline(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($status)) ?? '')), 80);
            $percent = $total > 0 ? (float) ($count / $total * 100) : 0.0;
            $item = ['status' => $status, 'label' => $label !== '' ? $label : 'Other', 'count' => $count, 'percent' => $percent];
            if ($segments) {
                $item['offset'] = $offset;
                $offset += $percent;
            }
            $items[] = $item;
        }

        return ['total' => $total, 'items' => $items];
    }

    private static function search(Builder $query, string $q, array $columns, ?string $nameRelation = null, array $idColumns = []): void
    {
        if ($q === '') {
            return;
        }
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
        $query->where(function (Builder $search) use ($q, $columns, $pattern, $nameRelation, $idColumns) {
            foreach ($columns as $column) {
                $search->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
            }
            if ($nameRelation) {
                $search->orWhereHas($nameRelation, fn (Builder $related) => $related->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            }
            if (ctype_digit($q) && strlen($q) <= 18) {
                foreach ($idColumns as $column) {
                    $search->orWhere($column, (int) $q);
                }
            }
        });
    }

    private static function statusOptions(string $section): array
    {
        return match ($section) {
            'evaluators' => ['' => 'All statuses', 'pending' => 'Pending account', 'approved' => 'Approved', 'needs_info' => 'More information needed', 'rejected' => 'Rejected', 'incomplete' => 'No submitted application', 'suspended' => 'Suspended'],
            'projects' => ['submitted' => 'Submitted projects', 'all' => 'All projects', 'draft' => 'Draft', 'pending' => 'Pending review', 'approved' => 'Ready for review', 'completed' => 'Completed', 'needs_changes' => 'Needs changes', 'rejected' => 'Rejected', 'hidden' => 'Hidden'],
            'reviews' => ['' => 'All review statuses', 'ongoing' => 'Ongoing', 'overdue' => 'Overdue', 'completed' => 'Completed', 'pending' => 'Pending request', 'declined' => 'Declined'],
            'reports' => ['open' => 'Open reports', '' => 'All statuses', 'pending' => 'Pending', 'under_review' => 'Under review', 'resolved' => 'Resolved'],
            'users' => ['' => 'All account statuses', 'active' => 'Active', 'suspended' => 'Suspended'],
            'tutorials' => ['' => 'All statuses', 'published' => 'Published', 'draft' => 'Draft'],
            'invitations' => ['' => 'All statuses', 'pending' => 'Pending', 'expired' => 'Expired', 'accepted' => 'Accepted'],
            default => [],
        };
    }
}

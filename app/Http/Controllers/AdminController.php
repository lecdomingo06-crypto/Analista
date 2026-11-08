<?php

namespace App\Http\Controllers;

use App\Models\EvaluatorVerification;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReviewRequest;
use App\Models\Tutorial;
use App\Models\User;
use App\Support\AdminAudit;
use App\Support\AdminDashboard;
use App\Support\YouTubeVideo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.index', AdminDashboard::data($request));
    }

    public function showEvaluatorVerification(Request $request, User $user): View
    {
        $this->authorizeAdmin($request);
        abort_unless($user->role === 'evaluator', 404);
        $evaluator = $user;
        $verifications = $user->evaluatorVerifications()->with(['evidence', 'reviewer'])->orderByDesc('id')->get();
        $verification = $verifications->first();
        $profileMatches = $verification?->matchesProfile($user) ?? false;
        $complete = $verification?->isComplete() ?? false;
        $canReview = $verification?->status === 'pending' && ! $user->is($request->user());
        $canApprove = $canReview && $profileMatches && $complete && ! $user->is_suspended;
        $reviewChecks = EvaluatorVerification::REVIEW_CHECKS;

        return view('admin.evaluator-verification', compact(
            'evaluator', 'verifications', 'verification', 'profileMatches', 'complete', 'canReview', 'canApprove', 'reviewChecks'
        ));
    }

    public function showReport(Request $request, Report $report): View
    {
        $this->authorizeAdmin($request);
        $context = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(['open', 'pending', 'under_review', 'resolved'])],
            'reports_page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $listFilters = ['status' => 'open'];
        if (trim($context['q'] ?? '') !== '') {
            $listFilters['q'] = trim($context['q']);
        }
        if (array_key_exists('status', $context)) {
            $listFilters['status'] = $context['status'] ?? '';
        }
        if (isset($context['reports_page'])) {
            $listFilters['reports_page'] = (int) $context['reports_page'];
        }
        $backUrl = route('admin.index', ['section' => 'reports', ...$listFilters]);
        $report->load('reporter:id,name');
        $subject = $report->subject();

        return view('admin.report', compact('report', 'subject', 'listFilters', 'backUrl'));
    }

    public function moderateEvaluator(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_if($user->role !== 'evaluator' || $user->is($request->user()), 403);
        $this->sanitizeInput($request, ['reason']);
        $rules = [
            'verification_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['approve', 'needs_info', 'reject'])],
            'reason' => ['required', 'string', 'max:2000'],
            'checks' => ['nullable', 'array:'.implode(',', array_keys(EvaluatorVerification::REVIEW_CHECKS))],
        ];
        foreach (array_keys(EvaluatorVerification::REVIEW_CHECKS) as $check) {
            $rules['checks.'.$check] = $request->input('decision') === 'approve'
                ? ['required', 'accepted'] : ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);

        DB::transaction(function () use ($user, $data, $request) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($user->role !== 'evaluator' || $user->is($request->user()), 403);
            $verification = $user->evaluatorVerifications()->orderByDesc('id')->lockForUpdate()->first();
            if (! $verification || (int) $verification->id !== (int) $data['verification_id'] || $verification->status !== 'pending') {
                throw ValidationException::withMessages([
                    'verification_id' => 'This application is no longer awaiting a decision. Open the latest submitted application before reviewing.',
                ]);
            }
            if ($data['decision'] === 'approve' && $user->is_suspended) {
                throw ValidationException::withMessages(['decision' => 'Reinstate this account before approving the evaluator.']);
            }
            if ($data['decision'] === 'approve' && ! $verification->matchesProfile($user)) {
                throw ValidationException::withMessages([
                    'decision' => 'The professional profile changed after submission. Request an updated application before approval.',
                ]);
            }
            if ($data['decision'] === 'approve' && ! $verification->isComplete()) {
                throw ValidationException::withMessages([
                    'decision' => 'Complete professional evidence, work sample, sample feedback, and attestation are required before approval.',
                ]);
            }
            if ($data['decision'] === 'approve' && $verification->evidence_type === 'document') {
                $evidence = $verification->evidence()->get();
                if ($evidence->isEmpty() || $evidence->contains(fn ($file) => ! Storage::disk('local')->exists($file->path))) {
                    throw ValidationException::withMessages(['decision' => 'The supporting document is unavailable. Request a replacement before approval.']);
                }
            }
            $approved = $data['decision'] === 'approve';
            $before = [
                'evaluator_status' => $user->evaluator_status,
                'verification_status' => $verification->status,
                'accepting_requests' => $user->accepting_requests,
            ];
            $status = match ($data['decision']) {
                'approve' => 'approved', 'needs_info' => 'needs_info', 'reject' => 'rejected',
            };
            $checks = [];
            foreach (array_keys(EvaluatorVerification::REVIEW_CHECKS) as $key) {
                $checks[$key] = $request->boolean('checks.'.$key);
            }
            $verification->forceFill([
                'status' => $status,
                'reviewer_id' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['reason'],
                'checks' => $checks,
            ])->save();
            $user->forceFill([
                'evaluator_status' => $status,
                'moderation_note' => $data['reason'],
                'accepting_requests' => $approved && ($user->evaluator_status !== 'approved' || $user->accepting_requests),
            ])->save();

            if (! $approved) {
                $this->closePendingEvaluatorRequests($user);
            }

            AdminAudit::record($request->user(), 'evaluator.'.$status, 'evaluator', $user->id, $before, [
                'evaluator_status' => $user->evaluator_status,
                'verification_status' => $verification->status,
                'accepting_requests' => $user->accepting_requests,
            ], $data['reason'], ['verification_id' => $verification->id]);

            $user->notifyActivity(
                match ($status) {
                    'approved' => 'Evaluator application approved',
                    'needs_info' => 'More information needed for your evaluator application',
                    default => 'Evaluator application not approved',
                },
                $approved
                    ? 'An administrator reviewed your application. You can now appear in the directory and accept thesis review requests. '.$data['reason']
                    : $data['reason'],
                route('account.evaluator-verification.show')
            );
        }, 3);

        return redirect()->route('admin.evaluators.show', $user)->with('success', match ($data['decision']) {
            'approve' => 'Evaluator approved. Your review record has been saved.',
            'needs_info' => 'Request for additional information sent. Your review record has been saved.',
            'reject' => 'Application rejected. Your review record has been saved.',
        });
    }

    public function showProject(Request $request, Project $project): View
    {
        $this->authorizeAdmin($request);
        $context = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(['submitted', 'all', 'draft', 'pending', 'approved', 'completed', 'needs_changes', 'rejected', 'hidden'])],
            'projects_page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $listFilters = ['status' => $context['status'] ?? 'submitted'];
        if (trim($context['q'] ?? '') !== '') {
            $listFilters['q'] = trim($context['q']);
        }
        if (isset($context['projects_page'])) {
            $listFilters['projects_page'] = (int) $context['projects_page'];
        }
        $backUrl = route('admin.index', ['section' => 'projects', ...$listFilters]);
        $project->load(['owner:id,name', 'documents' => fn ($query) => $query->orderByDesc('version')])->loadCount('documents');

        return view('admin.project', compact('project', 'backUrl'));
    }

    public function moderateProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->sanitizeInput($request, ['reason']);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'needs_changes', 'reject', 'hide'])],
            'reason' => [Rule::requiredIf($request->input('decision') !== 'approve'), 'nullable', 'string', 'max:2000'],
        ], [
            'reason.required' => 'Add a decision note before requesting changes, rejecting, or hiding a project.',
        ], ['reason' => 'decision note']);

        $status = [
            'approve' => 'approved', 'needs_changes' => 'needs_changes', 'reject' => 'rejected', 'hide' => 'hidden',
        ][$data['decision']];

        DB::transaction(function () use ($request, $project, $status, $data) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $before = ['status' => $project->status];
            $previousNote = $project->moderation_note;
            $note = $this->plain($data['reason'] ?? null);
            $project = $this->setProjectStatus($project, $status, $note);
            if ($before['status'] !== $project->status || $previousNote !== $note) {
                AdminAudit::record($request->user(), 'project.'.$status, 'project', $project->id,
                    $before, ['status' => $project->status], $note);
            }
        }, 3);

        return back()->with('success', $status === 'hidden'
            ? 'Project hidden from community listings. The project members have been notified.'
            : 'Project moderation saved. The project members have been notified.');
    }

    public function moderate(Request $request, Report $report): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->sanitizeInput($request, ['admin_note']);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'under_review', 'resolved'])],
            'action' => ['required_if:status,resolved', 'nullable', Rule::in(['dismiss', 'request_corrections', 'hide_content', 'suspend_account'])],
            'admin_note' => [Rule::requiredIf($request->input('action') !== 'dismiss'), 'nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $report, $data) {
            $report = Report::query()->lockForUpdate()->findOrFail($report->id);
            $project = $report->reportable_type === 'project' ? Project::query()->lockForUpdate()->find($report->reportable_id) : null;
            $subject = $report->reportable_type === 'user' ? User::query()->lockForUpdate()->find($report->reportable_id) : $project?->owner;
            $action = $data['status'] === 'resolved' ? ($data['action'] ?? 'dismiss') : null;
            $note = $this->plain($data['admin_note'] ?? null);
            $shouldApply = $action && ($report->status !== 'resolved' || $report->action !== $action);
            $before = ['status' => $report->status, 'report_action' => $report->action];
            $previousNote = $report->admin_note;
            $affectedBefore = [];
            $metadata = [];
            if ($shouldApply && $action !== 'dismiss' && $subject) {
                $metadata = ['affected_type' => $project && $action !== 'suspend_account' ? 'project' : 'user',
                    'affected_id' => $project && $action !== 'suspend_account' ? $project->id : $subject->id];
                $affectedBefore = $this->reportSubjectState($project, $subject, $action);
            }

            if ($shouldApply && $action !== 'dismiss') {
                if (! $subject) {
                    throw ValidationException::withMessages(['action' => 'The reported content no longer exists. You can dismiss this report.']);
                }
                if ($subject->role === 'admin' || $subject->is($request->user())) {
                    throw ValidationException::withMessages(['action' => 'This moderation action cannot be applied to your account or an administrator.']);
                }

                if ($action === 'suspend_account') {
                    $this->setSuspended($subject, true);
                } elseif ($project) {
                    $this->setProjectStatus($project, $action === 'hide_content' ? 'hidden' : 'needs_changes', $note);
                } elseif ($subject->role === 'evaluator') {
                    $verificationStatus = $action === 'hide_content' ? 'rejected' : 'needs_info';
                    $latestVerification = $subject->evaluatorVerifications()->latest('id')->lockForUpdate()->first();
                    if ($latestVerification?->status === 'pending') {
                        $metadata['verification_id'] = $latestVerification->id;
                        $latestVerification->forceFill([
                            'status' => $verificationStatus,
                            'reviewer_id' => $request->user()->id,
                            'reviewed_at' => now(),
                            'review_notes' => $note,
                        ])->save();
                    }
                    $subject->forceFill([
                        'evaluator_status' => $verificationStatus,
                        'accepting_requests' => false,
                        'moderation_note' => $note,
                    ])->save();
                    $this->closePendingEvaluatorRequests($subject);
                    $subject->notifyActivity(
                        'Your evaluator profile needs attention',
                        $note ?? 'An administrator has requested an update to your profile.',
                        route('account.evaluator-verification.show')
                    );
                } else {
                    $subject->notifyActivity('Profile update requested', $note ?? 'Please review your profile information.', route('account.profile'));
                }
            }

            $report->forceFill([
                'status' => $data['status'],
                'action' => $action,
                'admin_note' => $note,
                'resolved_at' => $data['status'] === 'resolved' ? now() : null,
            ])->save();

            $affectedAfter = $affectedBefore === [] ? [] : $this->reportSubjectState($project, $subject, $action);
            $after = ['status' => $report->status, 'report_action' => $report->action];
            if ($before !== $after || $previousNote !== $note || $affectedBefore !== $affectedAfter) {
                AdminAudit::record($request->user(), $data['status'] === 'resolved' ? 'report.resolved' : 'report.updated',
                    'report', $report->id, [...$before, ...$affectedBefore], [...$after, ...$affectedAfter], $note, $metadata);
            }

            if ($data['status'] === 'resolved') {
                $report->reporter?->notifyActivity(
                    'Your report has been reviewed',
                    'An administrator has reviewed and resolved your report. Thank you for helping keep Analista useful and respectful.',
                    route('account.dashboard', ['tab' => 'notifications'])
                );
            }
        }, 3);

        return back()->with('success', 'Report updated.');
    }

    public function toggleUser(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_if($user->role === 'admin' || $user->is($request->user()), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['suspend', 'reinstate'])]]);

        DB::transaction(function () use ($request, $user, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($user->role === 'admin' || $user->is($request->user()), 403);
            $before = ['is_suspended' => $user->is_suspended, 'accepting_requests' => $user->accepting_requests];
            $user = $this->setSuspended($user, $data['action'] === 'suspend');
            $after = ['is_suspended' => $user->is_suspended, 'accepting_requests' => $user->accepting_requests];
            if ($before !== $after) {
                AdminAudit::record($request->user(), $user->is_suspended ? 'user.suspended' : 'user.reinstated',
                    'user', $user->id, $before, $after);
            }
        }, 3);

        return back()->with('success', $data['action'] === 'suspend' ? 'Account suspended.' : 'Account reinstated.');
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = Str::lower(trim($data['email']));
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has an account. They can apply from their profile.']);
        }

        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($email, $token, $request) {
            $previous = Invitation::where('email', $email)->whereNull('accepted_at')->where('expires_at', '>', now())
                ->lockForUpdate()->get();
            foreach ($previous as $invitation) {
                $invitation->update(['expires_at' => now()]);
                AdminAudit::record($request->user(), 'invitation.invalidated', 'invitation', $invitation->id,
                    ['active' => true], ['active' => false]);
            }
            $invitation = Invitation::create([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
            ]);
            AdminAudit::record($request->user(), 'invitation.created', 'invitation', $invitation->id,
                [], ['active' => true]);
        });

        return back()->with('success', 'Invitation created. Share the private link with this professional; it expires in seven days.')
            ->with('invitation_url', route('register', ['invite' => $token]));
    }

    public function storeTutorial(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->tutorialData($request);
        $data['user_id'] = $request->user()->id;
        $data['slug'] = $this->uniqueSlug($data['title']);
        DB::transaction(function () use ($request, $data) {
            $tutorial = Tutorial::create($data);
            AdminAudit::record($request->user(), 'tutorial.created', 'tutorial', $tutorial->id, [], [
                'is_published' => $tutorial->is_published, 'featured' => $tutorial->featured,
            ]);
        }, 3);

        return back()->with('success', 'Tutorial created.');
    }

    public function updateTutorial(Request $request, Tutorial $tutorial): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->tutorialData($request);
        DB::transaction(function () use ($request, $tutorial, $data) {
            $tutorial = Tutorial::query()->lockForUpdate()->findOrFail($tutorial->id);
            $before = ['is_published' => $tutorial->is_published, 'featured' => $tutorial->featured];
            $tutorial->fill($data);
            $changedFields = array_keys($tutorial->getDirty());
            if ($changedFields !== []) {
                $tutorial->save();
                AdminAudit::record($request->user(), 'tutorial.updated', 'tutorial', $tutorial->id, $before, [
                    'is_published' => $tutorial->is_published, 'featured' => $tutorial->featured,
                ], null, ['changed_fields' => $changedFields]);
            }
        }, 3);

        return back()->with('success', 'Tutorial updated.');
    }

    public function destroyTutorial(Request $request, Tutorial $tutorial): RedirectResponse
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $tutorial) {
            $tutorial = Tutorial::query()->lockForUpdate()->findOrFail($tutorial->id);
            $before = ['is_published' => $tutorial->is_published, 'featured' => $tutorial->featured];
            $tutorial->delete();
            AdminAudit::record($request->user(), 'tutorial.deleted', 'tutorial', $tutorial->id, $before);
        }, 3);

        return back()->with('success', 'Tutorial deleted.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin() && ! $request->user()->is_suspended, 403);
    }

    private function setProjectStatus(Project $project, string $status, ?string $note): Project
    {
        $project = Project::query()->lockForUpdate()->findOrFail($project->id);
        if ($status === 'approved' && ! $project->documents()->exists()) {
            throw ValidationException::withMessages(['decision' => 'A thesis PDF must be uploaded before this project can be approved.']);
        }
        if ($status === 'approved' && ! $project->usesVolunteerReviews()
            && ($project->status === 'completed' || $project->reviewRequests()->where('status', 'completed')->exists())) {
            $status = 'completed';
        }
        $project->forceFill(['status' => $status, 'moderation_note' => $note])->save();
        if ($status === 'approved' && $project->usesVolunteerReviews()) {
            $project->syncReviewCompletion();
            $status = $project->status;
        }
        $members = $project->members()->get()->push($project->owner)->filter()->unique('id');
        foreach ($members as $member) {
            $member->notifyActivity(
                match ($status) {
                    'approved' => 'Thesis project approved',
                    'completed' => 'Completed thesis project restored',
                    default => 'Thesis project moderation update',
                },
                match ($status) {
                    'approved' => 'Your project is approved. You can now request a volunteer evaluator.',
                    'completed' => 'Your completed thesis project is available again. Its completed reviews and Messages remain available.',
                    default => 'Your project status is now '.str_replace('_', ' ', $status).'. '.($note ?? ''),
                },
                route('account.projects.show', $project)
            );
        }

        if (! in_array($status, ['approved', 'completed'], true)) {
            ReviewRequest::where('project_id', $project->id)->whereIn('status', ['pending', 'accepted'])
                ->with(['evaluator', 'requester'])->get()->each(function (ReviewRequest $review) use ($project) {
                    if ($review->status === 'pending') {
                        $review->update(['status' => 'declined']);
                        $review->evaluator?->notifyActivity(
                            'Review request closed',
                            'The request for “'.$project->title.'” was closed while the project undergoes moderation.',
                            route('account.dashboard', ['tab' => 'reviews'])
                        );
                        $review->requester?->notifyActivity(
                            'Review request closed',
                            'Your pending request for “'.$project->title.'” was closed. You can submit a new request after project approval.',
                            route('account.projects.show', $project)
                        );
                    } else {
                        $review->evaluator?->notifyActivity(
                            'Project review paused',
                            'Document access for “'.$project->title.'” is paused until the project is approved again.',
                            route('account.dashboard', ['tab' => 'reviews'])
                        );
                    }
                });
        }

        return $project;
    }

    private function closePendingEvaluatorRequests(User $user): void
    {
        ReviewRequest::where('evaluator_id', $user->id)->where('status', 'pending')
            ->with('requester')->get()->each(function (ReviewRequest $review) {
                $review->update(['status' => 'declined']);
                $review->requester?->notifyActivity(
                    'Review request closed',
                    'This evaluator is unavailable. You can request another approved evaluator.',
                    route('account.projects.show', $review->project_id)
                );
            });
    }

    private function setSuspended(User $user, bool $suspended): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($user->id);
        abort_if($user->role === 'admin', 403, 'Administrator accounts cannot be suspended.');
        $user->forceFill(['is_suspended' => $suspended])->save();
        if ($suspended) {
            $user->forceFill(['accepting_requests' => false])->save();
            $this->closePendingEvaluatorRequests($user);
        }
        $user->notifyActivity(
            $suspended ? 'Account suspended' : 'Account reinstated',
            $suspended
                ? 'An administrator suspended your account following a community review.'
                : 'Your account has been reinstated. You can sign in again.',
            route('account.dashboard')
        );

        return $user;
    }

    private function reportSubjectState(?Project $project, User $subject, string $action): array
    {
        if ($project && $action !== 'suspend_account') {
            return ['project_status' => $project->fresh()->status];
        }
        $subject = $subject->fresh();

        $state = [
            'evaluator_status' => $subject->evaluator_status,
            'is_suspended' => $subject->is_suspended,
            'accepting_requests' => $subject->accepting_requests,
        ];
        if ($subject->role === 'evaluator' && $action !== 'suspend_account') {
            $state['verification_status'] = $subject->evaluatorVerifications()->latest('id')->value('status');
        }

        return $state;
    }

    private function tutorialData(Request $request): array
    {
        $this->sanitizeInput($request, ['title', 'category', 'excerpt', 'body', 'video_creator']);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'excerpt' => ['required', 'string', 'max:500'],
            'youtube_url' => ['bail', 'required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                if (YouTubeVideo::idFromUrl($value) === null) {
                    $fail('Enter a valid HTTPS YouTube video link, such as https://www.youtube.com/watch?v=VIDEO_ID.');
                }
            }],
            'video_creator' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:60000'],
            'is_published' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
        ]);
        foreach (['title', 'category', 'excerpt', 'video_creator'] as $field) {
            $data[$field] = trim(strip_tags($data[$field]));
            if ($data[$field] === '') {
                throw ValidationException::withMessages([$field => 'Please enter text for this field.']);
            }
        }
        $data['youtube_video_id'] = YouTubeVideo::idFromUrl($data['youtube_url']);
        unset($data['youtube_url']);
        $data['body'] = trim(strip_tags($data['body'] ?? ''));
        $data['is_published'] = $request->boolean('is_published');
        $data['featured'] = $request->boolean('featured');

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'tutorial';
        $slug = $base;
        for ($number = 2; Tutorial::where('slug', $slug)->exists(); $number++) {
            $slug = $base.'-'.$number;
        }

        return $slug;
    }

    private function plain(?string $value): ?string
    {
        return $value === null ? null : trim(strip_tags($value));
    }

    private function sanitizeInput(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => $this->plain($request->input($field))]);
            }
        }
    }
}

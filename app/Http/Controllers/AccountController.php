<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\Tutorial;
use App\Models\User;
use App\Support\ProfilePhoto;
use App\Support\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    public const EXPERTISE_OPTIONS = [
        'Web', 'Mobile', 'IoT / Hardware', 'AI / ML', 'Database', 'Networking',
        'Security', 'UI / UX', 'Games', 'Health', 'Education', 'Business / POS',
        'Government', 'Other',
    ];

    public function dashboard(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $tab = (string) $request->query('tab', $request->route('section', ''));
        if ($user->isAdmin() && ! in_array($tab, ['notifications', 'inbox', 'bookmarks'], true)) {
            $request->session()->reflash();

            return redirect()->route('admin.index', match (true) {
                $tab === 'projects' => ['section' => 'projects'],
                $tab === 'reviews' || $request->routeIs('account.reviews') => ['section' => 'reviews'],
                default => [],
            });
        }
        if (($tab === 'reviews' || $request->routeIs('account.reviews')) && ! in_array($user->role, ['student', 'evaluator'], true)) {
            $request->session()->reflash();

            return redirect()->route('account.projects');
        }
        if ($tab === 'projects') {
            $query = $request->query();
            unset($query['tab']);
            $request->session()->reflash();

            return redirect()->route('account.projects', $query);
        }
        if ($tab === 'inbox') {
            $request->session()->reflash();

            return redirect()->route('account.dashboard', [...$request->query(), 'tab' => 'notifications']);
        }
        if (! in_array($tab, ['projects', 'reviews', 'notifications', 'bookmarks'], true)) {
            $request->session()->reflash();

            return redirect()->route($user->role === 'evaluator' ? 'account.reviews' : 'account.projects');
        }

        $projects = Project::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members->where('users.id', $user->id));
            })
            ->with('owner')
            ->withCount('documents')
            ->latest()
            ->get();

        $reviewRequests = ReviewRequest::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->with(['project', 'evaluator', 'requester'])
            ->latest()
            ->get();

        $assignedReviews = ReviewRequest::query()
            ->where('evaluator_id', $user->id)
            ->with(['project.owner', 'requester'])
            ->latest()
            ->get();

        $conversations = $assignedReviews->merge($reviewRequests)->unique('id')->sortByDesc('updated_at')->values();

        $activities = $user->activities()->latest()->paginate(20)->withQueryString();
        $bookmarks = $user->bookmarks()->where('is_published', true)->latest('tutorials.created_at')->get();
        $stats = [
            'projects' => $projects->count(),
            'pending_requests' => $reviewRequests->where('status', 'pending')->count(),
            'active_reviews' => $assignedReviews->where('status', 'accepted')->count(),
            'pending_reviews' => $assignedReviews->where('status', 'pending')->count(),
            'completed_reviews' => $assignedReviews->where('status', 'completed')->count(),
            'active_requests' => $reviewRequests->where('status', 'accepted')->count(),
            'completed_requests' => $reviewRequests->where('status', 'completed')->count(),
            'unread_notifications' => $user->activities()->whereNull('read_at')->count(),
        ];

        $reviewStatus = '';
        if ($tab === 'reviews') {
            $request->validate(['status' => ['nullable', Rule::in(['pending', 'accepted', 'completed', 'declined'])]]);
            $reviewStatus = $request->string('status')->toString();
            $assignedReviews = $assignedReviews->when($reviewStatus !== '', fn ($items) => $items->where('status', $reviewStatus))
                ->sortBy(fn ($review) => [$review->status !== 'pending', $review->status === 'pending' ? $review->created_at->timestamp : -$review->created_at->timestamp,
                    $review->status === 'pending' ? $review->id : -$review->id])->values();
            $reviewRequests = $reviewRequests->when($reviewStatus !== '', fn ($items) => $items->where('status', $reviewStatus))->values();
        }

        return view($tab === 'reviews' ? 'account.reviews' : 'account.dashboard', compact(
            'user', 'tab', 'projects', 'reviewRequests', 'assignedReviews', 'conversations', 'activities', 'bookmarks', 'stats', 'reviewStatus'
        ));
    }

    public function projects(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            $request->session()->reflash();

            return redirect()->route('admin.index', ['section' => 'projects']);
        }
        $statuses = ['draft', 'pending', 'approved', 'completed', 'needs_changes', 'rejected', 'hidden'];
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in($statuses)],
        ]);
        $search = $request->string('q')->trim()->toString();
        $status = $request->string('status')->toString();
        $projects = Project::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members->where('users.id', $user->id));
            })
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('school', 'like', '%'.$search.'%');
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->with(['owner', 'members', 'reviewRequests.evaluator:id,name', 'reviewRequests.document',
                'reviewRequests.ratings' => fn ($query) => $query->where('user_id', $user->id)])
            ->withCount(['documents', 'reviewRequests'])
            ->withReviewProgress()
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('account.projects', compact('user', 'projects', 'search', 'status', 'statuses'));
    }

    public function projectReviews(Request $request, Project $project): View
    {
        $user = $request->user();
        abort_unless($user->role === 'student' && ProjectAccess::isMember($project, $user), 403);
        $reviews = $project->reviewRequests()->select([
            'id', 'project_id', 'evaluator_id', 'document_version_id', 'status', 'preferred_date', 'agreed_date',
            'accepted_at', 'completed_at', 'created_at', 'updated_at',
        ])->with([
            'evaluator:id,name,headline,expertise,profile_photo_path',
            'document:id,version',
        ])->orderByRaw("CASE status WHEN 'completed' THEN 0 WHEN 'accepted' THEN 1 WHEN 'pending' THEN 2 WHEN 'declined' THEN 3 ELSE 4 END")
            ->latest()->orderByDesc('id')->paginate(12)->withQueryString();

        return view('account.project-reviews', compact('user', 'project', 'reviews'));
    }

    public function profile(Request $request): View
    {
        return view('account.profile', [
            'user' => $request->user(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        foreach (['name', 'city'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim(strip_tags($request->input($field)))]);
            }
        }
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'capacity' => $user->isEvaluator() ? ['sometimes', 'integer', 'min:1', 'max:10'] : ['prohibited'],
            'accepting_requests' => $user->isEvaluator() ? ['sometimes', 'boolean'] : ['prohibited'],
            'profile_photo' => ['nullable', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'remove_profile_photo' => ['sometimes', 'boolean'],
        ];
        foreach ([
            'headline', 'bio', 'expertise', 'skills', 'experience_years', 'review_modes', 'portfolio_url', 'apply',
            'email', 'username', 'role', 'evaluator_status', 'is_suspended', 'user_id', 'reviewer_id', 'profile',
            'password', 'current_password', 'password_confirmation', 'remember_token', 'profile_photo_path', 'google_id',
        ] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $data = $request->validate($rules);
        $controlsSubmitted = $request->hasAny(['capacity', 'accepting_requests']);
        if ($request->hasFile('profile_photo') && $request->boolean('remove_profile_photo')) {
            throw ValidationException::withMessages(['profile_photo' => 'Choose a new photo or remove your current photo, then save.']);
        }
        $newPhotoPath = $request->hasFile('profile_photo') ? ProfilePhoto::store($request->file('profile_photo'), $user) : null;
        $oldPhotoPath = null;

        try {
            $requiresApproval = DB::transaction(function () use ($user, $request, $data, $controlsSubmitted, $newPhotoPath, &$oldPhotoPath) {
                $account = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_if($account->is_suspended || $account->role !== $user->role, 403);
                if ($controlsSubmitted && ! $account->isEvaluator()) {
                    throw ValidationException::withMessages([
                        'capacity' => 'Only an approved evaluator can change review availability or capacity.',
                    ]);
                }

                $wasApprovedEvaluator = $account->isEvaluator();
                $account->fill(collect($data)->only(['name', 'city'])->all());
                if ($newPhotoPath || $request->boolean('remove_profile_photo')) {
                    $oldPhotoPath = $account->profile_photo_path;
                    $account->profile_photo_path = $newPhotoPath;
                }
                $requiresApproval = $wasApprovedEvaluator && $account->isDirty('name');
                if ($wasApprovedEvaluator && $controlsSubmitted) {
                    if (array_key_exists('capacity', $data)) {
                        $account->capacity = (int) $data['capacity'];
                    }
                    $account->accepting_requests = $request->boolean('accepting_requests');
                }
                if ($requiresApproval) {
                    $account->evaluator_status = 'pending';
                    $account->moderation_note = null;
                    $account->accepting_requests = false;
                }
                $account->save();

                if ($requiresApproval) {
                    User::where('role', 'admin')->where('is_suspended', false)->each(function (User $admin) use ($account) {
                        $admin->notifyActivity(
                            'Evaluator name changed',
                            $account->name.' changed their display name. An updated application must be submitted on Verification before approval can be restored.',
                            route('admin.evaluators.show', $account)
                        );
                    });
                    $account->notifyActivity(
                        'Updated verification needed',
                        'Your display name changed. Submit an updated evaluator application on Verification to restore approval.',
                        route('account.evaluator-verification.show')
                    );
                }

                return $requiresApproval;
            }, 3);
        } catch (\Throwable $exception) {
            ProfilePhoto::delete($user, $newPhotoPath);
            throw $exception;
        }
        ProfilePhoto::delete($user, $oldPhotoPath);

        return back()->with('success', $requiresApproval
            ? 'Your account details have been updated. Submit an updated application on Verification to restore evaluator approval.'
            : 'Your account details have been updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(10), 'max:72',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('Use a password no longer than 72 bytes. Symbols and accented letters may count as more than one byte.');
                    }
                },
            ],
        ]);

        $account = DB::transaction(function () use ($request, $data) {
            $account = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_if($account->is_suspended, 403);
            if (! Hash::check($data['current_password'], $account->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
            }
            $account->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ])->save();

            return $account;
        }, 3);

        Auth::setUser($account);
        $request->session()->regenerate();

        return redirect(route('account.profile').'#password')->with('success', 'Your password has been changed.');
    }

    public function bookmark(Request $request, Tutorial $tutorial): RedirectResponse
    {
        abort_unless($tutorial->is_published, 404);
        $changes = $request->user()->bookmarks()->toggle($tutorial->id);

        return back()->with('success', count($changes['attached']) ? 'Tutorial saved.' : 'Tutorial removed from saved items.');
    }

    public function notificationState(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        $activities = $user->activities()->with('actor:id,name,profile_photo_path')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
        $legacyEventKeys = $activities->getCollection()->whereNull('actor_id')->pluck('review_event_key')->filter()->values();
        $legacySenders = $legacyEventKeys->isEmpty() ? collect() : User::query()
            ->join('direct_messages', 'direct_messages.sender_id', '=', 'users.id')
            ->where('direct_messages.recipient_id', $user->id)->where('direct_messages.kind', 'review_event')
            ->whereIn('direct_messages.review_event_key', $legacyEventKeys)
            ->get(['users.id', 'users.name', 'users.profile_photo_path', 'direct_messages.review_event_key'])
            ->keyBy('review_event_key');
        $conversations = ReviewRequest::query()
            ->where(function ($query) use ($user) {
                $query->where('evaluator_id', $user->id)
                    ->orWhereHas('project', fn ($projects) => $projects->where(function ($projects) use ($user) {
                        $projects->where('user_id', $user->id)
                            ->orWhereHas('members', fn ($members) => $members->where('users.id', $user->id));
                    }));
            })
            ->with(['project:id,title', 'requester:id,name,profile_photo_path', 'evaluator:id,name,profile_photo_path'])
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(10)
            ->get(['id', 'project_id', 'requested_by', 'evaluator_id', 'status', 'updated_at']);
        $statusLabels = ['pending' => 'Pending review', 'accepted' => 'In review', 'completed' => 'Completed', 'declined' => 'Declined'];

        return response()->json([
            'user_id' => $user->id,
            'unread_count' => $user->activities()->whereNull('read_at')->count(),
            'activities' => $activities->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'title' => $activity->title,
                'body' => (string) $activity->body,
                'sender' => $this->notificationSender($activity->actor ?? $legacySenders->get($activity->review_event_key)),
                'url' => $this->notificationUrl($activity->url, $request),
                'created_at' => $activity->created_at->toIso8601String(),
                'created_label' => $activity->created_at->diffForHumans(),
                'read_at' => $activity->read_at?->toIso8601String(),
                'read_url' => route('account.notifications.read', $activity),
            ])->values()->all(),
            'next_page_url' => $activities->hasMorePages()
                ? route('account.notifications.state', ['page' => $activities->currentPage() + 1]) : null,
            'conversations' => $conversations->map(function (ReviewRequest $review) use ($user, $statusLabels) {
                $participant = (int) $review->evaluator_id === (int) $user->id ? $review->requester : $review->evaluator;

                return [
                    'id' => $review->id,
                    'title' => $review->project->title,
                    'participant_name' => $participant->name,
                    'participant_avatar_url' => $participant->profile_photo_url,
                    'participant_initials' => $participant->initials(),
                    'status' => $review->status,
                    'status_label' => $statusLabels[$review->status] ?? ucfirst(str_replace('_', ' ', $review->status)),
                    'updated_at' => $review->updated_at->toIso8601String(),
                    'updated_label' => $review->updated_at->diffForHumans(),
                    'url' => route('reviews.show', $review),
                ];
            })->values()->all(),
        ], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
    }

    public function notificationRead(Request $request, Activity $activity): RedirectResponse|JsonResponse
    {
        abort_unless((int) $activity->user_id === (int) $request->user()->id, 403);
        $request->user()->activities()->whereKey($activity->id)->whereNull('read_at')->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json([
                'user_id' => $request->user()->id,
                'unread_count' => $request->user()->activities()->whereNull('read_at')->count(),
                'read_at' => $activity->fresh()->read_at?->toIso8601String(),
            ], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
        }

        return back()->with('success', 'Notification marked as read.');
    }

    public function notificationReadAll(Request $request): RedirectResponse|JsonResponse
    {
        $readAt = now();
        $request->user()->activities()->whereNull('read_at')->update(['read_at' => $readAt]);

        if ($request->expectsJson()) {
            return response()->json([
                'user_id' => $request->user()->id,
                'unread_count' => $request->user()->activities()->whereNull('read_at')->count(),
                'read_at' => $readAt->toIso8601String(),
            ], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache']);
        }

        return back()->with('success', 'All notifications marked as read.');
    }

    private function notificationSender(?User $sender): array
    {
        return $sender ? [
            'name' => $sender->name,
            'avatar_url' => $sender->profile_photo_url,
            'initials' => $sender->initials(),
            'kind' => 'person',
        ] : ['name' => 'Analista', 'avatar_url' => null, 'initials' => 'A', 'kind' => 'system'];
    }

    private function notificationUrl(?string $url, Request $request): ?string
    {
        if (! $url || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || str_starts_with($url, '//')) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            $origin = static function (array $parts): ?string {
                $scheme = strtolower($parts['scheme'] ?? '');
                if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
                    return null;
                }

                return $scheme.'://'.strtolower($parts['host']).':'.($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            };
            $targetOrigin = $origin($parts);
            $requestOrigin = $origin(parse_url($request->getSchemeAndHttpHost()) ?: []);
            $appOrigin = $origin(parse_url((string) config('app.url')) ?: []);
            if ($targetOrigin === null || ! in_array($targetOrigin, [$requestOrigin, $appOrigin], true)) {
                return null;
            }
        }

        // Keep stored APP_URL targets local when the same app is opened on a preview origin.
        $path = $parts['path'] ?? '/';

        return '/'.ltrim($path, '/').(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}

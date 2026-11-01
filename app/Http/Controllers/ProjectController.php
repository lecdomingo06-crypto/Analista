<?php

namespace App\Http\Controllers;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Support\ProjectAccess;
use App\Support\SensitiveFileAudit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProjectController extends Controller
{
    public const CATEGORIES = ['Web', 'Mobile', 'AI / ML', 'Database', 'Networking', 'Security', 'UI / UX', 'IoT / Hardware', 'Games', 'Education', 'Business / POS', 'Other'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');
        $projects = Project::query()->whereIn('status', ['approved', 'completed'])->where('is_public', true)
            ->with('owner:id,name,profile_photo_path')->withCount('reviewRequests')->withReviewProgress()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', '%'.$search.'%')
                        ->orWhere('summary', 'like', '%'.$search.'%')
                        ->orWhere('school', 'like', '%'.$search.'%');
                });
            })->when($category !== '', fn ($query) => $query->where('category', $category))
            ->latest()->paginate(12)->withQueryString();

        return view('projects.index', ['projects' => $projects, 'categories' => self::CATEGORIES, 'search' => $search, 'category' => $category]);
    }

    public function show(Request $request, Project $project)
    {
        $user = $request->user();
        $canManage = ProjectAccess::canManage($project, $user);
        $canEdit = ProjectAccess::canEdit($project, $user);
        $canViewPrivate = ProjectAccess::canViewPrivate($project, $user);
        $canViewDocuments = ProjectAccess::canViewThesisDocuments($project, $user);
        $canBrowseDocuments = ProjectAccess::canBrowseThesisDocuments($project, $user);
        $isProjectMember = ProjectAccess::isMember($project, $user);
        $isProjectWorkspace = $request->routeIs('account.projects.show');
        $isPublicListing = in_array($project->status, ['approved', 'completed'], true) && $project->is_public;
        if ($isProjectWorkspace) {
            abort_unless($canManage, 404);
        } else {
            abort_unless($isPublicListing || $canViewPrivate, 404);
            if ($user?->role === 'student') {
                if ($canManage && ! $isPublicListing) {
                    $request->session()->reflash();

                    return redirect()->route('account.projects.show', ['project' => $project, 'evaluator' => $request->query('evaluator')]);
                }
                $canViewPrivate = false;
                $canViewDocuments = false;
            }
        }
        $project->load('owner:id,name');
        $project->loadReviewProgress();
        $project->loadMissing('latestDocument');
        if ($canViewPrivate) {
            $project->load('members:id,name');
        }
        $documents = collect();
        $reviewRequests = collect();
        $evaluators = collect();
        if ($canViewDocuments) {
            $documents = $project->documents()->when(! $canManage && ! $canBrowseDocuments, function ($query) use ($project, $user) {
                $query->whereIn('id', $project->reviewRequests()->select('document_version_id')
                    ->where('evaluator_id', $user->id)->whereIn('status', ['accepted', 'completed']));
            })->orderByDesc('version')->get();
        }
        if ($canViewPrivate) {
            $reviewRequests = $project->reviewRequests()
                ->when(! $canManage, fn ($query) => $query->where('evaluator_id', $user->id)->whereIn('status', ['accepted', 'completed']))
                ->with(['evaluator:id,name', 'document', 'feedback.author:id,name',
                    'ratings' => fn ($query) => $query->where('user_id', $user->id)])->latest()->get();
        }
        if ($canViewPrivate && $canEdit && $project->status === 'approved') {
            $memberIds = ProjectAccess::members($project)->pluck('id');
            $evaluators = User::query()->where('role', 'evaluator')->where('evaluator_status', 'approved')
                ->where('is_suspended', false)->where('accepting_requests', true)
                ->whereNotIn('id', $memberIds)->orderBy('name')->get();
        }

        return view('projects.show', compact('project', 'canManage', 'canEdit', 'canViewPrivate', 'canViewDocuments', 'canBrowseDocuments', 'isProjectMember', 'isProjectWorkspace', 'documents', 'reviewRequests', 'evaluators'));
    }

    public function create(Request $request)
    {
        ProjectAccess::assertActive($request->user());
        abort_unless(in_array($request->user()->role, ['student', 'admin'], true), 403, 'Student accounts can create thesis projects.');

        $selectedEvaluator = User::query()->whereKey((int) $request->query('evaluator_id'))
            ->where('role', 'evaluator')->where('evaluator_status', 'approved')->where('is_suspended', false)->first();

        return view('projects.form', ['project' => new Project, 'categories' => self::CATEGORIES, 'memberEmails' => '',
            'selectedEvaluatorId' => $selectedEvaluator?->id]);
    }

    public function store(Request $request)
    {
        ProjectAccess::assertActive($request->user());
        abort_unless(in_array($request->user()->role, ['student', 'admin'], true), 403);
        $data = $this->validatedProject($request, true);
        $file = $request->file('document');
        $this->validatePdf($file);
        $memberIds = $this->memberIds($request, $request->user()->id);
        $storedPath = null;

        try {
            $project = DB::transaction(function () use ($request, $data, $file, $memberIds, &$storedPath) {
                $project = Project::create([...$data, 'user_id' => $request->user()->id,
                    'status' => $request->boolean('save_draft') ? 'draft' : 'approved']);
                $project->members()->sync($memberIds);
                $this->saveDocument($project, $file, $request->user()->id, $storedPath);

                return $project;
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }

        $this->notifyNewMembers($project, $memberIds, $request->user()->id);

        $message = $project->status === 'draft'
            ? 'Your thesis draft has been saved.' : ($project->is_public
                ? 'Your thesis is ready for review and listed in community projects.'
                : 'Your thesis is ready for review. Your project details remain private.');

        return $this->submissionResponse($request, $project, $message, 201);
    }

    public function edit(Request $request, Project $project)
    {
        abort_unless(ProjectAccess::canEdit($project, $request->user()), 403);
        ProjectAccess::assertActive($request->user());

        return view('projects.form', ['project' => $project, 'categories' => self::CATEGORIES,
            'memberEmails' => $project->members()->pluck('email')->implode(', ')]);
    }

    public function update(Request $request, Project $project)
    {
        abort_unless(ProjectAccess::canEdit($project, $request->user()), 403);
        ProjectAccess::assertActive($request->user());
        $data = $this->validatedProject($request, false);
        $oldMemberIds = $project->members()->pluck('users.id')->all();
        $memberIds = $request->has('member_emails') ? $this->memberIds($request, $project->user_id, $oldMemberIds) : $oldMemberIds;
        DB::transaction(function () use ($project, $request, $data, $memberIds) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            ProjectAccess::assertActive($actor);
            abort_unless(ProjectAccess::canEdit($project, $actor), 403);
            $currentMemberIds = $project->members()->pluck('users.id')->all();
            if (! $actor->isAdmin() && array_diff($currentMemberIds, $memberIds)) {
                throw ValidationException::withMessages(['member_emails' => 'Keep the existing group members. An administrator can help with member removal.']);
            }
            $project->fill($data);
            if ($project->usesVolunteerReviews() && $project->reviewer_limit < $project->occupiedReviewerCount()) {
                throw ValidationException::withMessages([
                    'reviewer_limit' => 'The reviewer limit cannot be lower than the number of evaluators already reviewing or finished.',
                ]);
            }
            $syncCompletion = $project->isDirty(['review_mode', 'reviewer_limit']);
            if (in_array($project->status, ['draft', 'pending', 'needs_changes', 'rejected'], true) && ! $request->boolean('save_draft')) {
                if (! $project->documents()->exists()) {
                    throw ValidationException::withMessages(['document' => 'Upload a thesis PDF before submitting your project.']);
                }
                $project->status = 'approved';
                $project->moderation_note = null;
                $syncCompletion = true;
            }
            $project->save();
            if ($syncCompletion) {
                $project->syncReviewCompletion();
            }
            $project->members()->sync($memberIds);
        });

        $project->refresh();
        $this->notifyNewMembers($project, array_diff($memberIds, $oldMemberIds), $request->user()->id);

        $message = $project->status === 'approved'
            ? ($project->is_public ? 'Your thesis is ready for review and listed in community projects.'
                : 'Your thesis is ready for review. Your project details remain private.')
            : 'Project details updated.';

        return $this->submissionResponse($request, $project, $message);
    }

    public function upload(Request $request, Project $project)
    {
        abort_unless(ProjectAccess::canEdit($project, $request->user()), 403);
        ProjectAccess::assertActive($request->user());
        $request->validate(['document' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        $file = $request->file('document');
        $this->validatePdf($file);
        $storedPath = null;
        try {
            $document = DB::transaction(function () use ($project, $file, $request, &$storedPath) {
                $project = Project::query()->lockForUpdate()->findOrFail($project->id);
                $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                ProjectAccess::assertActive($actor);
                abort_unless(ProjectAccess::canEdit($project, $actor), 403);

                return $this->saveDocument($project, $file, $request->user()->id, $storedPath);
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }

        return redirect()->route('account.projects.show', $project)->with('success', 'Version '.$document->version.' uploaded. Existing reviews remain attached to their original document version.');
    }

    public function download(Request $request, DocumentVersion $document)
    {
        ProjectAccess::assertActive($request->user());
        abort_unless(ProjectAccess::canDownload($document, $request->user()), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        $filename = preg_replace('/[^\pL\pN._ ()-]/u', '_', $document->original_name) ?: 'thesis.pdf';

        $response = Storage::disk('local')->download($document->path, $filename, [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);

        if ($request->isMethod('GET')) {
            SensitiveFileAudit::record($request->user(), 'thesis_pdf', (int) $document->id,
                projectId: (int) $document->project_id, version: (int) $document->version);
        }

        return $response;
    }

    public function destroy(Request $request, Project $project)
    {
        ProjectAccess::assertActive($request->user());
        abort_unless((int) $project->user_id === (int) $request->user()->id, 403);
        $request->validate([
            'delete_confirmation' => ['required', 'string', Rule::in(['DELETE'])],
        ], ['delete_confirmation.in' => 'Type DELETE to confirm permanent project removal.']);

        $projectId = $project->id;
        $documentPaths = DB::transaction(function () use ($project, $request) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $participantIds = $project->members()->pluck('users.id')->merge($project->reviewRequests()->pluck('evaluator_id'))->push($request->user()->id)->unique();
            $participants = User::query()->whereIn('id', $participantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $participants->get($request->user()->id);
            abort_unless($actor, 403);
            ProjectAccess::assertActive($actor);
            abort_unless((int) $project->user_id === (int) $actor->id, 403);

            $paths = $project->documents()->lockForUpdate()->pluck('path')->unique()->all();
            $outputs = $project->reviewRequests()->lockForUpdate()->get()->filter(fn ($review) => $review->output_path !== null)
                ->map(fn ($review) => ['review_id' => $review->id, 'path' => $review->output_path, 'safe_path' => $review->safeOutputPath()])->all();
            $recipientIds = $project->members()->pluck('users.id')
                ->merge($project->reviewRequests()->pluck('evaluator_id'))->unique()->all();
            $recipients = User::query()->whereIn('id', $recipientIds)->where('id', '!=', $actor->id)->get();
            $title = $project->title;

            // Foreign keys remove project-scoped records. Polymorphic reports remain as an audit trail.
            if (! $project->delete()) {
                throw ValidationException::withMessages(['project' => 'The project could not be removed. Please try again.']);
            }
            foreach ($recipients as $recipient) {
                $recipient->notifyActivity(
                    'Thesis project removed',
                    '“'.$title.'” was deleted by its owner. Its documents and review conversations are no longer available.',
                    route('account.projects'),
                    $actor
                );
            }

            return ['documents' => $paths, 'outputs' => $outputs];
        }, 3);

        // The DB removal is committed before attempting irreversible filesystem cleanup.
        // A failed file deletion cannot restore the project or its access records.
        $cleanupFailed = false;
        $prefix = 'theses/'.$projectId.'/';
        foreach ($documentPaths['documents'] as $documentPath) {
            $reason = null;
            try {
                $filename = is_string($documentPath) && str_starts_with($documentPath, $prefix)
                    ? substr($documentPath, strlen($prefix)) : '';
                if ($filename === '' || in_array($filename, ['.', '..'], true)
                    || str_contains($filename, '/') || str_contains($filename, '\\')
                    || str_contains($filename, ':') || str_contains($filename, "\0")) {
                    $reason = 'document_path_outside_expected_project_files';
                } elseif (DocumentVersion::query()->where('path', $documentPath)->exists()) {
                    $reason = 'another_document_still_references_file';
                } else {
                    $disk = Storage::disk('local');
                    if ($disk->exists($documentPath) && ! $disk->delete($documentPath)) {
                        $reason = 'storage_delete_returned_false';
                    }
                }
            } catch (Throwable $exception) {
                $reason = 'storage_cleanup_exception: '.get_class($exception);
            }
            if ($reason !== null) {
                $cleanupFailed = true;
                Log::warning('Project deleted but its document cleanup needs attention.', [
                    'project_id' => $projectId, 'path' => $documentPath, 'reason' => $reason,
                ]);
            }
        }

        foreach ($documentPaths['outputs'] as $output) {
            $reason = null;
            try {
                if (! $output['safe_path']) {
                    $reason = 'output_path_outside_expected_review_files';
                } elseif (ReviewRequest::query()->where('output_path', $output['safe_path'])->exists()
                    || DocumentVersion::query()->where('path', $output['safe_path'])->exists()) {
                    $reason = 'another_document_still_references_file';
                } else {
                    $disk = Storage::disk('local');
                    if ($disk->exists($output['safe_path']) && ! $disk->delete($output['safe_path'])) {
                        $reason = 'storage_delete_returned_false';
                    }
                }
            } catch (Throwable $exception) {
                $reason = 'output_cleanup_exception: '.get_class($exception);
            }
            if ($reason !== null) {
                $cleanupFailed = true;
                Log::warning('Project deleted but its review output cleanup needs attention.', [
                    'project_id' => $projectId, 'review_id' => $output['review_id'], 'path' => $output['path'], 'reason' => $reason,
                ]);
            }
        }

        if ($cleanupFailed) {
            return redirect()->route('account.projects')->with('error',
                'Your project and review records were deleted, but some stored PDFs could not be removed. '
                .'Those files are no longer accessible through Analista. An administrator must finish the file cleanup.');
        }

        return redirect()->route('account.projects')->with('success', 'Your project, its documents, and related review records have been permanently deleted.');
    }

    private function validatedProject(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'summary' => ['required', 'string', 'min:30', 'max:5000'],
            'school' => ['required', 'string', 'max:200'], 'course' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'technologies' => ['nullable'], 'feedback_needed' => ['required', 'string', 'max:3000'],
            'deadline' => ['nullable', 'date', ...($creating ? ['after_or_equal:today'] : [])], 'is_public' => ['sometimes', 'boolean'],
            'pdf_access' => ['sometimes', 'required', Rule::in([Project::PDF_ACCESS_ASSIGNED, Project::PDF_ACCESS_APPROVED_EVALUATORS])],
            'review_mode' => ['sometimes', 'required', Rule::in([Project::REVIEW_MODE_REQUEST_ONLY, Project::REVIEW_MODE_VOLUNTEER])],
            'reviewer_limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'document' => [$creating ? 'required' : 'prohibited', 'file', 'mimes:pdf', 'max:20480'],
            'save_draft' => ['sometimes', 'boolean'],
        ]);
        $technologies = $request->input('technologies') ?? [];
        if (is_string($technologies)) {
            $technologies = preg_split('/[,\r\n]+/', $technologies, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (! is_array($technologies) || count($technologies) > 20) {
            throw ValidationException::withMessages(['technologies' => 'Enter up to 20 technologies, separated by commas.']);
        }
        $clean = [];
        foreach ($technologies as $technology) {
            if (! is_string($technology) || mb_strlen($technology) > 100) {
                throw ValidationException::withMessages(['technologies' => 'Each technology must be a name of 100 characters or fewer.']);
            }
            if (trim($technology) !== '') {
                $clean[] = trim($technology);
            }
        }
        unset($data['document'], $data['save_draft']);
        $data['technologies'] = array_values(array_unique($clean));
        if ($creating || $request->has('is_public')) {
            $data['is_public'] = $request->boolean('is_public', $creating);
        }
        if ($creating && ! array_key_exists('pdf_access', $data)) {
            $data['pdf_access'] = Project::PDF_ACCESS_ASSIGNED;
        }
        if ($creating) {
            $data['review_mode'] ??= Project::REVIEW_MODE_REQUEST_ONLY;
            $data['reviewer_limit'] ??= 1;
        }

        return $data;
    }

    private function memberIds(Request $request, int $ownerId, array $existingIds = []): array
    {
        $emails = $request->input('member_emails') ?? [];
        if (is_string($emails)) {
            $emails = preg_split('/[,;\r\n]+/', $emails, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (! is_array($emails)) {
            throw ValidationException::withMessages(['member_emails' => 'Enter up to 15 registered student email addresses.']);
        }
        $normalized = [];
        foreach ($emails as $email) {
            if ($email === null) {
                continue;
            }
            if (! is_string($email)) {
                throw ValidationException::withMessages(['member_emails' => 'Each group member must have a valid registered email address. Correct or remove the optional group member emails.']);
            }
            $email = trim($email);
            if ($email !== '') {
                $normalized[mb_strtolower($email)] = $email;
            }
        }
        if (count($normalized) > 15) {
            throw ValidationException::withMessages(['member_emails' => 'Enter up to 15 registered student email addresses.']);
        }

        $ids = [];
        $errors = [];
        $existingIds = array_map('intval', $existingIds);
        foreach ($normalized as $email => $suppliedEmail) {
            $displayEmail = mb_substr($suppliedEmail, 0, 254);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'The group member email "'.$displayEmail.'" is not valid. Correct or remove it; group members are optional.';

                continue;
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user && (int) $user->id === $ownerId) {
                continue;
            }
            if (! $user || (! in_array((int) $user->id, $existingIds, true)
                && ($user->role !== 'student' || $user->is_suspended))) {
                $errors[] = 'No active registered student account was found for "'.$displayEmail.'". Correct or remove this optional group member email.';

                continue;
            }
            $ids[] = $user->id;
        }
        if ($errors) {
            throw ValidationException::withMessages(['member_emails' => $errors]);
        }

        return array_values(array_unique($ids));
    }

    private function submissionResponse(Request $request, Project $project, string $message, int $status = 200)
    {
        $destination = route('account.projects.show', $project);
        if ($project->status === 'approved' && ! $project->usesVolunteerReviews() && $request->filled('evaluator_id')) {
            $evaluator = User::find((int) $request->input('evaluator_id'));
            if ($evaluator?->isEvaluator() && ! ProjectAccess::hasConflict($project, $evaluator)) {
                $destination = route('account.projects.show', ['project' => $project, 'evaluator' => $evaluator->id]).'#request-review';
            }
        }
        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['redirect' => $destination, 'message' => $message], $status);
        }

        return redirect()->to($destination)->with('success', $message);
    }

    private function validatePdf(UploadedFile $file): void
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        $magic = $handle ? fread($handle, 5) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== '%PDF-') {
            throw ValidationException::withMessages(['document' => 'Upload a valid PDF document.']);
        }
    }

    private function saveDocument(Project $project, UploadedFile $file, int $userId, ?string &$storedPath): DocumentVersion
    {
        $storedPath = $file->store('theses/'.$project->id, 'local');
        if (! $storedPath) {
            throw ValidationException::withMessages(['document' => 'The PDF could not be saved. Please try again.']);
        }

        return $project->documents()->create(['user_id' => $userId, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $storedPath, 'mime_type' => 'application/pdf', 'size' => $file->getSize(),
            'version' => ((int) $project->documents()->max('version')) + 1]);
    }

    private function notifyNewMembers(Project $project, array $ids, int $actorId): void
    {
        $actor = User::find($actorId);
        User::query()->whereIn('id', $ids)->where('id', '!=', $actorId)->get()->each(
            fn (User $user) => $user->notifyActivity('Added to a thesis group', 'You were added to '.$project->title.'.', route('account.projects.show', $project), $actor)
        );
    }
}

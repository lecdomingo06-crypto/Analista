<?php

namespace App\Http\Controllers;

use App\Models\DocumentVersion;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Rating;
use App\Models\ReviewRequest as ThesisReviewRequest;
use App\Models\User;
use App\Services\ReviewDelivery;
use App\Support\ProjectAccess;
use App\Support\SensitiveFileAudit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        abort_unless(ProjectAccess::isMember($project, $actor), 403);
        $data = $request->validate([
            'evaluator_id' => ['required', 'integer', 'exists:users,id'],
            'document_version_id' => ['required', 'integer', 'exists:document_versions,id'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $review = DB::transaction(function () use ($actor, $project, $data) {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $participants = $this->lockParticipants($project, [$actor->id, $data['evaluator_id']]);
            $actor = $participants->get($actor->id);
            abort_unless($actor, 403);
            ProjectAccess::assertActive($actor);
            abort_unless(ProjectAccess::isMember($project, $actor), 403);
            if ($project->usesVolunteerReviews()) {
                throw ValidationException::withMessages(['project' => 'This project accepts volunteers. Switch to request-only reviews before inviting an evaluator.']);
            }
            if ($project->status === 'completed') {
                throw ValidationException::withMessages(['project' => 'This thesis project is completed and cannot receive new review requests. You can continue the conversation in Messages.']);
            }
            if ($project->status !== 'approved') {
                throw ValidationException::withMessages(['project' => 'Submit your thesis project before requesting a review. Hidden projects cannot receive review requests.']);
            }
            $evaluator = $participants->get($data['evaluator_id']);
            abort_unless($evaluator, 404);
            if (! $evaluator->isEvaluator() || ! $evaluator->accepting_requests || (int) $evaluator->capacity < 1) {
                throw ValidationException::withMessages(['evaluator_id' => 'This evaluator is not currently accepting thesis requests.']);
            }
            if (ProjectAccess::hasConflict($project, $evaluator)) {
                throw ValidationException::withMessages(['evaluator_id' => 'An evaluator cannot review a thesis they belong to or have contributed to.']);
            }
            if (! $project->documents()->whereKey($data['document_version_id'])->exists()) {
                throw ValidationException::withMessages(['document_version_id' => 'Select a PDF version belonging to this thesis.']);
            }
            if ($project->reviewRequests()->where('evaluator_id', $evaluator->id)->whereIn('status', ['pending', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['evaluator_id' => 'This evaluator already has an active request for your thesis.']);
            }
            if ($this->activeCount($evaluator) >= (int) $evaluator->capacity) {
                throw ValidationException::withMessages(['evaluator_id' => 'This evaluator has reached their review capacity. Please choose another evaluator.']);
            }

            $review = $project->reviewRequests()->create([...$data, 'requested_by' => $actor->id, 'status' => 'pending']);
            app(ReviewDelivery::class)->deliver($review, 'requested');

            return $review;
        });

        return redirect()->route('reviews.show', $review)->with('success', 'Review request sent. The evaluator will confirm their availability.');
    }

    public function volunteer(Request $request, Project $project)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        abort_unless($actor->isEvaluator(), 403);
        $data = $request->validate([
            'agreed_date' => ['required', 'date', 'after_or_equal:today'],
            'message' => ['nullable', 'string', 'max:3000'],
        ]);

        $review = DB::transaction(function () use ($project, $actor, $data) {
            // Uploads, membership changes, review transitions, and project settings
            // all take this project lock before participant locks. The last slot
            // and the current PDF are therefore resolved in the same transaction.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $participants = $this->lockParticipants($project, [$actor->id]);
            $actor = $participants->get($actor->id);
            abort_unless($actor && $actor->isEvaluator(), 403);
            ProjectAccess::assertActive($actor);
            abort_unless($project->is_public && in_array($project->status, ['approved', 'completed'], true), 404);
            abort_if(ProjectAccess::hasConflict($project, $actor), 403, 'You cannot review a thesis you belong to or have contributed to.');
            if (! $project->usesVolunteerReviews() || $project->status !== 'approved') {
                throw ValidationException::withMessages(['volunteer' => 'This thesis project is not currently accepting volunteer reviews.']);
            }
            if (! $actor->accepting_requests || (int) $actor->capacity < 1 || $this->activeCount($actor) >= (int) $actor->capacity) {
                throw ValidationException::withMessages(['volunteer' => 'You must be accepting reviews and have available review capacity to volunteer.']);
            }
            if ($project->reviewRequests()->where('evaluator_id', $actor->id)->whereIn('status', ['pending', 'accepted', 'completed'])->exists()) {
                throw ValidationException::withMessages(['volunteer' => 'You already have a review request for this thesis. Open that request to continue.']);
            }
            if ($project->occupiedReviewerCount() >= (int) $project->reviewer_limit) {
                throw ValidationException::withMessages(['volunteer' => 'All volunteer reviewer places for this thesis have been filled.']);
            }

            $document = $project->documents()->orderByDesc('version')->orderByDesc('id')->lockForUpdate()->first();
            if (! $document || ! $this->isAvailableThesisPdf($document)) {
                throw ValidationException::withMessages(['document' => 'The latest thesis PDF is unavailable. The thesis group must upload a valid PDF before you can volunteer.']);
            }
            $message = trim($data['message'] ?? '');
            $review = $project->reviewRequests()->create([
                'requested_by' => $project->user_id,
                'evaluator_id' => $actor->id,
                'document_version_id' => $document->id,
                'message' => $message !== '' ? $message : 'I am volunteering to review this thesis and provide recommendations to the thesis group.',
                'preferred_date' => $project->deadline,
                'agreed_date' => $data['agreed_date'],
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);
            app(ReviewDelivery::class)->deliver($review, 'volunteered');

            return $review;
        }, 3);

        return redirect()->route('reviews.show', $review)->with('success', 'You are now reviewing this thesis. The thesis group has been notified, and you can download the assigned PDF.');
    }

    public function show(Request $request, ThesisReviewRequest $review)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        $project = $review->project;
        $canManage = ProjectAccess::canManage($project, $actor);
        $isEvaluator = (int) $review->evaluator_id === (int) $actor->id;
        abort_unless($canManage || $isEvaluator, 403);
        $review->load('evaluator:id,name,profile_photo_path', 'requester:id,name,profile_photo_path');
        $project->load('owner:id,name');
        $canViewDocument = $canManage || ($isEvaluator && $actor->isEvaluator() && in_array($project->status, ['approved', 'completed'], true)
            && in_array($review->status, ['accepted', 'completed'], true));
        $feedback = $canViewDocument ? $review->feedback()->with('author:id,name')->oldest()->get() : collect();
        $review->setRelation('feedback', $feedback);
        $review->setRelation('document', $canViewDocument ? $review->document()->first() : null);
        $canRate = $actor->role === 'student' && ProjectAccess::isMember($project, $actor) && $review->status === 'completed';
        $canViewOutput = $canViewDocument && $review->status === 'completed' && $review->safeOutputPath() !== null;
        $rating = $canRate ? $review->ratings()->where('user_id', $actor->id)->first() : null;
        $reviewRequest = $review;

        return view('reviews.show', compact('review', 'reviewRequest', 'project', 'canManage', 'isEvaluator', 'canViewDocument', 'canViewOutput', 'feedback', 'canRate', 'rating'));
    }

    public function respond(Request $request, ThesisReviewRequest $review)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        abort_unless((int) $review->evaluator_id === (int) $actor->id, 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'decline'])],
            'agreed_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        DB::transaction(function () use ($review, $actor, $data) {
            $project = Project::query()->lockForUpdate()->findOrFail($review->project_id);
            $participants = $this->lockParticipants($project, [$actor->id, $review->requested_by, $review->evaluator_id]);
            $evaluator = $participants->get($actor->id);
            abort_unless($evaluator, 403);
            ProjectAccess::assertActive($evaluator);
            $locked = ThesisReviewRequest::query()->lockForUpdate()->findOrFail($review->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This request has already been answered.']);
            }
            if ($data['decision'] === 'accept') {
                if ($project->status === 'completed') {
                    throw ValidationException::withMessages(['decision' => 'This thesis project has already been completed. You can decline this pending request and continue the conversation in Messages.']);
                }
                if ($project->status !== 'approved' || ! $evaluator->isEvaluator() || ! $evaluator->accepting_requests) {
                    throw ValidationException::withMessages(['decision' => 'A submitted thesis and an available, approved evaluator are required to begin a review.']);
                }
                if (ProjectAccess::hasConflict($project, $evaluator)) {
                    throw ValidationException::withMessages(['decision' => 'You cannot review a thesis you belong to or have contributed to.']);
                }
                if ($this->activeCount($evaluator) >= (int) $evaluator->capacity) {
                    throw ValidationException::withMessages(['decision' => 'You have reached your active review capacity.']);
                }
                if ($project->usesVolunteerReviews()
                    && ! $project->reviewRequests()->where('evaluator_id', $evaluator->id)->whereIn('status', ['accepted', 'completed'])->exists()
                    && $project->occupiedReviewerCount() >= (int) $project->reviewer_limit) {
                    throw ValidationException::withMessages(['decision' => 'All volunteer reviewer places for this thesis have been filled.']);
                }
                $locked->update(['status' => 'accepted', 'accepted_at' => now(), 'agreed_date' => $data['agreed_date'] ?? null]);
            } else {
                $locked->update(['status' => 'declined']);
            }
            app(ReviewDelivery::class)->deliver($locked, $locked->status);
        });
        $review->refresh();

        return redirect()->route('reviews.show', $review)->with('success', $review->status === 'accepted'
            ? 'Request accepted. You can now download the submitted PDF and provide recommendations.' : 'Request declined. The thesis group has been notified.');
    }

    public function feedback(Request $request, ThesisReviewRequest $review)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        $isEvaluator = (int) $review->evaluator_id === (int) $actor->id;
        $isMember = ProjectAccess::isMember($review->project, $actor);
        abort_unless($isEvaluator || $isMember || $actor->isAdmin(), 403);
        $types = $isEvaluator ? ['strength', 'concern', 'recommendation', 'reply'] : ['question', 'reply'];
        $data = $request->validate([
            'section' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'type' => ['required', Rule::in($types)], 'body' => ['required', 'string', 'min:3', 'max:10000'],
            'priority' => ['nullable', Rule::in(['major', 'minor', 'suggestion'])],
        ]);
        if ($data['type'] === 'recommendation' && mb_strlen(trim($data['body'])) < 20) {
            throw ValidationException::withMessages(['body' => 'Please provide a specific recommendation of at least 20 characters.']);
        }
        DB::transaction(function () use ($review, $actor, $isEvaluator, $data) {
            Project::query()->whereKey($review->project_id)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $locked = ThesisReviewRequest::query()->lockForUpdate()->findOrFail($review->id);
            $this->assertOpenForFeedback($locked, $actor, $isEvaluator);
            $locked->feedback()->create([...$data, 'user_id' => $actor->id, 'priority' => $data['priority'] ?? 'suggestion', 'addressed' => false]);
        });
        if ($isEvaluator) {
            $this->notifyMembers($review, 'New thesis feedback', $actor->name.' added feedback for '.$review->project->title.'.', $actor);
        } else {
            $review->evaluator->notifyActivity('Thesis review conversation', $actor->name.' added a message about '.$review->project->title.'.', route('reviews.show', $review), $actor);
        }

        return redirect()->route('reviews.show', $review)->with('success', 'Your feedback has been added.');
    }

    public function complete(Request $request, ThesisReviewRequest $review)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        abort_unless((int) $review->evaluator_id === (int) $actor->id && $actor->isEvaluator(), 403);
        $request->merge(['final_summary' => is_string($request->input('final_summary')) ? trim($request->input('final_summary')) : $request->input('final_summary')]);
        $data = $request->validate([
            'final_summary' => ['required', 'string', 'min:30', 'max:10000'],
            'annotated_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ]);
        $file = $request->file('annotated_pdf');
        if ($file) {
            $this->validateOutputPdf($file);
        }
        $storedPath = null;
        try {
            DB::transaction(function () use ($review, $actor, $data, $file, &$storedPath) {
                $project = Project::query()->whereKey($review->project_id)->lockForUpdate()->firstOrFail();
                $participants = $this->lockParticipants($project, [$actor->id, $review->requested_by, $review->evaluator_id]);
                $actor = $participants->get($actor->id);
                abort_unless($actor, 403);
                $locked = ThesisReviewRequest::query()->lockForUpdate()->findOrFail($review->id);
                $locked->setRelation('project', $project);
                $this->assertOpenForFeedback($locked, $actor, true);
                if ($locked->status !== 'accepted') {
                    throw ValidationException::withMessages(['review' => 'Only an active accepted review can be completed. Completed summaries and attachments cannot be replaced.']);
                }
                $output = [];
                if ($file) {
                    $storedPath = $file->storeAs('review-outputs/'.$locked->id, Str::random(40).'.pdf', 'local');
                    if (! $storedPath) {
                        throw ValidationException::withMessages(['annotated_pdf' => 'The annotated PDF could not be saved. Please try again.']);
                    }
                    $output = ['output_path' => $storedPath, 'output_original_name' => $this->outputFilename($file->getClientOriginalName()), 'output_size' => $file->getSize()];
                }
                $locked->update(['status' => 'completed', 'completed_at' => now(), 'final_summary' => $data['final_summary'], ...$output]);
                $project->syncReviewCompletion();
                app(ReviewDelivery::class)->deliver($locked, 'completed');
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                try {
                    Storage::disk('local')->delete($storedPath);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }

        $projectCompleted = $review->project()->value('status') === 'completed';

        return redirect()->route('reviews.show', $review)->with('success', ($projectCompleted
            ? 'Review completed and thesis project marked completed. '
            : 'Your review is complete. This thesis project is still waiting for its other reviews. ')
            .'Your summary and optional annotated PDF have been sent to the thesis group in Messages and Notifications.');
    }

    public function downloadOutput(Request $request, ThesisReviewRequest $review)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        $project = $review->project;
        $canDownload = ProjectAccess::canManage($project, $actor)
            || ((int) $review->evaluator_id === (int) $actor->id && $actor->isEvaluator()
                && in_array($project->status, ['approved', 'completed'], true) && in_array($review->status, ['accepted', 'completed'], true));
        abort_unless($canDownload, 403);
        $path = $review->safeOutputPath();
        abort_unless($review->status === 'completed' && $path && Storage::disk('local')->exists($path), 404);

        $response = Storage::disk('local')->download($path, $this->outputFilename($review->output_original_name ?? 'review-summary.pdf'), [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($request->isMethod('GET')) {
            SensitiveFileAudit::record($actor, 'review_pdf', (int) $review->id, projectId: (int) $project->id);
        }

        return $response;
    }

    public function addressed(Request $request, Feedback $feedback)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        $review = $feedback->reviewRequest;
        abort_unless(ProjectAccess::isMember($review->project, $actor), 403);
        abort_unless(in_array($feedback->type, ['concern', 'recommendation'], true), 422, 'Only recommendations and concerns can be marked addressed.');
        $data = $request->validate(['addressed' => ['required', 'boolean']]);
        DB::transaction(function () use ($review, $actor, $feedback, $data) {
            $project = Project::query()->whereKey($review->project_id)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            ProjectAccess::assertActive($actor);
            abort_unless(ProjectAccess::isMember($project, $actor), 403);
            $feedback->update(['addressed' => (bool) $data['addressed']]);
        });

        return redirect()->route('reviews.show', $review)->with('success', 'Revision checklist updated.');
    }

    public function rate(Request $request, ThesisReviewRequest $review)
    {
        $actor = $request->user();
        ProjectAccess::assertActive($actor);
        abort_unless($actor->role === 'student' && ProjectAccess::isMember($review->project, $actor), 403);
        abort_unless($review->status === 'completed', 422, 'Ratings are available after the review is completed.');
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1500']]);
        DB::transaction(function () use ($review, $actor, $data) {
            $project = Project::query()->whereKey($review->project_id)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            ProjectAccess::assertActive($actor);
            abort_unless($actor->role === 'student' && ProjectAccess::isMember($project, $actor), 403);
            $review = ThesisReviewRequest::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            abort_unless($review->status === 'completed', 422);
            Rating::updateOrCreate(['review_request_id' => $review->id, 'user_id' => $actor->id],
                [...$data, 'evaluator_id' => $review->evaluator_id]);
        });

        return redirect()->to(route('reviews.show', $review).'#rating')->with('success', 'Your evaluator rating has been saved.');
    }

    private function activeCount(User $evaluator): int
    {
        return ThesisReviewRequest::query()->where('evaluator_id', $evaluator->id)->where('status', 'accepted')->count();
    }

    private function isAvailableThesisPdf(DocumentVersion $document): bool
    {
        $path = $document->path;
        if ($document->mime_type !== 'application/pdf' || (int) $document->size < 1 || ! is_string($path)
            || ! str_starts_with($path, 'theses/') || str_contains($path, '\\')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f]~', $path)) {
            return false;
        }
        $stream = null;
        try {
            if (! Storage::disk('local')->exists($path)) {
                return false;
            }
            $stream = Storage::disk('local')->readStream($path);

            return is_resource($stream) && fread($stream, 5) === '%PDF-';
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function assertOpenForFeedback(ThesisReviewRequest $review, User $actor, bool $isEvaluator): void
    {
        ProjectAccess::assertActive($actor);
        abort_unless(($isEvaluator && (int) $review->evaluator_id === (int) $actor->id)
            || ProjectAccess::isMember($review->project, $actor) || $actor->isAdmin(), 403);
        if (! in_array($review->status, ['accepted', 'completed'], true)) {
            throw ValidationException::withMessages(['review' => 'Feedback is available after an evaluator accepts the request.']);
        }
        if (! in_array($review->project->status, ['approved', 'completed'], true)) {
            throw ValidationException::withMessages(['review' => 'This review is paused because the thesis is not currently available for review.']);
        }
        if ($isEvaluator && (! $actor->isEvaluator() || ProjectAccess::hasConflict($review->project, $actor))) {
            abort(403, 'Only an approved independent evaluator can provide recommendations.');
        }
    }

    private function notifyMembers(ThesisReviewRequest $review, string $title, string $body, ?User $actor = null): void
    {
        ProjectAccess::members($review->project)->each(
            fn (User $user) => $user->notifyActivity($title, $body, route('reviews.show', $review), $actor)
        );
    }

    private function lockParticipants(Project $project, array $extraIds)
    {
        $ids = $project->members()->pluck('users.id')->push($project->user_id)->merge($extraIds)->unique()->sort()->values();

        return User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function validateOutputPdf(UploadedFile $file): void
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        $magic = $handle ? fread($handle, 5) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== '%PDF-') {
            throw ValidationException::withMessages(['annotated_pdf' => 'Upload a valid annotated PDF document.']);
        }
    }

    private function outputFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';

        return mb_substr($filename !== '' ? $filename : 'annotated-review.pdf', 0, 255);
    }
}

<?php

namespace App\Models;

use App\Services\ReviewDelivery;
use App\Support\ProjectAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DirectMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(DirectConversation::class, 'direct_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function reviewRequest(): BelongsTo
    {
        return $this->belongsTo(ReviewRequest::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DirectMessageAttachment::class)->orderBy('id');
    }

    public function previewText(): string
    {
        if (trim($this->body ?? '') !== '') {
            return $this->body;
        }
        $this->loadMissing('attachments');
        $count = $this->attachments->count();
        if ($count > 1) {
            return $count.' attachments';
        }
        $attachment = $this->attachments->first();

        return $attachment ? ($attachment->is_image ? 'Photo' : 'Attachment: '.$attachment->name) : '';
    }

    /** Resolve live access for both initial HTML and incremental message updates. */
    public function reviewCardFor(User $viewer): ?array
    {
        if ($this->kind !== 'review_event') {
            return null;
        }
        $unavailable = [
            'available' => false,
            'label' => 'Review update unavailable',
            'description' => 'This review is no longer available to your account.',
        ];
        if ($viewer->is_suspended || ! in_array((int) $viewer->id, [(int) $this->sender_id, (int) $this->recipient_id], true)) {
            return $unavailable;
        }
        $this->loadMissing('reviewRequest.project', 'reviewRequest.evaluator', 'reviewRequest.document');
        $review = $this->reviewRequest;
        $project = $review?->project;
        if (! $review || ! $project || ! in_array($project->status, ['approved', 'completed'], true) || ! $review->evaluator
            || ! $review->document || (int) $review->document->project_id !== (int) $project->id
            || ! in_array($this->review_event, ['requested', 'accepted', 'volunteered', 'declined', 'completed'], true)) {
            return $unavailable;
        }
        $member = ProjectAccess::isMember($project, $viewer);
        $assignedEvaluator = (int) $review->evaluator_id === (int) $viewer->id && $viewer->isEvaluator();
        if (! $member && ! $assignedEvaluator) {
            return $unavailable;
        }
        $copy = ReviewDelivery::eventCopy($this->review_event);
        $reviewUrl = route('reviews.show', $review);

        return [
            'available' => true,
            ...$copy,
            'event' => $this->review_event,
            'project_title' => $project->title,
            'evaluator_name' => $review->evaluator->name,
            'document_version' => (int) $review->document->version,
            'deadline' => $review->agreed_date?->format('M j, Y'),
            'status' => match ($review->status) {
                'pending' => 'Awaiting response', 'accepted' => 'In review',
                'completed' => 'Completed', 'declined' => 'Declined', default => 'Unavailable',
            },
            'review_url' => $reviewUrl.($this->review_event === 'completed' ? '#review-summary' : ''),
            // Pending evaluators can inspect the request, but not a private project page or PDF.
            'project_url' => $member || in_array($review->status, ['accepted', 'completed'], true)
                ? route($member ? 'account.projects.show' : 'projects.show', $project) : null,
            'rating_url' => $member && $viewer->role === 'student' && $review->status === 'completed'
                ? $reviewUrl.'#rating' : null,
        ];
    }
}

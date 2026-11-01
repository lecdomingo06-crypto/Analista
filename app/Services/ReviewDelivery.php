<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\ReviewRequest;
use App\Support\ProjectAccess;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class ReviewDelivery
{
    /**
     * Called inside the review transition transaction, after locking the project,
     * all participants by ascending user ID, and the review request, in that order.
     * Keeping delivery in that transaction prevents a status without its updates.
     */
    public function deliver(ReviewRequest $review, string $event): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Review updates must be delivered inside the review transaction.');
        }
        $copy = self::eventCopy($event);
        $review->loadMissing('project.owner', 'evaluator', 'requester');
        $sender = $event === 'requested' ? $review->requester : $review->evaluator;
        $recipients = $event === 'requested' ? collect([$review->evaluator]) : ProjectAccess::members($review->project);

        foreach ($recipients->filter()->unique('id')->sortBy('id') as $recipient) {
            if (! $sender || $sender->is($recipient)) {
                continue;
            }
            $key = 'review:'.$review->id.':'.$event.':'.$recipient->id;
            $thread = DirectConversation::query()
                ->where('lower_user_id', min($sender->id, $recipient->id))
                ->where('higher_user_id', max($sender->id, $recipient->id))
                ->lockForUpdate()->first();
            if (! $thread) {
                // The caller's participant locks serialize creation with normal chat sends.
                $thread = DirectConversation::create([
                    'lower_user_id' => min($sender->id, $recipient->id),
                    'higher_user_id' => max($sender->id, $recipient->id),
                ]);
            }
            $message = DirectMessage::firstOrCreate(['review_event_key' => $key], [
                'direct_conversation_id' => $thread->id,
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'kind' => 'review_event',
                'review_request_id' => $review->id,
                'review_event' => $event,
                // Private project details are resolved only when an authorized viewer opens a card.
                'body' => $copy['label'].'. '.$copy['description'],
            ]);
            if ($message->wasRecentlyCreated) {
                $thread->forceFill(['last_message_at' => $message->created_at])->save();
            }
            Activity::firstOrCreate(['review_event_key' => $key], [
                'user_id' => $recipient->id,
                'actor_id' => $sender->id,
                'title' => $copy['label'],
                'body' => $copy['description'],
                'url' => route('reviews.show', $review).($event === 'completed' ? '#review-summary' : ''),
            ]);
        }
    }

    public static function eventCopy(string $event): array
    {
        return match ($event) {
            'requested' => ['label' => 'New review request', 'description' => 'A thesis project is waiting for your response. View the request to accept or decline.'],
            'accepted' => ['label' => 'Review request accepted', 'description' => 'The evaluator accepted this request and has started reviewing the thesis project.'],
            'volunteered' => ['label' => 'Volunteer review started', 'description' => 'An evaluator volunteered and has started reviewing your thesis.'],
            'declined' => ['label' => 'Review request declined', 'description' => 'The evaluator is unable to take this request. Open the project for its current status, or continue the conversation in Messages.'],
            'completed' => ['label' => 'Review completed', 'description' => 'The evaluator has finished this review. Open the review to read the summary and any attached annotated PDF. You can continue the conversation in Messages.'],
            default => throw new InvalidArgumentException('Unsupported review event.'),
        };
    }
}

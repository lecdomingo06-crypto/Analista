@php($isOwn = (int) $message->sender_id === (int) $user->id)
@php($reviewCard = $message->reviewCardFor($user))
@php($sender = $message->sender)
<article class="chat-message {{ $isOwn ? 'is-own' : '' }}" data-message-id="{{ $message->id }}">
    <x-avatar :user="$sender" class="chat-message-avatar" role="img" :aria-label="$sender?->name ?? 'Account unavailable'" :data-avatar-initials="$sender?->initials() ?? '?'" />
    <div class="chat-message-content">
    @if($reviewCard)
        <div class="chat-review-card {{ $reviewCard['available'] ? '' : 'is-unavailable' }}" data-review-card>
            <p class="chat-review-label">{{ $reviewCard['label'] }}</p>
            @if($reviewCard['available'])
                <h3>{{ $reviewCard['project_title'] }}</h3>
            @endif
            <p class="chat-review-description">{{ $reviewCard['description'] }}</p>
            @if($reviewCard['available'])
                <dl class="chat-review-details">
                    <div><dt>Evaluator</dt><dd>{{ $reviewCard['evaluator_name'] }}</dd></div>
                    <div><dt>PDF version</dt><dd>{{ $reviewCard['document_version'] }}</dd></div>
                    <div><dt>Status</dt><dd>{{ $reviewCard['status'] }}</dd></div>
                    @if($reviewCard['deadline'])<div><dt>Agreed completion</dt><dd>{{ $reviewCard['deadline'] }}</dd></div>@endif
                </dl>
                <div class="chat-review-actions">
                    <a href="{{ $reviewCard['review_url'] }}">{{ $reviewCard['event'] === 'requested' ? 'View request' : 'View review' }} <span aria-hidden="true">↗</span></a>
                    @if($reviewCard['rating_url'])<a href="{{ $reviewCard['rating_url'] }}">Rate evaluator <span aria-hidden="true">☆</span></a>@endif
                    @if($reviewCard['project_url'])<a href="{{ $reviewCard['project_url'] }}">View project <span aria-hidden="true">↗</span></a>@endif
                </div>
            @endif
        </div>
    @else
        @if($message->body !== '')<p class="chat-bubble">{{ $message->body }}</p>@endif
        @if($message->attachments->isNotEmpty())
            <div class="chat-attachments" aria-label="Message attachments">
                @foreach($message->attachments as $attachment)
                    @include('messages.partials.attachment', ['attachment' => $attachment])
                @endforeach
            </div>
        @endif
    @endif
    <div class="chat-message-meta"><time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('M j, Y · g:i a') }}</time>@if($isOwn)<span data-message-receipt>{{ $message->read_at ? 'Seen' : 'Sent' }}</span>@endif</div>
    </div>
</article>

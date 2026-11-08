@props(['evaluator', 'featured' => false])
@php
    $cardExpertise = collect($evaluator->expertise ?? [])->filter(fn ($area) => is_string($area) && trim($area) !== '')
        ->map(fn ($area) => trim($area))->unique()->values();
    $cardHeadline = $evaluator->headline ?: 'Volunteer IT professional sharing thoughtful feedback on your thesis.';
@endphp
<article @class(['evaluator-card', 'home-evaluator-card' => $featured, 'reviewer-card' => !$featured]) @if($featured) data-evaluator-card data-evaluator-name="{{ $evaluator->name }}" @endif>
    <div @class(['evaluator-card-badges', 'home-evaluator-badges' => $featured]) @unless($featured) data-evaluator-topic-row @endunless>
        <x-evaluator-badge :evaluator="$evaluator"/>
        @foreach($featured ? $cardExpertise->take(2) : $cardExpertise as $area)
            <span @class(['evaluator-card-topic', 'home-evaluator-topic' => $featured]) @unless($featured) data-evaluator-topic hidden @endunless>{{ $area }}</span>
        @endforeach
        @unless($featured)
            <span class="evaluator-card-topic evaluator-card-topic-more" data-evaluator-topic-more @if($cardExpertise->isEmpty()) hidden @endif title="{{ $cardExpertise->implode(', ') }}">
                <span data-evaluator-topic-count aria-hidden="true">+{{ $cardExpertise->count() }}</span>
                <span class="sr-only" data-evaluator-topic-description>{{ $cardExpertise->count() }} more {{ Str::plural('area', $cardExpertise->count()) }} of expertise: {{ $cardExpertise->implode(', ') }}</span>
            </span>
        @endunless
    </div>
    <div @class(['evaluator-card-person', 'home-evaluator-person' => $featured])>
        <x-avatar :user="$evaluator"/>
        <div>
            <h3>@if($featured){{ $evaluator->name }}@else<a class="card-title" href="{{ route('evaluators.show', $evaluator) }}" title="{{ $evaluator->name }}">{{ $evaluator->name }}</a>@endif</h3>
            <span>{{ $evaluator->experience_years > 0 ? $evaluator->experience_years.' years of experience' : 'Volunteer evaluator' }}</span>
        </div>
    </div>
    <p @class(['evaluator-card-description', 'home-evaluator-description' => $featured]) @unless($featured) title="{{ $cardHeadline }}" @endunless>{{ $cardHeadline }}</p>
        <div @class(['evaluator-card-meta', 'home-evaluator-meta' => $featured])>
            <span class="availability {{ $evaluator->accepting_requests ? '' : 'muted' }}"><i aria-hidden="true"></i>{{ $evaluator->accepting_requests ? 'Available for reviews' : 'Currently unavailable' }}</span>
            @if($evaluator->ratings_received_count > 0)
                <span class="evaluator-card-rating is-rated"><x-icon name="star"/><span>{{ number_format($evaluator->ratings_received_avg_rating, 1) }}<span class="sr-only"> out of 5 stars</span> <span class="muted">({{ $evaluator->ratings_received_count }}<span class="sr-only"> {{ Str::plural('rating', $evaluator->ratings_received_count) }}</span>)</span></span></span>
            @else
                <span class="evaluator-card-rating"><x-icon name="star"/>No ratings yet</span>
            @endif
        </div>
    <div @class(['evaluator-card-actions', 'home-evaluator-actions' => $featured])>
        <a class="btn btn-primary btn-small" href="{{ route('evaluators.show', $evaluator) }}" aria-label="View {{ $evaluator->name }}'s profile">View profile <x-icon name="arrow-up-right"/></a>
        @if(!auth()->check() || (int) auth()->id() !== (int) $evaluator->id)
            <a class="btn btn-small evaluator-message" href="{{ route('messages.compose', $evaluator) }}" aria-label="Message {{ $evaluator->name }}"><x-icon name="message-circle"/>Message</a>
        @endif
    </div>
</article>

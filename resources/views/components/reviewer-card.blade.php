@props(['evaluator', 'featured' => false])
<article @class(['evaluator-card', 'home-evaluator-card' => $featured, 'reviewer-card' => !$featured]) @if($featured) data-evaluator-card data-evaluator-name="{{ $evaluator->name }}" @endif>
    <div @class(['evaluator-card-badges', 'home-evaluator-badges' => $featured])>
        <x-evaluator-badge :evaluator="$evaluator"/>
        @foreach(array_slice($evaluator->expertise ?? [], 0, $featured ? 2 : 3) as $area)
            <span @class(['evaluator-card-topic', 'home-evaluator-topic' => $featured])>{{ $area }}</span>
        @endforeach
    </div>
    <div @class(['evaluator-card-person', 'home-evaluator-person' => $featured])>
        <x-avatar :user="$evaluator"/>
        <div>
            <h3>@if($featured){{ $evaluator->name }}@else<a class="card-title" href="{{ route('evaluators.show', $evaluator) }}">{{ $evaluator->name }}</a>@endif</h3>
            <span>{{ $evaluator->experience_years > 0 ? $evaluator->experience_years.' years of experience' : 'Volunteer evaluator' }}</span>
        </div>
    </div>
    <p @class(['evaluator-card-description', 'home-evaluator-description' => $featured])>{{ $evaluator->headline ?: 'Volunteer IT professional sharing thoughtful feedback on your thesis.' }}</p>
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

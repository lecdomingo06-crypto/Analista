<div class="review-output-heading"><div><h3>Review summary</h3><p class="small muted">{{ $review->evaluator->name }}@if($review->completed_at) · Completed {{ $review->completed_at->format('M j, Y') }}@endif</p></div><span class="badge"><x-icon name="check-check"/> Completed</span></div>
@if($review->final_summary)
    <p class="review-text review-final-summary">{{ $review->final_summary }}</p>
@else
    <p class="small muted">This review was completed before written summaries were introduced. The evaluator’s original feedback is preserved below.</p>
    @forelse($review->feedback as $entry)
        <div class="list-item"><div>@if($entry->section || $entry->page)<p class="hint">{{ $entry->section }}{{ $entry->section && $entry->page ? ' · ' : '' }}{{ $entry->page ? 'Page '.$entry->page : '' }}</p>@endif<p class="review-text">{{ $entry->body }}</p></div></div>
    @empty
        <p class="small muted">No written feedback was recorded. You can contact the evaluator in Messages.</p>
    @endforelse
@endif
@if($review->output_path)
    <div class="review-document list-item"><div><strong>{{ $review->output_original_name ?: 'Annotated review.pdf' }}</strong><p class="hint">Evaluator’s annotated PDF @if($review->output_size) · {{ number_format($review->output_size / 1024 / 1024, 2) }} MB @endif</p></div><a class="btn btn-small" href="{{ route('reviews.output', $review) }}"><x-icon name="download"/> Download review PDF</a></div>
@endif
<p class="hint"><x-icon name="lock-keyhole"/> This summary and any annotated PDF are private review materials. They do not replace your submitted thesis version.</p>

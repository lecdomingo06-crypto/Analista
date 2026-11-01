@props(['evaluator'])
@if($evaluator->hasReviewedEvaluatorProfile())
    <span class="verified" title="Professional evidence and a practice review checked by an admin for community participation."><x-icon name="check"/>Admin-reviewed evaluator</span>
@else
    <span class="verified" title="Approved to participate in the Analista community."><x-icon name="check"/>Approved evaluator</span>
@endif

        <div class="review-detail-panel-heading"><h2>The request</h2><span>{{ $review->created_at->format('M j, Y') }}</span></div>
        <h3 class="review-detail-project-title">{{ $project->title }}</h3>
        <p class="review-detail-affiliation">{{ $project->school }} · {{ $project->course }}</p>
        <p class="review-text">{{ $project->summary }}</p>
        <x-tags :tags="$project->technologies ?? []"/>
        <div class="review-detail-section"><h3>What the group needs</h3><p class="review-text">{{ $review->message }}</p><p class="small muted">Requested by {{ $review->requester->name }}</p></div>
        @if($canViewDocument && $review->document)
            <div class="list-item review-document"><div><strong>{{ $review->document->original_name }}</strong><div class="small muted">PDF version {{ $review->document->version }} · {{ number_format($review->document->size / 1024 / 1024, 2) }} MB</div></div><a class="btn btn-small" href="{{ route('documents.download', $review->document) }}"><x-icon name="download"/> Download thesis PDF</a></div>
        @else
            <p class="hint"><x-icon name="lock-keyhole"/> @if(\App\Support\ProjectAccess::canBrowseThesisDocuments($project, auth()->user()))The team shares its thesis PDFs with approved evaluators. Open View project to access the PDF versions before accepting this request.@else The thesis PDF is available to the assigned evaluator after they accept this request.@endif</p>
        @endif

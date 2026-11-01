<header class="admin-section-heading"><h2>File access history</h2><p>Authorized requests for protected files. These entries record access being granted; they do not confirm that a file was fully received or read.</p></header>
@include('admin.partials.filters')
@if($fileAccesses->count())
    <section class="panel admin-history-list admin-file-access-list" aria-label="Protected file download requests">
        @foreach($fileAccesses as $entry)
            @php($projectTitle = in_array($entry->file_type, ['thesis_pdf', 'review_pdf'], true) ? $entry->project?->title : null)
            <article class="admin-history-entry admin-file-access-entry" data-access-id="{{ $entry->id }}">
                <div class="admin-history-heading"><h3>{{ $projectTitle ?: $entry->fileLabel() }}</h3><time class="hint" datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('M j, Y · g:i a') }}</time></div>
                <p class="small"><strong>{{ $entry->actor?->name ?? 'Former account' }}</strong> <span aria-hidden="true">·</span> {{ $entry->roleLabel() }} <span aria-hidden="true">·</span> Download requested</p>
                <p class="hint">@if($projectTitle){{ $entry->fileLabel() }} <span aria-hidden="true">·</span> @endif @if($entry->project_id)Project #{{ $entry->project_id }} <span aria-hidden="true">·</span> @endif @if($entry->verification_id)Application #{{ $entry->verification_id }} <span aria-hidden="true">·</span> @endif @if($entry->version)PDF version {{ $entry->version }} <span aria-hidden="true">·</span> @endif Entry #{{ $entry->id }}</p>
            </article>
        @endforeach
    </section>
@else
    <x-empty title="No matching file access entries." text="New authorized download requests will appear here. Try another search or reset your filters." />
@endif
<x-pagination :paginator="$fileAccesses" />

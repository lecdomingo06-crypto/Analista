<header class="admin-section-heading"><h2>File access history</h2></header>
@include('admin.partials.filters')
@if($fileAccesses->count())
    <section class="panel admin-history-list admin-file-access-list" aria-label="Protected file download requests">
        @foreach($fileAccesses as $entry)
            @php($context = $fileAccessContexts[$entry->id])
            <article class="admin-history-entry admin-file-access-entry" data-access-id="{{ $entry->id }}">
                <span class="admin-file-access-icon" aria-hidden="true"><x-icon :name="$context['icon']"/></span>
                <div class="admin-file-access-content">
                    <div class="admin-history-heading"><h3><strong>{{ $entry->actor?->name ?? 'Former account' }}</strong> {{ $context['action'] }}</h3><time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('M j, Y · g:i a') }}</time></div>
                    <div class="admin-file-access-meta"><span class="admin-file-access-type">{{ $context['category'] }}</span><span>{{ $entry->roleLabel() }}</span><span class="admin-file-access-action">Download requested</span></div>
                    <dl class="admin-file-access-details">
                        <div><dt>File</dt><dd>{{ $context['filename'] }}</dd></div>
                        <div><dt>Submitted by</dt><dd>{{ $context['submitter'] }}</dd></div>
                        @if($context['project_title'])<div class="admin-file-access-project"><dt>Project</dt><dd>{{ $context['project_title'] }}</dd></div>@endif
                    </dl>
                </div>
            </article>
        @endforeach
    </section>
@else
    <x-empty title="No matching file access entries." text="New authorized download requests will appear here. Try another search or reset your filters." />
@endif
<x-pagination :paginator="$fileAccesses" />

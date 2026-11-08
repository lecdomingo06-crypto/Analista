<header class="admin-section-heading"><h2>Share something useful</h2></header>
<details class="panel" @if(! old('_tutorial_form') || old('_tutorial_form') === 'new') open @endif>
    <summary class="mono">Add a video tutorial</summary>
    @include('admin.partials.tutorial-form', ['tutorial' => null])
</details>
<datalist id="tutorial-categories"><option value="Research writing"><option value="Literature review"><option value="Methodology"><option value="Citations"><option value="Data analysis"><option value="Thesis documentation"></datalist>
<header class="admin-section-heading"><h2>The video library</h2></header>
@include('admin.partials.filters')
@if($tutorials->count())
<div class="admin-video-grid" aria-label="Tutorial library">
@foreach($tutorials as $tutorial)
    <article class="panel admin-video-card" data-tutorial-id="{{ $tutorial->id }}" aria-labelledby="admin-video-title-{{ $tutorial->id }}">
        @if($tutorial->is_video)
            @if($tutorial->is_published)
                <a class="admin-video-thumbnail" href="{{ route('tutorials.show', $tutorial->slug) }}" aria-label="Watch {{ $tutorial->title }}"><img src="{{ $tutorial->thumbnail_url }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"><span class="admin-video-play" aria-hidden="true"><x-icon name="play" /></span></a>
            @else
                <div class="admin-video-thumbnail"><img src="{{ $tutorial->thumbnail_url }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"></div>
            @endif
        @else
            <div class="admin-video-thumbnail admin-video-thumbnail-fallback"><x-icon name="book" /><span>Reading guide</span></div>
        @endif
        <div class="admin-video-badges"><x-status :status="$tutorial->is_published ? 'published' : 'draft'" />@if($tutorial->featured)<span class="badge">Featured</span>@endif</div>
        <div class="admin-video-copy">
            <span class="admin-video-category">{{ $tutorial->category }}</span>
            <h3 class="admin-video-title" id="admin-video-title-{{ $tutorial->id }}" title="{{ $tutorial->title }}">{{ $tutorial->title }}</h3>
            @if(filled($tutorial->excerpt))<p class="admin-video-excerpt">{{ $tutorial->excerpt }}</p>@endif
            <p class="hint admin-video-creator">{{ $tutorial->is_video ? $tutorial->video_creator : 'Text guide · add a YouTube link to convert it to a video' }}</p>
        </div>
        @if($tutorial->is_published)<div class="admin-video-watch"><a class="btn btn-primary btn-small" href="{{ route('tutorials.show', $tutorial->slug) }}">{{ $tutorial->is_video ? 'Watch video' : 'View guide' }} <x-icon name="arrow-up-right" /></a></div>@endif
        <div class="admin-video-actions">
            <button class="btn btn-small" type="button" data-tutorial-edit="tutorial-editor-{{ $tutorial->id }}" aria-haspopup="dialog" aria-controls="tutorial-editor-{{ $tutorial->id }}"><x-icon name="edit" />{{ $tutorial->is_video ? 'Edit video' : 'Convert to video' }}</button>
            <form method="POST" action="{{ route('admin.tutorials.destroy', $tutorial) }}" data-confirm="Are you sure you want to delete this tutorial? It will be permanently removed from the learning library." data-confirm-title="Delete tutorial?" data-confirm-label="Delete tutorial" data-confirm-subject="{{ $tutorial->title }}">
                @csrf @method('DELETE')
                <button class="btn btn-danger btn-small" type="submit"><x-icon name="trash" />Delete tutorial</button>
            </form>
        </div>
        <dialog class="admin-tutorial-editor" id="tutorial-editor-{{ $tutorial->id }}" data-tutorial-editor aria-labelledby="tutorial-editor-title-{{ $tutorial->id }}" @if((string) old('_tutorial_form') === (string) $tutorial->id && $errors->any()) data-editor-reopen @endif>
            <header class="admin-tutorial-editor-heading">
                <div><h2 id="tutorial-editor-title-{{ $tutorial->id }}">{{ $tutorial->is_video ? 'Edit video' : 'Convert to video' }}</h2><p class="hint">{{ $tutorial->title }}</p></div>
                <button class="btn btn-small" type="button" data-tutorial-editor-close aria-label="Close video editor"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
            </header>
            <div class="admin-tutorial-editor-body">
                @if((string) old('_tutorial_form') === (string) $tutorial->id && $errors->any())
                    <div class="alert" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                @include('admin.partials.tutorial-form', ['tutorial' => $tutorial])
            </div>
        </dialog>
    </article>
@endforeach
</div>
@else
    <x-empty title="The library is ready for your first video." text="Add a useful YouTube tutorial to help students take their next step." />
@endif
<x-pagination :paginator="$tutorials" />
@push('scripts')
<script src="{{ asset('assets/admin-tutorials.js') }}?v={{ filemtime(public_path('assets/admin-tutorials.js')) }}" defer></script>
@endpush

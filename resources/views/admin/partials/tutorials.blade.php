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
        <details class="admin-video-edit" @if((string) old('_tutorial_form') === (string) $tutorial->id) open @endif>
            <summary><x-icon name="edit" />{{ $tutorial->is_video ? 'Edit video' : 'Convert to video' }}</summary>
            @include('admin.partials.tutorial-form', ['tutorial' => $tutorial])
        </details>
        <details class="admin-video-delete">
            <summary><x-icon name="trash" />Delete tutorial</summary>
            <form method="POST" action="{{ route('admin.tutorials.destroy', $tutorial) }}" class="stack">
                @csrf @method('DELETE')
                <p class="small">Permanently remove “{{ $tutorial->title }}” from the learning library?</p>
                <div><button class="btn btn-danger btn-small" type="submit">Delete permanently</button></div>
            </form>
        </details>
    </article>
@endforeach
</div>
@else
    <x-empty title="The library is ready for your first video." text="Add a useful YouTube tutorial to help students take their next step." />
@endif
<x-pagination :paginator="$tutorials" />

@extends('layouts.app')
@section('title', 'Tutorials & tips')
@section('body-class', 'tutorials-page tutorials-index-page')
@section('content')
@include('tutorials.partials.sky')
<section data-video-library>
    @if(in_array(auth()->user()?->role, ['student', 'evaluator'], true) && request()->boolean('saved'))
        @include('account.partials.workspace-menu', ['current' => 'bookmarks', 'library' => true])
    @endif
    <a class="learning-library-back page-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
    <header class="learning-header">
        <h1>Tutorials &amp; <em>tips</em></h1><p>A little guidance for every chapter. Learn from useful videos shared by the community.</p>
    </header>
    <div class="learning-library-tools">
        <div class="learning-library-links">
            @if(auth()->user()?->isEvaluator())<a href="{{ route('account.tutorials.create') }}"><x-icon name="plus"/> Create tutorial</a><a href="{{ route('account.tutorials.index') }}">My tutorials <x-icon name="arrow-up-right"/></a>@endif
            @if(request()->filled('q') || request()->filled('category') || request()->boolean('saved'))
                <a href="{{ route('tutorials.index') }}">All videos <x-icon name="arrow-up-right"/></a>
            @else
                @auth<a href="{{ route('tutorials.index', ['saved' => 1]) }}">Saved tutorials <x-icon name="arrow-up-right"/></a>@endauth
            @endif
        </div>
        <div class="learning-view" role="group" aria-label="Video layout" data-video-views hidden>
            <button type="button" data-video-view="list" aria-pressed="false" aria-label="List view" title="List view"><x-icon name="list"/></button>
            <button type="button" data-video-view="grid" aria-pressed="true" aria-label="Collage view" title="Collage view"><x-icon name="grid"/></button>
        </div>
    </div>
    <div class="learning-videos" data-video-items data-layout="grid">
        @forelse($tutorials as $tutorial)
            <a class="learning-video" href="{{ route('tutorials.show', $tutorial) }}">
                <div class="learning-thumbnail"><x-tutorial-thumbnail :tutorial="$tutorial"/><span class="learning-play" aria-hidden="true"><x-icon name="play"/></span></div>
                <div class="learning-video-copy"><span class="learning-topic">{{ $tutorial->category }}</span><h2>{{ $tutorial->title }}</h2><p>{{ $tutorial->excerpt }}</p><div class="learning-byline"><span>{{ $tutorial->video_creator }}</span><span class="learning-watch">Watch video <x-icon name="arrow-up-right"/></span></div></div>
            </a>
        @empty
            <x-empty title="No videos found." text="Try another topic, creator, or clear your filters."/>
        @endforelse
    </div>
    <x-pagination :paginator="$tutorials"/>
</section>
@endsection
@push('styles')<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('assets/tutorials.css') }}?v={{ filemtime(public_path('assets/tutorials.css')) }}">@endpush
@push('scripts')<script src="{{ asset('assets/tutorials.js') }}?v={{ filemtime(public_path('assets/tutorials.js')) }}" defer></script>@endpush

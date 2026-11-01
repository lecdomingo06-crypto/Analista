@extends('layouts.app')
@section('title', $tutorial->title)
@section('body-class', 'tutorials-page tutorial-detail-page')
@section('content')
@include('tutorials.partials.sky')
<a class="learning-back page-back" href="{{ route('tutorials.index') }}"><x-icon name="arrow-left"/> All tutorials & tips</a>
<header class="learning-header learning-detail-header">
    <h1>Tutorials &amp; <em>tips.</em></h1>
    @if(filled($tutorial->excerpt))<p class="learning-description">{{ $tutorial->excerpt }}</p>@endif
</header>
<article class="learning-article" aria-labelledby="tutorial-title">
    <header class="learning-article-header">
        <div class="learning-article-topics"><span class="learning-topic">{{ $tutorial->category }}</span><span class="learning-kind"><x-icon :name="$tutorial->is_video ? 'play' : 'book'"/>{{ $tutorial->is_video ? 'Video tutorial' : 'Reading guide' }}</span></div>
        <h2 id="tutorial-title">{{ $tutorial->title }}</h2>
        <div class="learning-toolbar">
            <span class="learning-creator">{{ $tutorial->is_video ? 'Video by '.$tutorial->video_creator : 'By '.($tutorial->author?->name ?? 'The Analista community') }}</span>
            @auth
                <form method="post" action="{{ route('account.bookmarks.toggle', $tutorial) }}">@csrf<button @class(['btn', 'btn-small', 'is-saved' => $saved]) type="submit" aria-pressed="{{ $saved ? 'true' : 'false' }}"><x-icon name="bookmark"/>{{ $saved ? 'Saved for later' : 'Save for later' }}</button></form>
            @else
                <a class="learning-save" href="{{ route('login') }}"><x-icon name="bookmark"/> Sign in to save</a>
            @endauth
        </div>
    </header>
    @if($tutorial->is_video)
        <div class="learning-player" data-video-player data-video-embed="{{ $tutorial->embed_url }}" data-video-title="{{ $tutorial->title }}">
            <button class="learning-poster" type="button" data-video-play aria-label="Play {{ $tutorial->title }}" hidden>
                <img src="{{ $tutorial->thumbnail_url }}" alt="" decoding="async" referrerpolicy="no-referrer">
                <span class="learning-player-play"><x-icon name="play"/><span>Play tutorial</span></span>
            </button>
            <noscript><a class="learning-no-script" href="{{ $tutorial->youtube_url }}" target="_blank" rel="noopener noreferrer">Watch this tutorial on YouTube ↗</a></noscript>
        </div>
        <div class="learning-source"><span><x-icon name="play"/> {{ $tutorial->video_creator }} on YouTube</span><a href="{{ $tutorial->youtube_url }}" target="_blank" rel="noopener noreferrer">Watch on YouTube <x-icon name="arrow-up-right"/></a></div>
    @endif
    @if(filled($tutorial->body))
        <div class="learning-notes">
            @if($tutorial->is_video)<h2>Put it into practice</h2>@endif
            @foreach(preg_split('/\R\s*\R/', trim($tutorial->body)) as $paragraph)
                @if(Str::startsWith(trim($paragraph), ['## ', '# ']))<h2>{{ preg_replace('/^#+\s*/', '', trim($paragraph)) }}</h2>
                @else<p class="preserve">{{ $paragraph }}</p>@endif
            @endforeach
        </div>
    @endif
    @if($tutorial->is_video)<p class="learning-credit">Video belongs to its original creator. Analista’s practice notes help you apply it to your own thesis.</p>@endif
</article>
@endsection
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/tutorials.css') }}?v={{ filemtime(public_path('assets/tutorials.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/tutorial-detail.css') }}?v={{ filemtime(public_path('assets/tutorial-detail.css')) }}">
@endpush
@push('scripts')<script src="{{ asset('assets/tutorials.js') }}?v={{ filemtime(public_path('assets/tutorials.js')) }}" defer></script>@endpush

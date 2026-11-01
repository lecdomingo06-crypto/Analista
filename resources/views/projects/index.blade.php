@extends('layouts.app')
@section('title', 'Community projects')
@section('body-class', 'projects-page')
@push('styles')
    <link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
@endpush
@section('content')
@include('projects.partials.sky')
<a class="projects-back page-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="projects-header">
    <h1>Ideas in <em>progress.</em></h1>
    <p>Explore student thesis projects looking for thoughtful, practical recommendations.</p>
</header>
<form method="get" action="{{ route('projects.index') }}" class="projects-filters" role="search" aria-label="Find thesis projects">
    <div class="projects-search-row">
        <label class="projects-search" for="search">
            <x-icon name="search"/><span class="sr-only">Search projects by title, school, or research idea</span>
            <input id="search" type="search" name="search" value="{{ $search }}" placeholder="A title, school, or research idea">
        </label>
        <button class="btn projects-search-button" type="submit">Search <x-icon name="arrow-right"/></button>
    </div>
    <div class="projects-category">
        <label class="sr-only" for="category">Area of focus</label>
        <select id="category" name="category"><option value="">All categories</option>@foreach($categories as $item)<option value="{{ $item }}" @selected($category === $item)>{{ $item }}</option>@endforeach</select>
    </div>
    @auth
        @if(in_array(auth()->user()->role, ['student', 'admin']))<a href="{{ route('projects.create') }}" class="btn projects-create-button"><x-icon name="plus"/>Submit a project</a>@endif
    @else
        <a href="{{ route('register') }}" class="btn projects-create-button">Share your project <x-icon name="arrow-up-right"/></a>
    @endauth
    @if($search || $category)<div class="projects-filter-footer"><a class="projects-reset" href="{{ route('projects.index') }}">Clear filters <span aria-hidden="true">↺</span></a></div>@endif
</form>
<section aria-labelledby="projects-results-title">
<div class="projects-results-heading"><h2 id="projects-results-title">{{ $projects->total() }} {{ Str::plural('project', $projects->total()) }} found</h2><span>Shared by the community.</span></div>
@if($projects->count())
    <div class="project-grid">@foreach($projects as $project)<x-project-card :project="$project"/>@endforeach</div>
    <x-pagination :paginator="$projects"/>
@else
    <x-empty title="No projects here yet." text="Try another search or submit a project with summary sharing enabled.">
        @auth<a class="btn btn-small" href="{{ route('account.projects') }}">View my projects <x-icon name="arrow-up-right"/></a><p class="hint">Your private projects and drafts are saved in My projects.</p>@endauth
    </x-empty>
@endif
</section>
@endsection

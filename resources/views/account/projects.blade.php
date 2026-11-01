@extends('layouts.app')
@section('title', 'My projects')
@section('body-class', 'projects-page my-projects-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/reviews.css') }}?v={{ filemtime(public_path('assets/reviews.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/my-projects.css') }}?v={{ filemtime(public_path('assets/my-projects.css')) }}">
@endpush
@section('content')
@include('projects.partials.sky')
<a class="projects-back page-back my-projects-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="my-projects-header">
    <h1>My <em>projects.</em></h1>
    <p>View your thesis projects, update their details, and manage your submissions.</p>
</header>
@if(in_array($user->role, ['student', 'evaluator'], true))
    @include('account.partials.workspace-menu', ['current' => 'projects'])
@else
<nav class="tabs my-projects-tabs" aria-label="Project sections"><a class="tab active" href="{{ route('account.projects') }}" aria-current="page">My projects</a>@if(in_array($user->role, ['student', 'evaluator'], true))<a class="tab" href="{{ route('account.reviews') }}">{{ $user->role === 'evaluator' ? 'My reviews' : 'Review requests' }}</a>@endif<a class="tab" href="{{ route('tutorials.index', ['saved' => 1]) }}">Saved tutorials</a></nav>
@endif
<form method="GET" action="{{ route('account.projects') }}" class="my-projects-filters" role="search" aria-label="Find your projects">
    <div class="my-projects-search-row">
        <div class="my-projects-search"><x-icon name="search"/><input type="search" name="q" value="{{ $search }}" maxlength="200" placeholder="Search your projects…" aria-label="Search your projects"></div>
        <button class="btn btn-primary my-projects-search-button" type="submit">Search <x-icon name="arrow-right"/></button>
    </div>
    <div class="my-projects-status"><select name="status" aria-label="Project status"><option value="">All statuses</option>@foreach($statuses as $value)<option value="{{ $value }}" @selected($status === $value)>{{ ucfirst(str_replace('_', ' ', $value)) }}</option>@endforeach</select></div>
    @if(in_array($user->role, ['student', 'admin'], true))<a class="btn btn-primary my-projects-create-button" href="{{ route('projects.create') }}"><x-icon name="plus"/>New project</a>@endif
    @if($search !== '' || $status !== '')<a class="text-button my-projects-filter-clear" href="{{ route('account.projects') }}">Clear filters</a>@endif
</form>
<div class="my-projects-results"><span>{{ $projects->total() }} {{ Str::plural('project', $projects->total()) }}</span><span>Your work, together.</span></div>
@if($projects->isNotEmpty())
    <div class="my-projects-list">
    @include('account.partials.project-list')
    </div>
    <x-pagination :paginator="$projects" />
@elseif($search !== '' || $status !== '')
    <x-empty title="No matching projects." text="Try a different title or school, or clear the status filter."><a class="btn" href="{{ route('account.projects') }}">View all my projects</a></x-empty>
@else
    <x-empty title="Your projects will appear here." text="Projects you create or join as a group member are kept together here.">
        @if(in_array($user->role, ['student', 'admin'], true))<a class="btn btn-primary" href="{{ route('projects.create') }}">Create your first project <x-icon name="arrow-right" /></a>@else<a class="btn" href="{{ route('account.reviews') }}">View my reviews <x-icon name="arrow-right" /></a>@endif
    </x-empty>
@endif
@endsection

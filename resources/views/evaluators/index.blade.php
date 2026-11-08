@extends('layouts.app')
@section('title', 'Find your evaluator')
@section('body-class', 'evaluators-page')
@section('content')
@include('evaluators.partials.sky')
<a class="evaluators-back page-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="evaluators-header">
    <h1>Find your <em>evaluator.</em></h1>
    <p class="evaluators-intro">Meet volunteer IT professionals who can help you make sense of your next draft.</p>
</header>

<form class="evaluators-filters" method="get" action="{{ route('evaluators.index') }}" role="search" aria-label="Find evaluators">
    <div class="evaluators-search-row">
        <label class="evaluators-search" for="evaluator-search">
            <x-icon name="search"/>
            <span class="sr-only">Search evaluators by name, role, or skill</span>
            <input id="evaluator-search" type="search" name="q" placeholder="Search a name, role, or skill…" value="{{ request('q') }}">
        </label>
        <button class="btn btn-primary" type="submit">Search <x-icon name="arrow-right"/></button>
    </div>
    <div class="evaluators-filter-options">
        <div>
            <label class="sr-only" for="evaluator-expertise">Expertise</label>
            <select id="evaluator-expertise" name="category">
                <option value="">All expertise</option>
                @foreach(\App\Http\Controllers\AccountController::EXPERTISE_OPTIONS as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="sr-only" for="evaluator-experience">Experience</label>
            <select id="evaluator-experience" name="experience">
                <option value="">Any experience</option>
                @foreach([1, 3, 5, 10] as $years)
                    <option value="{{ $years }}" @selected((string) request('experience') === (string) $years)>{{ $years }}+ years</option>
                @endforeach
            </select>
        </div>
    </div>
    @if(request()->hasAny(['q', 'category', 'experience', 'available', 'sort']))
        <div class="evaluators-filter-footer">
            <a class="evaluators-reset" href="{{ route('evaluators.index') }}">Reset filters <span aria-hidden="true">↺</span></a>
        </div>
    @endif
</form>

<section class="evaluators-results" aria-labelledby="evaluators-results-title">
    <div class="evaluators-results-heading">
        <h2 id="evaluators-results-title">{{ $evaluators->total() }} {{ Str::plural('evaluator', $evaluators->total()) }} found</h2>
        <span>Knowledge, shared freely.</span>
    </div>
    @if($evaluators->count())
        <div class="evaluators-grid reviewer-grid">
            @foreach($evaluators as $evaluator)
                <x-reviewer-card :evaluator="$evaluator"/>
            @endforeach
        </div>
        <x-pagination :paginator="$evaluators"/>
    @elseif($approvedCount === 0)
        <x-empty title="No evaluators have been approved yet" text="IT professionals apply with their own background and expertise. Their profiles appear here after an administrator reviews and approves their application.">
            @if(auth()->user()?->isAdmin())
                <a class="btn btn-primary" href="{{ route('admin.index', ['section' => 'evaluators']) }}">Review applications <x-icon name="arrow-right"/></a>
            @elseif(auth()->user()?->role === 'evaluator')
                <a class="btn btn-primary" href="{{ route('account.evaluator-verification.show') }}">Complete your application <x-icon name="arrow-right"/></a>
            @elseif(auth()->check())
                <a class="btn" href="{{ route('tutorials.index') }}">Explore tutorials <x-icon name="arrow-right"/></a>
            @else
                <a class="btn btn-primary" href="{{ route('register', ['role' => 'evaluator']) }}">Apply as an evaluator <x-icon name="arrow-right"/></a>
            @endif
        </x-empty>
    @else
        <x-empty title="No matching evaluators" text="Try another skill or clear your filters to explore the community.">
            <a class="btn" href="{{ route('evaluators.index') }}">View all evaluators</a>
        </x-empty>
    @endif
</section>
@endsection
@push('styles')<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('assets/evaluator-cards.css') }}?v={{ filemtime(public_path('assets/evaluator-cards.css')) }}">@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('assets/evaluators.css') }}?v={{ filemtime(public_path('assets/evaluators.css')) }}">@endpush
@push('scripts')<script src="{{ asset('assets/evaluator-card-topics.js') }}?v={{ filemtime(public_path('assets/evaluator-card-topics.js')) }}" defer></script>@endpush

@extends('layouts.app')
@section('title', 'My tutorials')
@section('body-class', 'tutorials-page tutorial-publishing-page')
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/tutorials.css') }}?v={{ filemtime(public_path('assets/tutorials.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/tutorial-publishing.css') }}?v={{ filemtime(public_path('assets/tutorial-publishing.css')) }}">
@endpush
@section('content')
@include('tutorials.partials.sky')
@include('account.partials.workspace-menu', ['current' => 'tutorials', 'library' => true])
<a class="learning-back page-back" href="{{ route('tutorials.index') }}"><x-icon name="arrow-left"/> All tutorials &amp; tips</a>
<header class="learning-header"><h1>My <em>tutorials.</em></h1><p>Share what you know. Manage your published videos and drafts.</p></header>
<div class="tutorial-owner-tools"><span>{{ $tutorials->total() }} {{ Str::plural('tutorial', $tutorials->total()) }}</span><a class="btn btn-primary" href="{{ route('account.tutorials.create') }}"><x-icon name="plus"/> Create tutorial</a></div>
<div class="tutorial-owner-grid">
    @forelse($tutorials as $tutorial)
        <article class="learning-video tutorial-owner-card">
            <a class="learning-thumbnail" href="{{ route('tutorials.show', $tutorial) }}" aria-label="Preview {{ $tutorial->title }}">
                @if($tutorial->thumbnail_url)<img src="{{ $tutorial->thumbnail_url }}" alt="" loading="lazy" referrerpolicy="no-referrer">@else<span class="tutorial-upload-thumbnail"><x-icon name="play"/><span>{{ $tutorial->is_video ? 'Video tutorial' : 'Reading guide' }}</span></span>@endif
            </a>
            <div class="tutorial-owner-badges"><x-status :status="$tutorial->is_published ? 'published' : 'draft'"/><span>{{ $tutorial->has_uploaded_video ? 'Uploaded video' : 'YouTube link' }}</span></div>
            <div class="learning-video-copy"><span class="learning-topic">{{ $tutorial->category }}</span><h2>{{ $tutorial->title }}</h2><p>{{ $tutorial->excerpt }}</p></div>
            <div class="tutorial-owner-actions"><a class="btn btn-primary" href="{{ route('tutorials.show', $tutorial) }}">{{ $tutorial->is_published ? 'Watch' : 'Preview' }} <x-icon name="play"/></a><a class="btn" href="{{ route('account.tutorials.edit', $tutorial) }}">Edit <x-icon name="edit"/></a><form method="POST" action="{{ route('account.tutorials.destroy', $tutorial) }}" data-confirm="Are you sure you want to delete this tutorial? Its video and learning notes will be removed." data-confirm-title="Delete tutorial?" data-confirm-label="Delete tutorial" data-confirm-subject="{{ $tutorial->title }}">@csrf @method('DELETE')<button class="btn btn-danger" type="submit" aria-label="Delete {{ $tutorial->title }}"><x-icon name="trash"/> Delete</button></form></div>
        </article>
    @empty
        <x-empty title="Your first tutorial starts here." text="Add a video link or upload your own video to share practical guidance with students."/>
    @endforelse
</div>
<x-pagination :paginator="$tutorials"/>
@endsection

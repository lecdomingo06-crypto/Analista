@extends('layouts.app')
@section('title', 'Inspect project')
@section('body-class', 'projects-page admin-page admin-project-inspection')
@section('validation-toast', 'true')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/admin.css') }}?v={{ filemtime(public_path('assets/admin.css')) }}">
@endpush
@push('scripts')
<script src="{{ asset('assets/admin-projects.js') }}?v={{ filemtime(public_path('assets/admin-projects.js')) }}" defer></script>
@endpush
@section('content')
@include('projects.partials.sky')
<div class="admin-layout">
    @include('admin.partials.section-nav', ['section' => 'projects', 'stats' => []])
    <div class="admin-main">
        <a class="projects-back page-back admin-back" href="{{ $backUrl }}"><x-icon name="arrow-left"/>Back to projects</a>
        <header class="projects-header admin-page-header"><h1>Inspect a <em>project.</em></h1></header>
        <div class="stack admin-content">
            <section class="panel stack" aria-labelledby="inspected-project-title">
                <div class="actions"><x-status :status="$project->status" />@if($project->category)<span class="badge">{{ $project->category }}</span>@endif<span class="badge">{{ $project->is_public ? 'Public summary requested' : 'Private project' }}</span></div>
                <h2 id="inspected-project-title">{{ $project->title }}</h2>
                <p class="small">{{ $project->owner->name }} &middot; {{ $project->school }} &middot; {{ $project->course }}</p>
                <p class="preserve">{{ $project->summary }}</p>
                <x-tags :tags="$project->technologies ?? []" />
                <p class="preserve"><strong>Requested feedback:</strong> {{ $project->feedback_needed }}</p>
                @if($project->moderation_note)<p class="preserve small"><strong>Previous admin note:</strong> {{ $project->moderation_note }}</p>@endif
            </section>
            <section class="panel stack" aria-labelledby="project-documents-title">
                <h2 id="project-documents-title">Thesis documents</h2>
                @forelse($project->documents as $document)
                    <div class="list-item"><div><strong>{{ $document->original_name }}</strong><p class="hint">Version {{ $document->version }} &middot; {{ number_format($document->size / 1024 / 1024, 2) }} MB</p></div><a class="btn btn-small" href="{{ route('documents.download', $document) }}">Download PDF <x-icon name="download" /></a></div>
                @empty
                    <p class="small">No thesis PDF has been uploaded.</p>
                @endforelse
            </section>
            <section class="panel stack" id="project-moderation" aria-labelledby="project-moderation-title">
                <h2 id="project-moderation-title">Project decision</h2>
                @include('admin.partials.project-moderation-form')
            </section>
        </div>
    </div>
</div>
@endsection

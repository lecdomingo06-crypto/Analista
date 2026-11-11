@extends('layouts.app')
@section('title', $tutorial ? 'Edit tutorial' : 'Create a tutorial')
@section('body-class', 'tutorials-page tutorial-publishing-page')
@section('validation-toast', 'true')
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/tutorials.css') }}?v={{ filemtime(public_path('assets/tutorials.css')) }}">
@endpush
@section('content')
@include('tutorials.partials.sky')
<a class="learning-back page-back" href="{{ route('account.tutorials.index') }}"><x-icon name="arrow-left"/> Back to my tutorials</a>
<header class="learning-header"><h1>{{ $tutorial ? 'Edit your' : 'Share a' }} <em>tutorial.</em></h1><p>Help students take their next step with a useful video.</p></header>
<section class="tutorial-editor-surface" aria-label="{{ $tutorial ? 'Edit tutorial' : 'New tutorial' }}">
    @include('admin.partials.tutorial-form', ['evaluatorMode' => true])
</section>
<datalist id="tutorial-categories"><option value="Research writing"><option value="Literature review"><option value="Methodology"><option value="Citations"><option value="Data analysis"><option value="Thesis documentation"></datalist>
@endsection

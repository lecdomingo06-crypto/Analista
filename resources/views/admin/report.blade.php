@extends('layouts.app')
@section('title', 'Report #'.$report->id.' — Community administration')
@section('body-class', 'projects-page admin-page admin-report-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/admin.css') }}?v={{ filemtime(public_path('assets/admin.css')) }}">
@endpush
@section('content')
@php($sameReportInput = (string) old('report_id') === (string) $report->id)
@include('projects.partials.sky')
<div class="admin-layout">
    @include('admin.partials.section-nav', ['section' => 'reports', 'stats' => []])
<div class="admin-main">
<a class="projects-back page-back admin-back" href="{{ $backUrl }}"><x-icon name="arrow-left" />Back to reports</a>
<header class="projects-header admin-page-header">
    <h1>Review a <em>report.</em></h1>
</header>
<div class="stack admin-report-content">
    <section class="panel stack admin-report-summary" aria-labelledby="report-heading">
        <div class="admin-report-heading"><h2 id="report-heading">Report #{{ $report->id }} · {{ ucfirst(str_replace('_', ' ', $report->reason)) }}</h2><x-status :status="$report->status" /></div>
        <div class="admin-report-meta">
            <div><p class="hint">{{ $report->reportable_type === 'project' ? 'Project / thesis' : 'Evaluator profile' }}</p><h3>{{ $subject?->title ?? $subject?->name ?? 'Content removed' }}</h3></div>
            @if($subject && $report->reportable_type === 'project')
                <a class="btn btn-small" href="{{ route('projects.show', $subject) }}">Inspect project <x-icon name="arrow-up-right" /></a>
            @elseif($subject && $report->reportable_type === 'user' && $subject->role === 'evaluator')
                <a class="btn btn-small" href="{{ route('admin.evaluators.show', $subject) }}">Inspect evaluator <x-icon name="arrow-up-right" /></a>
            @endif
        </div>
        <dl class="admin-report-facts">
            <div><dt>Reported by</dt><dd>{{ $report->reporter?->name ?? 'Deleted account' }}</dd></div>
            <div><dt>Reported</dt><dd><time datetime="{{ $report->created_at->toIso8601String() }}">{{ $report->created_at->format('M j, Y · g:i a') }}</time></dd></div>
            @if($report->resolved_at)<div><dt>Resolved</dt><dd><time datetime="{{ $report->resolved_at->toIso8601String() }}">{{ $report->resolved_at->format('M j, Y · g:i a') }}</time></dd></div>@endif
        </dl>
        <div class="stack"><h3>Report details</h3><p class="admin-report-description">{{ $report->details ?: 'No additional details were provided.' }}</p></div>
    </section>
    <section class="panel stack admin-report-decision" aria-labelledby="report-decision-heading">
        <h2 id="report-decision-heading">Report decision</h2>
        <form method="POST" action="{{ route('admin.reports.moderate', ['report' => $report, ...$listFilters]) }}" class="stack" data-confirm-report data-confirm-subject="{{ $subject?->title ?? $subject?->name ?? 'Reported content' }}">
            @csrf
            <input type="hidden" name="report_id" value="{{ $report->id }}">
            <div class="form-grid">
                <div class="field"><label for="report-status">Report status</label><select id="report-status" name="status" required>@foreach(['pending' => 'Pending', 'under_review' => 'Under review', 'resolved' => 'Resolved'] as $value => $label)<option value="{{ $value }}" @selected(($sameReportInput ? old('status') : $report->status) === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="field"><label for="report-action">Action when resolved</label><select id="report-action" name="action">@foreach(['dismiss' => 'Dismiss report', 'request_corrections' => 'Request corrections', 'hide_content' => 'Hide reported content', 'suspend_account' => 'Suspend responsible account'] as $value => $label)<option value="{{ $value }}" @selected(($sameReportInput ? old('action') : ($report->action ?? 'dismiss')) === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="field full"><label for="report-note">Admin decision note</label><textarea id="report-note" name="admin_note" rows="4" maxlength="2000" placeholder="Record what you checked and the reason for your decision." aria-describedby="report-note-help">{{ $sameReportInput ? old('admin_note') : $report->admin_note }}</textarea><span class="hint" id="report-note-help">A note is required for moderation actions. Corrections or hiding decisions share the relevant reason with the responsible user.</span></div>
            </div>
            <div class="actions"><button class="btn btn-primary" type="submit">Save report decision</button><a class="btn btn-ghost" href="{{ $backUrl }}">Back to reports</a></div>
        </form>
    </section>
</div>
</div>
</div>
@endsection

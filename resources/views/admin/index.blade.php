@extends('layouts.app')
@section('title', 'Community administration')
@section('body-class', 'projects-page admin-page')
@if($section === 'projects')
@section('validation-toast', 'true')
@push('scripts')
<script src="{{ asset('assets/admin-projects.js') }}?v={{ filemtime(public_path('assets/admin-projects.js')) }}" defer></script>
@endpush
@endif
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/admin.css') }}?v={{ filemtime(public_path('assets/admin.css')) }}">
@if($section === 'projects')<link rel="stylesheet" href="{{ asset('assets/admin-project-cards.css') }}?v={{ filemtime(public_path('assets/admin-project-cards.css')) }}">@endif
@if($section === 'reviews')<link rel="stylesheet" href="{{ asset('assets/admin-review-monitoring.css') }}?v={{ filemtime(public_path('assets/admin-review-monitoring.css')) }}">@endif
@if($section === 'file-access')<link rel="stylesheet" href="{{ asset('assets/admin-file-access.css') }}?v={{ filemtime(public_path('assets/admin-file-access.css')) }}">@endif
@endpush
@section('content')
@include('projects.partials.sky')
<div class="admin-layout">
    @include('admin.partials.section-nav', ['section' => $section, 'stats' => $stats])
<div class="admin-main">
<a class="projects-back page-back admin-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="projects-header admin-page-header">
    <h1>Keep good work <em>moving.</em></h1>
</header>
<div class="stack admin-content admin-section-{{ $section }}">
@if($section === 'overview')
    <div class="stats-grid admin-stats-grid">
        <a class="stat" href="{{ route('admin.index', ['section' => 'evaluators']) }}"><span class="admin-stat-icon"><x-icon name="users"/></span><span class="mono">{{ str_pad((string) $stats['pending_evaluators'], 2, '0', STR_PAD_LEFT) }}</span><span class="section-label">Evaluator applications</span></a>
        <a class="stat" href="{{ route('admin.index', ['section' => 'projects', 'status' => 'approved']) }}"><span class="admin-stat-icon"><x-icon name="folder"/></span><span class="mono">{{ str_pad((string) $stats['ready_projects'], 2, '0', STR_PAD_LEFT) }}</span><span class="section-label">Projects ready for review</span></a>
        <a class="stat" href="{{ route('admin.index', ['section' => 'reports']) }}"><span class="admin-stat-icon"><x-icon name="flag"/></span><span class="mono">{{ str_pad((string) $stats['pending_reports'], 2, '0', STR_PAD_LEFT) }}</span><span class="section-label">Open community reports</span></a>
    </div>
    <section class="panel admin-community-overview" aria-labelledby="admin-community-title">
        <div class="admin-community-heading"><h2 id="admin-community-title">The community at a glance</h2><a class="btn btn-primary" href="{{ route('admin.index', ['section' => 'invitations']) }}"><x-icon name="plus"/>Invite an evaluator</a></div>
        <dl class="admin-community-stats" aria-label="Community totals">
            <div class="admin-community-metric"><dt>Active {{ Str::plural('account', $stats['active_users']) }}</dt><dd>{{ $stats['active_users'] }}</dd></div>
            <div class="admin-community-metric"><dt>{{ Str::plural('Student', $stats['students']) }}</dt><dd>{{ $stats['students'] }}</dd></div>
            <div class="admin-community-metric"><dt>Approved, active {{ Str::plural('evaluator', $stats['evaluators']) }}</dt><dd>{{ $stats['evaluators'] }}</dd></div>
            <div class="admin-community-metric"><dt>Published {{ Str::plural('guide', $stats['tutorials']) }}</dt><dd>{{ $stats['tutorials'] }}</dd></div>
        </dl>
        @include('admin.partials.overview-charts')
        <p class="hint admin-community-note">Project totals include {{ $stats['drafts'] }} saved {{ Str::plural('draft', $stats['drafts']) }}. New submissions are automatically approved.</p>
    </section>
    <section class="panel admin-trends" aria-labelledby="admin-trends-title">
        <h2 id="admin-trends-title">Activity in the last 7 days</h2>
        <p class="hint">Compared with the preceding 7 days. New project counts include saved drafts.</p>
        <table class="admin-trends-table"><caption class="sr-only">Community activity counts for the last seven days and the previous seven days.</caption><thead><tr><th scope="col">Activity</th><th scope="col">Last 7 days</th><th scope="col">Previous 7 days</th></tr></thead><tbody>
            @foreach($trends as $trend)
                <tr><th scope="row">{{ $trend['label'] }}</th><td>{{ $trend['current'] }}</td><td>{{ $trend['previous'] }}</td></tr>
            @endforeach
        </tbody></table>
    </section>

@elseif($section === 'evaluators')
    <header class="admin-section-heading"><h2>Evaluator applications</h2></header>
    @include('admin.partials.filters')
    @if($evaluators->count())
        <div class="admin-evaluator-grid">
            @foreach($evaluators as $evaluator)
                <article class="panel admin-evaluator-card" id="evaluator-{{ $evaluator->id }}" aria-labelledby="evaluator-name-{{ $evaluator->id }}">
                    <div class="admin-evaluator-card-top">
                        <x-avatar :user="$evaluator" />
                        <div class="admin-evaluator-statuses"><x-status :status="$evaluator->evaluator_status" />@if($evaluator->is_suspended)<span class="badge">Suspended</span>@endif</div>
                    </div>
                    <div class="admin-evaluator-identity">
                        <h3 id="evaluator-name-{{ $evaluator->id }}">{{ $evaluator->name }}</h3>
                        <p class="admin-evaluator-headline">{{ $evaluator->headline ?: 'Volunteer evaluator' }}</p>
                        <p class="admin-evaluator-email">{{ $evaluator->email }}</p>
                    </div>
                    @if($evaluator->expertise)
                        <div class="admin-evaluator-expertise"><x-tags :tags="array_slice($evaluator->expertise, 0, 3)" /></div>
                    @endif
                    <a class="btn btn-primary admin-evaluator-application" href="{{ route('admin.evaluators.show', $evaluator) }}" aria-label="View {{ $evaluator->name }}'s application">View application <x-icon name="arrow-up-right" /></a>
                </article>
            @endforeach
        </div>
    @else
        <x-empty title="No matching evaluator applications." text="Try another search or reset your filters." />
    @endif
    <x-pagination :paginator="$evaluators" />

@elseif($section === 'projects')
    <header class="admin-section-heading"><h2>Project moderation</h2></header>
    @include('admin.partials.filters')
    @if($projects->count())
        <div class="admin-project-grid">
            @foreach($projects as $project)
                @include('admin.partials.project-card')
            @endforeach
        </div>
    @else
        <x-empty title="No matching projects." text="Try another search or reset your filters. Saved drafts are available through the status filter." />
    @endif
    <x-pagination :paginator="$projects" />

@elseif($section === 'reports')
    @include('admin.partials.reports')

@elseif($section === 'users')
    <header class="admin-section-heading"><h2>Community members</h2></header>
    @include('admin.partials.filters')
    <div class="table-wrap panel admin-users-panel"><table class="data-table" role="table"><thead><tr><th scope="col">Member</th><th scope="col">Role</th><th scope="col">Account status</th><th scope="col">Action</th></tr></thead><tbody>
    @forelse($users as $member)
        <tr>
            <td data-label="Member"><strong>{{ $member->name }}</strong><br><span class="hint">{{ $member->email }}</span></td>
            <td data-label="Role">{{ ucfirst($member->role) }}@if($member->role === 'evaluator')<br><span class="hint">{{ ucfirst($member->evaluator_status) }}</span>@endif</td>
            <td data-label="Account status"><x-status :status="$member->is_suspended ? 'suspended' : 'active'" /></td>
            <td data-label="Action">
                @if($member->role !== 'admin' && ! $member->is(auth()->user()))
                    <form method="POST" action="{{ route('admin.users.toggle', $member) }}" @unless($member->is_suspended) data-confirm="Are you sure you want to suspend this account? This member will be unable to sign in or use protected features until reinstated." data-confirm-title="Suspend account?" data-confirm-label="Suspend account" data-confirm-subject="{{ $member->name }}" @endunless>
                        @csrf<input type="hidden" name="action" value="{{ $member->is_suspended ? 'reinstate' : 'suspend' }}">
                        <button class="btn btn-small {{ $member->is_suspended ? '' : 'btn-danger' }}" type="submit">{{ $member->is_suspended ? 'Reinstate' : 'Suspend' }}</button>
                    </form>
                @else<span class="hint">Administrator</span>@endif
            </td>
        </tr>
    @empty<tr><td colspan="4">No community members yet.</td></tr>@endforelse
    </tbody></table></div>
    <x-pagination :paginator="$users" />

@elseif($section === 'invitations')
    <header class="admin-section-heading"><h2>Invite an evaluator</h2></header>
    <form method="POST" action="{{ route('admin.invitations.store') }}" class="panel stack">@csrf
        <div><h3>A small invitation. A meaningful contribution.</h3><p class="muted small">Create a private signup link for an IT professional. They will complete an application for admin approval.</p></div>
        <div class="field"><label for="invite-email">Professional’s email address</label><input id="invite-email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" placeholder="professional@example.com"></div>
        <div class="actions"><button class="btn btn-primary" type="submit">Create invitation <x-icon name="arrow-up-right" /></button><span class="hint">Links expire after 7 days.</span></div>
    </form>
    @if(session('invitation_url'))
        <div class="panel stack"><div class="section-label">YOUR PRIVATE INVITATION LINK</div><div class="field"><label for="invitation-url">Copy and share this link with the professional</label><input id="invitation-url" type="url" value="{{ session('invitation_url') }}" readonly onclick="this.select()"></div><p class="hint">This link has not been emailed automatically. Keep it private; a newer invitation for the same email replaces the previous link.</p></div>
    @endif
    <section class="panel admin-invitation-history"><h2>Invitation history</h2>
    @include('admin.partials.filters')
    @forelse($invitations as $invitation)
        <div class="list-item"><div><strong>{{ $invitation->email }}</strong><p class="hint">Created {{ $invitation->created_at->format('M j, Y') }} · Expires {{ $invitation->expires_at->format('M j, Y') }}</p></div><x-status :status="$invitation->accepted_at ? 'accepted' : ($invitation->expires_at->isPast() ? 'expired' : 'pending')" /></div>
    @empty<x-empty title="No matching invitations." text="Try another email address or reset your filters." />@endforelse
    </section>
    <x-pagination :paginator="$invitations" />

@elseif($section === 'tutorials')
    @include('admin.partials.tutorials')
@elseif($section === 'history')
    @include('admin.partials.history')
@elseif($section === 'reviews')
    @include('admin.partials.reviews')
@elseif($section === 'file-access')
    @include('admin.partials.file-access')
@endif
</div>
</div>
</div>
@endsection

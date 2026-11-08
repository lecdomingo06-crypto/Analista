<header class="admin-section-heading"><h2>Administration history</h2></header>
@include('admin.partials.filters')
@if($history->count())
    <section class="panel admin-action-panel" aria-label="Recorded administration actions">
        @php
            $targetTitle = static function (string $type, int $id) use ($historyTargets): string {
                $target = $historyTargets[$type][$id] ?? null;

                return match ($type) {
                    'evaluator' => $target?->name ?? 'Evaluator unavailable',
                    'user' => $target?->name ?? 'Account unavailable',
                    'project' => $target?->title ?? 'Project unavailable',
                    'tutorial' => $target?->title ?? 'Tutorial unavailable',
                    'invitation' => 'Private invitation',
                    'report' => 'Report unavailable',
                    default => 'Record unavailable',
                };
            };
        @endphp
        <ol class="admin-action-feed" role="list">
        @foreach($history as $entry)
            @php
                $isReportAction = $entry->target_type === 'report';
                $report = $isReportAction ? $historyReports->get($entry->target_id) : null;
                $subject = $report ? ($historySubjects[$report->reportable_type][$report->reportable_id] ?? null) : null;
                $recordTitle = $report
                    ? ($report->reportable_type === 'project' ? ($subject?->title ?? 'Project unavailable') : ($subject?->name ?? 'Evaluator unavailable'))
                    : $targetTitle($entry->target_type, $entry->target_id);
                $changes = $entry->changes();
                $entryIcon = match ($entry->target_type) {
                    'report' => 'flag', 'project' => 'folder', 'evaluator' => 'users',
                    'user' => 'user', 'tutorial' => 'play', 'invitation' => 'send', default => 'clock',
                };
                $entryTone = match ($entry->action) {
                    'project.hidden', 'project.rejected', 'evaluator.rejected', 'user.suspended', 'tutorial.deleted' => 'attention',
                    'project.approved', 'evaluator.approved', 'report.resolved', 'user.reinstated' => 'complete',
                    'project.needs_changes', 'evaluator.needs_info' => 'pending', default => 'neutral',
                };
            @endphp
            <li>
                <article class="admin-history-item" data-action-id="{{ $entry->id }}">
                    <details class="admin-history-details" id="history-action-details-{{ $entry->id }}">
                        <summary class="admin-action-summary">
                            <span class="admin-action-icon admin-action-icon-{{ $entryTone }}" aria-hidden="true"><x-icon :name="$entryIcon" /></span>
                            <span class="admin-action-copy">
                                <span class="admin-action-label">{{ $entry->actionLabel() }}</span>
                                <span class="admin-action-record">{{ $recordTitle }}</span>
                                <span class="admin-action-people"><span>By {{ $entry->actor?->name ?? 'Former administrator' }}</span>@if($report)<span>Reported by {{ $report->reporter?->name ?? 'Deleted account' }}</span>@endif</span>
                            </span>
                            <span class="admin-action-aside">
                                <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('M j, Y') }}<span>{{ $entry->created_at->format('g:i a') }}</span></time>
                                <span class="admin-action-toggle"><span class="admin-action-view-label">View details</span><span class="admin-action-close-label">Close details</span><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span>
                            </span>
                        </summary>
                        <div class="admin-action-detail">
                            @if($report)
                                <p class="admin-action-context">{{ $report->reportable_type === 'project' ? 'Project / thesis' : 'Evaluator profile' }} <span aria-hidden="true">·</span> {{ ucfirst(str_replace('_', ' ', $report->reason)) }}</p>
                            @elseif($isReportAction)
                                <p class="admin-action-context">This report is no longer available.</p>
                            @endif
                            @if($changes)
                                <dl class="admin-action-changes">
                                    @foreach($changes as $field => $change)
                                        <div><dt>{{ Str::headline($field) }}</dt><dd><span>{{ is_bool($change['before']) ? ($change['before'] ? 'Yes' : 'No') : ($change['before'] === null ? 'Not set' : Str::headline($change['before'])) }}</span> <span class="admin-action-change-arrow" aria-hidden="true">→</span><span class="sr-only"> changed to </span> <strong>{{ is_bool($change['after']) ? ($change['after'] ? 'Yes' : 'No') : ($change['after'] === null ? 'Not set' : Str::headline($change['after'])) }}</strong></dd></div>
                                    @endforeach
                                </dl>
                            @endif
                            @if(! empty($entry->metadata['changed_fields']))<p class="admin-action-context">Updated fields: {{ implode(', ', array_map(fn ($field) => Str::headline($field), $entry->metadata['changed_fields'])) }}.</p>@endif
                            @if(! empty($entry->metadata['affected_type']) && ! empty($entry->metadata['affected_id']))<p class="admin-action-context">Related {{ strtolower(\App\Models\AdminAction::TARGET_LABELS[$entry->metadata['affected_type']] ?? 'record') }}: {{ $targetTitle($entry->metadata['affected_type'], (int) $entry->metadata['affected_id']) }}</p>@endif
                            @if($entry->reason)<div class="admin-action-note"><strong>Decision note</strong><p>{{ $entry->reason }}</p></div>@endif
                            @if($report)<div><a class="btn btn-small" href="{{ route('admin.reports.show', $report) }}" aria-label="View report about {{ $recordTitle }}">View report <x-icon name="arrow-up-right" /></a></div>@endif
                        </div>
                    </details>
                </article>
            </li>
        @endforeach
        </ol>
    </section>
@else
    <x-empty title="No matching administration actions." text="New decisions will appear here. Try another search or reset your filters." />
@endif
<x-pagination :paginator="$history" />

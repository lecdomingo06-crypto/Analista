<header class="admin-section-heading"><h2>Administration history</h2><p>Who took action, what changed, and when. This history is view-only and restricted to active administrators.</p></header>
@include('admin.partials.filters')
@if($history->count())
    <section class="panel admin-history-table-panel" aria-label="Recorded administration actions">
        <table class="admin-history-table" role="table">
            <caption class="sr-only">Administration actions with linked reported content, reporter, administrator, date, and report details.</caption>
            <thead><tr><th scope="col">Record</th><th scope="col">Reported by</th><th scope="col">Admin action</th><th scope="col">Administrator</th><th scope="col">Action date</th><th scope="col">Report</th></tr></thead>
        @foreach($history as $entry)
            @php
                $isReportAction = $entry->target_type === 'report';
                $report = $isReportAction ? $historyReports->get($entry->target_id) : null;
                $subject = $report ? ($historySubjects[$report->reportable_type][$report->reportable_id] ?? null) : null;
                $recordTitle = $report
                    ? ($report->reportable_type === 'project' ? ($subject?->title ?? 'Project unavailable') : ($subject?->name ?? 'Evaluator unavailable'))
                    : $entry->targetLabel();
                $changes = $entry->changes();
            @endphp
            <tbody class="admin-history-group" data-action-id="{{ $entry->id }}">
                <tr class="admin-history-row">
                    <th scope="row" data-label="Record">
                        <span class="admin-history-record-title">{{ $recordTitle }}</span>
                        @if($report)
                            <p class="hint admin-history-record-meta">{{ $report->reportable_type === 'project' ? 'Project / thesis' : 'Evaluator profile' }} · Report #{{ $report->id }} · {{ ucfirst(str_replace('_', ' ', $report->reason)) }}</p>
                        @elseif($isReportAction)
                            <p class="hint admin-history-record-meta">This report is no longer available.</p>
                        @endif
                    </th>
                    <td data-label="Reported by">
                        @if($report){{ $report->reporter?->name ?? 'Deleted account' }}@elseif($isReportAction)<span class="hint">Unavailable</span>@else<span class="hint" aria-label="Not a report action">—</span>@endif
                    </td>
                    <td data-label="Admin action"><span class="admin-history-action-label">{{ $entry->actionLabel() }}</span><p class="hint">Action #{{ $entry->id }}</p></td>
                    <td data-label="Administrator">{{ $entry->actor?->name ?? 'Former administrator' }}</td>
                    <td data-label="Action date"><time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('M j, Y') }}<span class="hint">{{ $entry->created_at->format('g:i a') }}</span></time></td>
                    <td data-label="Report">@if($report)<a class="btn btn-primary btn-small" href="{{ route('admin.reports.show', $report) }}" aria-label="View report #{{ $report->id }}">View report <x-icon name="arrow-up-right" /></a>@else<span class="hint" aria-label="No linked report available">—</span>@endif</td>
                </tr>
                @if($changes || $entry->reason || ! empty($entry->metadata))
                <tr class="admin-history-detail-row"><td colspan="6">
                    <details class="admin-history-details" id="history-action-details-{{ $entry->id }}"><summary aria-label="View details for action #{{ $entry->id }}">View action details</summary><div class="admin-history-detail-content">
                @if($changes)
                    <dl class="admin-history-changes">
                        @foreach($changes as $field => $change)
                            <div><dt>{{ Str::headline($field) }}</dt><dd>{{ is_bool($change['before']) ? ($change['before'] ? 'Yes' : 'No') : ($change['before'] === null ? 'Not set' : Str::headline($change['before'])) }} <span aria-hidden="true">→</span><span class="sr-only"> changed to </span> {{ is_bool($change['after']) ? ($change['after'] ? 'Yes' : 'No') : ($change['after'] === null ? 'Not set' : Str::headline($change['after'])) }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                @if(! empty($entry->metadata['changed_fields']))<p class="hint">Updated fields: {{ implode(', ', array_map(fn ($field) => Str::headline($field), $entry->metadata['changed_fields'])) }}.</p>@endif
                @if(! empty($entry->metadata['verification_id']))<p class="hint">Application #{{ $entry->metadata['verification_id'] }}</p>@endif
                @if(! empty($entry->metadata['affected_type']) && ! empty($entry->metadata['affected_id']))<p class="hint">Related {{ strtolower(\App\Models\AdminAction::TARGET_LABELS[$entry->metadata['affected_type']] ?? 'record') }} #{{ $entry->metadata['affected_id'] }}</p>@endif
                @if($entry->reason)<div class="admin-history-decision-note"><strong>Decision note</strong><p>{{ $entry->reason }}</p></div>@endif
                    </div></details>
                </td></tr>
                @endif
            </tbody>
        @endforeach
        </table>
    </section>
@else
    <x-empty title="No matching administration actions." text="New decisions will appear here. Try another search or reset your filters." />
@endif
<x-pagination :paginator="$history" />

<header class="admin-section-heading"><h2>Community reports</h2><p>Open a report to inspect the content and record your decision. Reporter identities stay private to administrators.</p></header>
@include('admin.partials.filters')
@if($reports->count())
    <div class="panel admin-reports-list">
        <table class="admin-reports-table" role="table">
            <caption class="sr-only">Community reports with their reported content, status, submission date, and details link.</caption>
            <thead><tr><th scope="col">Report</th><th scope="col">Reported content</th><th scope="col">Status</th><th scope="col">Reported</th><th scope="col">Action</th></tr></thead>
            <tbody>
                @foreach($reports as $report)
                    @php
                        $subject = $reportSubjects[$report->reportable_type][$report->reportable_id] ?? null;
                        $subjectTitle = $subject?->title ?? $subject?->name ?? 'Content removed';
                        $reason = ucfirst(str_replace('_', ' ', $report->reason));
                    @endphp
                    <tr id="report-{{ $report->id }}" data-report-id="{{ $report->id }}">
                        <th scope="row" data-label="Report"><span class="admin-report-reference">Report #{{ $report->id }}</span><span class="admin-report-reason" title="{{ $reason }}">{{ $reason }}</span></th>
                        <td data-label="Reported content"><span class="admin-report-subject" title="{{ $subjectTitle }}">{{ $subjectTitle }}</span><span class="admin-report-kind">{{ $report->reportable_type === 'project' ? 'Project / thesis' : 'Evaluator profile' }}</span></td>
                        <td data-label="Status"><x-status :status="$report->status" /></td>
                        <td class="admin-report-date" data-label="Reported"><time datetime="{{ $report->created_at->toIso8601String() }}">{{ $report->created_at->format('M j, Y') }}<span class="hint">{{ $report->created_at->format('g:i a') }}</span></time></td>
                        <td data-label="Action"><a class="btn btn-primary btn-small" href="{{ route('admin.reports.show', ['report' => $report, 'q' => $filters['q'], 'status' => $filters['status'], 'reports_page' => $reports->currentPage()]) }}" aria-label="View report #{{ $report->id }}">View report <x-icon name="arrow-up-right" /></a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <x-empty title="No matching community reports." text="Try another search or choose All statuses to include resolved reports." />
@endif
<x-pagination :paginator="$reports" />

@php
    $projectChart = $overviewCharts['projects'];
    $reviewChart = $overviewCharts['reviews'];
@endphp
<div class="admin-community-charts" aria-label="Project and review status charts">
    <figure class="admin-community-chart admin-project-chart" aria-labelledby="admin-project-chart-title">
        <figcaption class="admin-chart-heading"><h3 id="admin-project-chart-title">Thesis projects</h3><p class="hint">{{ $projectChart['total'] }} total {{ Str::plural('project', $projectChart['total']) }}</p></figcaption>
        <ul class="admin-project-bars" aria-label="Projects by status">
            @foreach($projectChart['items'] as $item)
                @php($statusClass = in_array($item['status'], ['draft', 'approved', 'completed', 'pending', 'needs_changes', 'rejected', 'hidden'], true) ? $item['status'] : 'other')
                <li class="admin-chart-status-{{ $statusClass }}" data-project-status="{{ $item['status'] }}">
                    <div class="admin-chart-bar-label"><span>{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></div>
                    <div class="admin-chart-track" aria-hidden="true"><span class="admin-chart-fill" style="width:{{ number_format($item['percent'], 4, '.', '') }}%"></span></div>
                </li>
            @endforeach
        </ul>
        @if($projectChart['total'] === 0)<p class="hint admin-chart-empty">No projects yet.</p>@endif
    </figure>
    <figure class="admin-community-chart admin-review-chart" aria-labelledby="admin-review-chart-title">
        <figcaption class="admin-chart-heading"><h3 id="admin-review-chart-title">Review progress</h3><p class="hint">All review requests</p></figcaption>
        <div class="admin-review-breakdown">
            <div class="admin-review-donut">
                <svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
                    <circle class="admin-donut-track" cx="60" cy="60" r="46" />
                    <g>
                        @foreach($reviewChart['items'] as $item)
                            @if($item['count'] > 0)
                                @php($statusClass = in_array($item['status'], ['accepted', 'completed', 'pending', 'declined'], true) ? $item['status'] : 'other')
                                <circle class="admin-donut-segment admin-chart-status-{{ $statusClass }}" data-review-segment="{{ $item['status'] }}" cx="60" cy="60" r="46" pathLength="100" stroke-dasharray="{{ number_format($item['percent'], 4, '.', '') }} {{ number_format(100 - $item['percent'], 4, '.', '') }}" stroke-dashoffset="{{ number_format(-$item['offset'], 4, '.', '') }}"><title>{{ $item['label'] }}: {{ $item['count'] }} {{ Str::plural('request', $item['count']) }}</title></circle>
                            @endif
                        @endforeach
                    </g>
                </svg>
                <div class="admin-donut-total"><strong>{{ $reviewChart['total'] }}</strong><span>{{ Str::plural('request', $reviewChart['total']) }}</span></div>
            </div>
            <dl class="admin-chart-legend">
                @foreach($reviewChart['items'] as $item)
                    @php($statusClass = in_array($item['status'], ['accepted', 'completed', 'pending', 'declined'], true) ? $item['status'] : 'other')
                    <div class="admin-chart-status-{{ $statusClass }}" data-review-status="{{ $item['status'] }}"><dt><span class="admin-chart-swatch" aria-hidden="true"></span>{{ $item['label'] }}</dt><dd>{{ $item['count'] }}</dd></div>
                @endforeach
            </dl>
        </div>
        @if($reviewChart['total'] === 0)<p class="hint admin-chart-empty">No review requests yet.</p>@endif
    </figure>
</div>

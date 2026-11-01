<form class="admin-filters" method="GET" action="{{ route('admin.index') }}" role="search" aria-label="Filter administration records">
    <input type="hidden" name="section" value="{{ $section }}">
    <div class="admin-filter-field admin-filter-search">
        <label for="admin-search">Search</label>
        <div class="admin-search-input"><x-icon name="search"/><input id="admin-search" name="q" type="search" maxlength="200" value="{{ $filters['q'] }}" placeholder="{{ $searchPlaceholder }}"></div>
    </div>
    @foreach($filterSelects as $filter)
        <div class="admin-filter-field">
            <label for="admin-{{ $filter['name'] }}">{{ $filter['label'] }}</label>
            <select id="admin-{{ $filter['name'] }}" name="{{ $filter['name'] }}">
                @foreach($filter['options'] as $value => $label)<option value="{{ $value }}" @selected($filters[$filter['name']] === (string) $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
    @endforeach
    <div class="admin-filter-actions"><button class="btn btn-primary" type="submit">Apply filters <x-icon name="arrow-right"/></button><a class="btn btn-ghost" href="{{ route('admin.index', ['section' => $section]) }}">Reset</a></div>
</form>
<p class="admin-results-count">{{ $resultCount }} {{ Str::plural(match ($section) { 'evaluators' => 'evaluator', 'projects' => 'project', 'reviews' => 'review', 'reports' => 'report', 'users' => 'account', 'tutorials' => 'guide', 'invitations' => 'invitation', 'history' => 'action', 'file-access' => 'entry' }, $resultCount) }} found</p>

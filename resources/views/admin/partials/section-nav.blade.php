@php
    $adminSections = [
        'overview' => ['label' => 'Overview', 'icon' => 'grid'],
        'evaluators' => ['label' => 'Evaluators', 'icon' => 'users'],
        'projects' => ['label' => 'Projects', 'icon' => 'folder'],
        'reviews' => ['label' => 'Reviews', 'icon' => 'book'],
        'reports' => ['label' => 'Reports', 'icon' => 'flag'],
        'users' => ['label' => 'Users', 'icon' => 'user'],
        'tutorials' => ['label' => 'Tutorials', 'icon' => 'play'],
        'invitations' => ['label' => 'Invitations', 'icon' => 'plus'],
        'history' => ['label' => 'History', 'icon' => 'clock'],
        'file-access' => ['label' => 'File access', 'icon' => 'lock'],
    ];
    $currentSection = $adminSections[$section] ?? $adminSections['overview'];
@endphp
<details class="admin-navigation" data-admin-navigation open>
    <summary class="admin-menu-heading" aria-label="Administration menu, current section: {{ $currentSection['label'] }}"><span class="admin-menu-label">Menu</span><span class="admin-menu-current">{{ $currentSection['label'] }}</span><x-icon name="gear" /></summary>
    <nav class="tabs admin-section-nav" aria-label="Administration sections">
        @foreach($adminSections as $key => $item)
            @include('admin.partials.section-link', ['linkClass' => 'tab'])
        @endforeach
    </nav>
</details>
@once
@push('scripts')
<script src="{{ asset('assets/admin-navigation.js') }}?v={{ filemtime(public_path('assets/admin-navigation.js')) }}" defer></script>
@endpush
@endonce

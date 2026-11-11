@if(in_array(auth()->user()?->role, ['student', 'evaluator'], true))
    @php
        $workspaceRole = auth()->user()->role;
        $workspaceSections = [
            ($workspaceRole === 'evaluator' ? 'ratings' : 'projects') => $workspaceRole === 'evaluator'
                ? ['label' => 'My ratings', 'icon' => 'star', 'url' => route('account.ratings')]
                : ['label' => 'My projects', 'icon' => 'folder', 'url' => route('account.projects')],
            'reviews' => ['label' => $workspaceRole === 'evaluator' ? 'My reviews' : 'Review requests', 'icon' => 'inbox', 'url' => route('account.reviews')],
            'bookmarks' => ['label' => 'Saved tutorials', 'icon' => 'bookmark', 'url' => route('tutorials.index', ['saved' => 1])],
        ];
        if (auth()->user()->isEvaluator()) {
            $workspaceSections['tutorials'] = ['label' => 'My tutorials', 'icon' => 'play', 'url' => route('account.tutorials.index')];
        }
        $workspaceCurrent = $workspaceSections[$current] ?? $workspaceSections[$workspaceRole === 'evaluator' ? 'ratings' : 'projects'];
    @endphp
    <div class="workspace-menu-slot {{ ($library ?? false) ? 'workspace-menu-slot-library' : '' }}">
        <details class="workspace-menu" data-workspace-menu>
            <summary class="workspace-menu-heading" aria-label="{{ ucfirst($workspaceRole) }} workspace menu, current section: {{ $workspaceCurrent['label'] }}"><span class="workspace-menu-label">Menu</span><span class="workspace-menu-current">{{ $workspaceCurrent['label'] }}</span><x-icon name="gear" /></summary>
            <nav class="workspace-menu-links" aria-label="Your space sections">
                @foreach($workspaceSections as $key => $item)
                    <a class="workspace-menu-link" href="{{ $item['url'] }}" @if($current === $key) aria-current="page" @endif><x-icon :name="$item['icon']" /><span>{{ $item['label'] }}</span></a>
                @endforeach
            </nav>
        </details>
    </div>
    @once
        @push('styles')
            <link rel="stylesheet" href="{{ asset('assets/workspace-menu.css') }}?v={{ filemtime(public_path('assets/workspace-menu.css')) }}">
        @endpush
        @push('scripts')
            <script src="{{ asset('assets/workspace-menu.js') }}?v={{ filemtime(public_path('assets/workspace-menu.js')) }}" defer></script>
        @endpush
    @endonce
@endif

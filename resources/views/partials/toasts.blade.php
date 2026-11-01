<div class="toast-region" data-toast-region role="region" aria-label="Status updates">
    @foreach(['success', 'error', 'warning', 'info'] as $type)
        @if(session($type))
            <x-toast :type="$type" :message="session($type)"/>
        @endif
    @endforeach
</div>

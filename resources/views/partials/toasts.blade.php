<div class="toast-region" data-toast-region role="region" aria-label="Status updates">
    @foreach(['success', 'error', 'warning', 'info'] as $type)
        @if(session($type))
            <x-toast :type="$type" :message="session($type)"/>
        @endif
    @endforeach
    @hasSection('validation-toast')
        @if($errors->any())
            <x-toast type="error" :message="implode(PHP_EOL, $errors->all())"/>
        @endif
        <template data-validation-toast-template><x-toast type="error"/></template>
    @endif
</div>

@props(['type' => 'info', 'message' => ''])
@php
    $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
    $titles = ['success' => 'Success', 'error' => 'Please check', 'warning' => 'Please check', 'info' => 'Update'];
    $icons = ['success' => 'check', 'error' => 'flag', 'warning' => 'flag', 'info' => 'bell'];
@endphp
<div class="toast toast-{{ $type }}" data-toast data-toast-type="{{ $type }}" role="{{ $type === 'error' ? 'alert' : 'status' }}" aria-atomic="true">
    <span class="toast-icon" aria-hidden="true"><x-icon :name="$icons[$type]"/></span>
    <div class="toast-content">
        <div class="toast-meta">
            <span class="toast-brand" aria-hidden="true"><x-icon name="mark"/><span>analista<span class="toast-brand-dot">.</span></span></span>
            <strong class="toast-title">{{ $titles[$type] }}</strong>
        </div>
        <p class="toast-message">{{ $message }}</p>
    </div>
    <button class="toast-close" type="button" data-toast-close aria-label="Dismiss notification" hidden>
        <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg>
    </button>
</div>

@php($attachmentSize = $attachment->size >= 1048576 ? number_format($attachment->size / 1048576, 1).' MB' : max(1, (int) ceil($attachment->size / 1024)).' KB')
<div class="chat-attachment {{ $attachment->is_image ? 'is-image' : '' }}">
    @if($attachment->is_image)
        <a class="chat-attachment-image" href="{{ $attachment->url }}" target="_blank" rel="noopener" aria-label="Open image: {{ $attachment->name }}">
            <img src="{{ $attachment->url }}" alt="{{ $attachment->name }}" loading="lazy" decoding="async" width="280" height="180">
        </a>
    @endif
    <a class="chat-attachment-download" href="{{ $attachment->download_url }}" download aria-label="Download {{ $attachment->name }} ({{ $attachmentSize }})">
        <x-icon name="file"/>
        <span class="chat-attachment-copy"><strong>{{ $attachment->name }}</strong><span>{{ $attachmentSize }}</span></span>
        <x-icon name="download"/>
    </a>
</div>

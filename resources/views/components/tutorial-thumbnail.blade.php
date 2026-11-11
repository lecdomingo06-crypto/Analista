@props(['tutorial'])
@if($tutorial->thumbnail_url)
    <img src="{{ $tutorial->thumbnail_url }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
@else
    <svg viewBox="0 0 640 360" width="640" height="360" aria-hidden="true" style="display:block;width:100%;height:100%">
        <rect width="640" height="360" fill="var(--hover)"/>
        <circle cx="320" cy="180" r="64" fill="var(--bg)"/>
        <path d="M306 150v60l48-30z" fill="var(--text)"/>
    </svg>
@endif

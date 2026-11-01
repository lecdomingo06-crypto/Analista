@props(['user' => null])
<span {{ $attributes->class(['avatar']) }}>
    @if($user?->profile_photo_url)
        <img src="{{ $user->profile_photo_url }}" alt="" width="48" height="48" loading="lazy" decoding="async">
    @else
        {{ $user?->initials() ?? '?' }}
    @endif
</span>

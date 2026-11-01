{{-- The night scene is decorative; the saved theme preference controls its visibility. --}}
<div class="night-sky" aria-hidden="true">
    <div class="night-sky-galaxy"></div>
    <div class="night-sky-stars">
        {{-- Static SVG fields drift together, keeping the loop seamless without animating every star. --}}
        @for ($starField = 0; $starField < 2; $starField++)
            <svg class="night-sky-star-field" xmlns="http://www.w3.org/2000/svg" focusable="false">
                @for ($star = 0; $star < 58; $star++)
                    @if ($star % 13 === 0)
                        <svg class="night-sky-star night-sky-star-sparkle" x="{{ (($star * 47 + 13) % 97) + 1 }}%" y="{{ (($star * 31 + 7) % 97) + 1 }}%" width="6" height="6" viewBox="0 0 6 6" opacity="{{ $star % 4 === 0 ? '.72' : '.42' }}" focusable="false">
                            <path d="M3 0 3.72 2.28 6 3 3.72 3.72 3 6 2.28 3.72 0 3 2.28 2.28Z"/>
                        </svg>
                    @else
                        <circle class="night-sky-star" cx="{{ (($star * 47 + 13) % 97) + 1 }}%" cy="{{ (($star * 31 + 7) % 97) + 1 }}%" r="{{ $star % 5 === 0 ? '1.25' : ($star % 3 === 0 ? '1' : '.75') }}" opacity="{{ $star % 4 === 0 ? '.72' : '.42' }}"/>
                    @endif
                @endfor
            </svg>
        @endfor
    </div>
    <svg class="night-sky-planet night-sky-planet-ringed" viewBox="0 0 230 160" fill="none" focusable="false">
        <defs>
            <linearGradient id="night-planet-ringed" x1="86" y1="40" x2="149" y2="128" gradientUnits="userSpaceOnUse">
                <stop stop-color="#a59cdb"/>
                <stop offset=".5" stop-color="#68669e"/>
                <stop offset="1" stop-color="#383c6a"/>
            </linearGradient>
        </defs>
        <ellipse cx="115" cy="80" rx="102" ry="24" transform="rotate(-24 115 80)" stroke="#a7b8df" stroke-opacity=".55" stroke-width="7"/>
        <circle cx="115" cy="80" r="47" fill="url(#night-planet-ringed)"/>
        <path d="M75 56c19 6 48 3 68-9M69 76c22 7 59 3 83-10M74 97c23 6 55 0 79-10" stroke="#c2b7ee" stroke-opacity=".2" stroke-width="7"/>
        <path d="M24 104c-5 7-4 13 2 16 15 8 65-2 111-23 43-20 72-41 72-53" stroke="#a7b8df" stroke-opacity=".65" stroke-width="7" stroke-linecap="round"/>
    </svg>
    <svg class="night-sky-planet night-sky-planet-distant" viewBox="0 0 100 100" fill="none" focusable="false">
        <defs>
            <linearGradient id="night-planet-distant" x1="25" y1="20" x2="83" y2="86" gradientUnits="userSpaceOnUse">
                <stop stop-color="#729ac2"/>
                <stop offset=".55" stop-color="#3b5b85"/>
                <stop offset="1" stop-color="#213752"/>
            </linearGradient>
        </defs>
        <circle cx="50" cy="50" r="43" fill="url(#night-planet-distant)"/>
        <path d="M22 23c13 4 33 3 48-2M9 48c17 6 52 7 79-6M20 73c20 4 44 1 65-6" stroke="#b6d8ed" stroke-opacity=".17" stroke-width="8"/>
    </svg>
</div>

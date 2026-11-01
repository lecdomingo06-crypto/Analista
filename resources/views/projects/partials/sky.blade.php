<div class="projects-sky" aria-hidden="true">
    @foreach([
        [70, 150, 70, -10.5, .95, 63], [190, 90, 95, -52.3, .8, 12],
        [330, 220, 60, -49.2, .9, 84], [470, 70, 110, -33, .7, 43],
        [600, 120, 80, -54.4, .85, 4], [760, 180, 66, -3.3, .9, 72],
        [880, 60, 120, -108, .65, 28], [980, 100, 88, -37, .8, 90],
    ] as [$top, $width, $duration, $delay, $opacity, $restLeft])
        <svg class="projects-sky-cloud" viewBox="0 0 10 6" shape-rendering="crispEdges" focusable="false" style="--cloud-top:{{ $top }}px;--cloud-width:{{ $width }}px;--cloud-duration:{{ $duration }}s;--cloud-delay:{{ $delay }}s;--cloud-opacity:{{ $opacity }};--cloud-rest-left:{{ $restLeft }}%"><rect x="2" y="0" width="3" height="1"/><rect x="6" y="0" width="2" height="1"/><rect x="1" y="1" width="8" height="1"/><rect x="0" y="2" width="10" height="3"/><rect x="1" y="5" width="8" height="1"/></svg>
    @endforeach
</div>

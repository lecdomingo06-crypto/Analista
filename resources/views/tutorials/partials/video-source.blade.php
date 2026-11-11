@php($sourceType = $value('source_type', $tutorial?->has_uploaded_video ? 'upload' : 'youtube'))
<fieldset class="field full tutorial-source-picker">
    <legend>Video source</legend>
    <div class="tutorial-source-options">
        <label><input type="radio" name="source_type" value="youtube" @checked($sourceType === 'youtube')> <x-icon name="play"/> Video link</label>
        <label><input type="radio" name="source_type" value="upload" @checked($sourceType === 'upload')> <x-icon name="upload"/> Upload a video</label>
    </div>
</fieldset>
<div class="field full" data-tutorial-source="youtube">
    <label for="{{ $fieldId }}-url">YouTube video link</label>
    <input id="{{ $fieldId }}-url" name="youtube_url" type="url" maxlength="2048" value="{{ $value('youtube_url', $tutorial?->youtube_url ?? '') }}" placeholder="https://www.youtube.com/watch?v=...">
    <span class="hint">Paste a YouTube or youtu.be link. Credit the original creator below.</span>
</div>
<div class="field full" data-tutorial-source="upload">
    <label for="{{ $fieldId }}-file">Video file</label>
    <input id="{{ $fieldId }}-file" name="video" type="file" accept=".mp4,.webm,video/mp4,video/webm" data-existing-video="{{ $tutorial?->has_uploaded_video ? 'true' : 'false' }}">
    <span class="hint">MP4 or WebM, up to 30 MB. @if($tutorial?->has_uploaded_video)Leave this empty to keep your current video.@endif</span>
    @if($tutorial?->has_uploaded_video)<span class="tutorial-current-video"><x-icon name="file-text"/> Current video: {{ $tutorial->video_original_name }}</span>@endif
</div>
@once
    @push('styles')<link rel="stylesheet" href="{{ asset('assets/tutorial-publishing.css') }}?v={{ filemtime(public_path('assets/tutorial-publishing.css')) }}">@endpush
    @push('scripts')<script src="{{ asset('assets/tutorial-publishing.js') }}?v={{ filemtime(public_path('assets/tutorial-publishing.js')) }}" defer></script>@endpush
@endonce

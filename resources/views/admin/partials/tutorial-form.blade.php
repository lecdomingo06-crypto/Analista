@php
    $formKey = $tutorial?->id ?? 'new';
    $failedForm = (string) old('_tutorial_form') === (string) $formKey;
    $value = fn (string $field, mixed $fallback = '') => $failedForm ? old($field, $fallback) : $fallback;
    $fieldId = 'video-'.$formKey;
    $evaluatorMode = $evaluatorMode ?? false;
    $formAction = $evaluatorMode
        ? ($tutorial ? route('account.tutorials.update', $tutorial) : route('account.tutorials.store'))
        : ($tutorial ? route('admin.tutorials.update', $tutorial) : route('admin.tutorials.store'));
@endphp
<form method="POST" action="{{ $formAction }}" class="stack" enctype="multipart/form-data" data-tutorial-publishing-form>
    @csrf
    @if($tutorial) @method('PUT') @endif
    <input type="hidden" name="_tutorial_form" value="{{ $formKey }}">
    <div class="form-grid">
        <div class="field full"><label for="{{ $fieldId }}-title">Title</label><input id="{{ $fieldId }}-title" name="title" maxlength="180" required value="{{ $value('title', $tutorial?->title ?? '') }}" placeholder="e.g. How to write a research question"></div>
        @include('tutorials.partials.video-source')
        <div class="field"><label for="{{ $fieldId }}-creator">Video creator or channel</label><input id="{{ $fieldId }}-creator" name="video_creator" maxlength="120" required value="{{ $value('video_creator', $tutorial?->video_creator ?? ($evaluatorMode ? auth()->user()->name : '')) }}" placeholder="Creator's channel name"></div>
        <div class="field"><label for="{{ $fieldId }}-category">Category</label><input id="{{ $fieldId }}-category" name="category" maxlength="80" required value="{{ $value('category', $tutorial?->category ?? '') }}" placeholder="Research writing" list="tutorial-categories"></div>
        <div class="field full"><label for="{{ $fieldId }}-excerpt">Short description</label><textarea id="{{ $fieldId }}-excerpt" name="excerpt" rows="2" maxlength="500" required>{{ $value('excerpt', $tutorial?->excerpt ?? '') }}</textarea></div>
        <div class="field full"><label for="{{ $fieldId }}-body">Learning notes <span class="muted">(optional)</span></label><textarea id="{{ $fieldId }}-body" name="body" rows="6" maxlength="60000" placeholder="Add useful context or a short activity for students.">{{ $value('body', $tutorial?->body ?? '') }}</textarea><span class="hint">Use your own plain-text notes. Blank lines separate paragraphs.</span></div>
    </div>
    <div class="actions">
        <label class="small"><input type="checkbox" name="is_published" value="1" @checked($failedForm ? old('is_published', false) : ($tutorial?->is_published ?? false))> {{ $tutorial ? 'Published' : 'Publish now' }}</label>
        @unless($evaluatorMode)<label class="small"><input type="checkbox" name="featured" value="1" @checked($failedForm ? old('featured', false) : ($tutorial?->featured ?? false))> Feature this video</label>@endunless
    </div>
    <div class="actions"><button class="btn btn-primary {{ $tutorial ? 'btn-small' : '' }}" type="submit" data-tutorial-submit>{{ $tutorial ? 'Save video' : 'Add video' }} @unless($tutorial)<x-icon name="plus" />@endunless</button>@if($evaluatorMode)<a class="btn" href="{{ route('account.tutorials.index') }}">Cancel</a>@elseif($tutorial)<button class="btn btn-small" type="button" data-tutorial-editor-close>Cancel</button>@endif</div>
    <p class="hint" data-tutorial-upload-status role="status" hidden>Uploading your video. Keep this page open until it finishes.</p>
</form>

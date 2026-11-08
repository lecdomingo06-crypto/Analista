@php
    $canApproveProject = in_array($project->status, ['pending', 'needs_changes', 'rejected', 'hidden'], true);
    $sameProjectInput = (string) old('moderation_project_id') === (string) $project->id;
    $projectReasonError = $sameProjectInput ? $errors->first('reason') : null;
    $projectReasonValue = $sameProjectInput && is_string(old('reason')) ? old('reason') : '';
@endphp
<form method="POST" action="{{ route('admin.projects.moderate', $project) }}" class="stack" data-project-moderation>@csrf
    <input type="hidden" name="moderation_project_id" value="{{ $project->id }}">
    <div class="field">
        <label for="project-reason-{{ $project->id }}">Decision note</label>
        <textarea id="project-reason-{{ $project->id }}" name="reason" rows="2" maxlength="2000" required data-required-message="Add a decision note before requesting changes, rejecting, or hiding a project." aria-describedby="project-reason-help-{{ $project->id }}{{ $projectReasonError ? ' project-reason-error-'.$project->id : '' }}" @if($projectReasonError) aria-invalid="true" @endif placeholder="Explain what needs to change or why the project is being hidden.">{{ $projectReasonValue }}</textarea>
        <span class="hint" id="project-reason-help-{{ $project->id }}">Required to request changes, reject, or hide. Shared with the student and project members.@if($canApproveProject) Optional when {{ $project->status === 'pending' ? 'approving' : 'restoring' }}.@endif</span>
        @if($projectReasonError)<span class="sr-only" id="project-reason-error-{{ $project->id }}">{{ $projectReasonError }}</span>@endif
    </div>
    <div class="actions">
        @if($canApproveProject)<button class="btn btn-primary btn-small" name="decision" value="approve" type="submit" formnovalidate @disabled(! $project->documents_count)>{{ $project->status === 'pending' ? 'Approve project' : 'Restore project' }}</button>@endif
        <button class="btn btn-small" name="decision" value="needs_changes" type="submit">Request changes</button><button class="btn btn-small btn-danger" name="decision" value="reject" type="submit">Reject</button><button class="btn btn-small btn-ghost" name="decision" value="hide" type="submit" data-confirm="Are you sure you want to hide this project? It will be removed from community listings, and its members will receive your decision note." data-confirm-title="Hide project?" data-confirm-label="Hide project" data-confirm-subject="{{ $project->title }}">Hide project</button>
    </div>
    <span class="hint">Hidden projects stay in this admin list but are removed from community listings until an admin restores them.</span>
    @if($canApproveProject && ! $project->documents_count)<span class="hint">A thesis PDF is required before this project can be {{ $project->status === 'pending' ? 'approved' : 'restored' }}.</span>@endif
</form>

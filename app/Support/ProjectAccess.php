<?php

namespace App\Support;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Support\Collection;

final class ProjectAccess
{
    public static function isMember(Project $project, ?User $user): bool
    {
        return $user !== null && ! $user->is_suspended && ((int) $project->user_id === (int) $user->id
            || $project->members()->where('users.id', $user->id)->exists());
    }

    public static function canManage(Project $project, ?User $user): bool
    {
        return $user !== null && ! $user->is_suspended && ($user->isAdmin() || self::isMember($project, $user));
    }

    public static function canEdit(Project $project, ?User $user): bool
    {
        // Administrative oversight does not grant authorship of another team's project.
        return self::isMember($project, $user);
    }

    public static function hasConflict(Project $project, User $user): bool
    {
        return (int) $project->user_id === (int) $user->id
            || $project->members()->where('users.id', $user->id)->exists()
            || $project->documents()->where('user_id', $user->id)->exists()
            || $project->reviewRequests()->where('requested_by', $user->id)->exists()
            || $project->reviewRequests()->where('evaluator_id', '!=', $user->id)
                ->whereHas('feedback', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    public static function hasAcceptedReview(Project $project, ?User $user): bool
    {
        return in_array($project->status, ['approved', 'completed'], true) && $user?->isEvaluator() && $project->reviewRequests()
            ->where('evaluator_id', $user->id)
            ->whereIn('status', ['accepted', 'completed'])->exists();
    }

    public static function canViewPrivate(Project $project, ?User $user): bool
    {
        return self::canManage($project, $user) || self::hasAcceptedReview($project, $user);
    }

    public static function canBrowseThesisDocuments(Project $project, ?User $user): bool
    {
        return $user !== null && ! $user->is_suspended && $user->isEvaluator()
            && in_array($project->status, ['approved', 'completed'], true)
            && $project->is_public && $project->pdf_access === Project::PDF_ACCESS_APPROVED_EVALUATORS;
    }

    public static function canViewThesisDocuments(Project $project, ?User $user): bool
    {
        return self::canViewPrivate($project, $user) || self::canBrowseThesisDocuments($project, $user);
    }

    public static function canDownload(DocumentVersion $document, ?User $user): bool
    {
        return $user !== null && (self::canManage($document->project, $user)
            || self::canBrowseThesisDocuments($document->project, $user)
            || (in_array($document->project->status, ['approved', 'completed'], true) && $user->isEvaluator()
                && ReviewRequest::query()->where('project_id', $document->project_id)
                ->where('document_version_id', $document->id)
                ->where('evaluator_id', $user->id)
                ->whereIn('status', ['accepted', 'completed'])->exists()));
    }

    /** @return Collection<int, User> */
    public static function members(Project $project): Collection
    {
        return $project->members()->get()->push($project->owner)->filter()->unique('id')->values();
    }

    public static function assertActive(User $user): void
    {
        abort_if($user->is_suspended, 403, 'This account is suspended.');
    }
}

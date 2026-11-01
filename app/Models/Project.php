<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Project extends Model
{
    use HasFactory;

    public const PDF_ACCESS_ASSIGNED = 'assigned';
    public const PDF_ACCESS_APPROVED_EVALUATORS = 'approved_evaluators';
    public const REVIEW_MODE_REQUEST_ONLY = 'request_only';
    public const REVIEW_MODE_VOLUNTEER = 'volunteer';

    protected $guarded = [];

    protected $attributes = [
        'pdf_access' => self::PDF_ACCESS_ASSIGNED,
        'review_mode' => self::REVIEW_MODE_REQUEST_ONLY,
        'reviewer_limit' => 1,
    ];

    protected function casts(): array
    {
        return ['technologies' => 'array', 'deadline' => 'date', 'is_public' => 'boolean', 'reviewer_limit' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function reviewRequests(): HasMany
    {
        return $this->hasMany(ReviewRequest::class);
    }

    public function latestDocument(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version', 'max');
    }

    public function usesVolunteerReviews(): bool
    {
        return $this->review_mode === self::REVIEW_MODE_VOLUNTEER;
    }

    public function scopeWithReviewProgress(Builder $query): Builder
    {
        return $query->withCount($this->reviewProgressRelations());
    }

    public function loadReviewProgress(): static
    {
        return $this->loadCount($this->reviewProgressRelations());
    }

    private function reviewProgressRelations(): array
    {
        return [
            'reviewRequests as occupied_reviewers_count' => fn (Builder $query) => $query
                ->whereIn('status', ['accepted', 'completed'])->select(DB::raw('count(distinct evaluator_id)')),
            'reviewRequests as completed_reviewers_count' => fn (Builder $query) => $query
                ->where('status', 'completed')->select(DB::raw('count(distinct evaluator_id)')),
            'reviewRequests as accepted_reviews_count' => fn (Builder $query) => $query->where('status', 'accepted'),
        ];
    }

    public function occupiedReviewerCount(): int
    {
        if (array_key_exists('occupied_reviewers_count', $this->getAttributes())) {
            return (int) $this->occupied_reviewers_count;
        }

        return $this->reviewRequests()->whereIn('status', ['accepted', 'completed'])->distinct()->count('evaluator_id');
    }

    public function completedReviewerCount(): int
    {
        if (array_key_exists('completed_reviewers_count', $this->getAttributes())) {
            return (int) $this->completed_reviewers_count;
        }

        return $this->reviewRequests()->where('status', 'completed')->distinct()->count('evaluator_id');
    }

    /** Reconcile completion while the caller holds the project row lock. */
    public function syncReviewCompletion(): void
    {
        if (! in_array($this->status, ['approved', 'completed'], true)) {
            return;
        }

        // Query current rows: display counts loaded before a transition may be stale.
        $completed = $this->reviewRequests()->where('status', 'completed')->distinct()->count('evaluator_id');
        $isComplete = $this->usesVolunteerReviews()
            ? $completed >= $this->reviewer_limit && ! $this->reviewRequests()->where('status', 'accepted')->exists()
            : ($completed > 0 || $this->status === 'completed');
        $status = $isComplete ? 'completed' : 'approved';
        if ($this->status !== $status) {
            $this->status = $status;
            $this->save();
        }
    }

    public function canManage(User $user): bool
    {
        if ($user->is_suspended) {
            return false;
        }

        return $user->isAdmin() || (int) $this->user_id === (int) $user->id
            || $this->members()->whereKey($user->id)->exists();
    }

    public function canAccess(User $user): bool
    {
        if ($this->canManage($user)) {
            return true;
        }

        return $user->isEvaluator() && in_array($this->status, ['approved', 'completed'], true)
            && $this->reviewRequests()->where('evaluator_id', $user->id)
                ->whereIn('status', ['accepted', 'completed'])->exists();
    }
}

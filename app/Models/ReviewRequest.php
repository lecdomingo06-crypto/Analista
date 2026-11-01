<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewRequest extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['preferred_date' => 'date', 'agreed_date' => 'date', 'accepted_at' => 'datetime', 'completed_at' => 'datetime', 'output_size' => 'integer'];
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'accepted')->where('agreed_date', '<', today()->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->status === 'accepted' && $this->agreed_date !== null && $this->agreed_date->lt(today());
    }

    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) $this->agreed_date->diffInDays(today()) : 0;
    }

    public function safeOutputPath(): ?string
    {
        return is_string($this->output_path)
            && preg_match('/\Areview-outputs\/'.$this->id.'\/[A-Za-z0-9]{40}\.pdf\z/', $this->output_path)
                ? $this->output_path : null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class)->oldest();
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }
}

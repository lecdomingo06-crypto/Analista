<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\ProfilePhoto;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'profile_photo_path',
        'google_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_suspended' => 'boolean',
            'expertise' => 'array',
            'skills' => 'array',
            'review_modes' => 'array',
            'experience_years' => 'integer',
            'capacity' => 'integer',
            'accepting_requests' => 'boolean',
        ];
    }

    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : Str::lower(trim($value)));
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function memberProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    public function evaluatorRequests(): HasMany
    {
        return $this->hasMany(ReviewRequest::class, 'evaluator_id');
    }

    public function evaluatorVerifications(): HasMany
    {
        return $this->hasMany(EvaluatorVerification::class);
    }

    public function latestEvaluatorVerification(): HasOne
    {
        return $this->hasOne(EvaluatorVerification::class)->latestOfMany();
    }

    public function evaluatorProfileSnapshot(): array
    {
        return [
            'name' => $this->name,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'expertise' => $this->expertise,
            'skills' => $this->skills,
            'experience_years' => $this->experience_years,
            'portfolio_url' => $this->portfolio_url,
            'review_modes' => $this->review_modes,
        ];
    }

    public function evaluatorProfileFingerprint(): string
    {
        return hash('sha256', json_encode($this->evaluatorProfileSnapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function directConversations(): Builder
    {
        return DirectConversation::query()->forUser((int) $this->id);
    }

    public function receivedDirectMessages(): HasMany
    {
        return $this->hasMany(DirectMessage::class, 'recipient_id');
    }

    public function ratingsReceived(): HasMany
    {
        return $this->hasMany(Rating::class, 'evaluator_id');
    }

    public function bookmarks(): BelongsToMany
    {
        return $this->belongsToMany(Tutorial::class)->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin' && ! $this->is_suspended;
    }

    public function isEvaluator(): bool
    {
        return $this->role === 'evaluator' && $this->evaluator_status === 'approved' && ! $this->is_suspended;
    }

    public function hasReviewedEvaluatorProfile(): bool
    {
        if (! $this->isEvaluator()) {
            return false;
        }

        $verification = $this->latestEvaluatorVerification;

        return $verification?->status === 'approved' && $verification->matchesProfile($this);
    }

    public function initials(): string
    {
        return Str::of($this->name)->trim()->explode(' ')->filter()->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        $path = ProfilePhoto::safePath($this);

        return $path ? route('profile-photos.show', ['user' => $this->id, 'filename' => basename($path)]) : null;
    }

    public function notifyActivity(string $title, ?string $body, string $url, ?User $actor = null): Activity
    {
        return $this->activities()->create([...compact('title', 'body', 'url'), 'actor_id' => $actor?->id]);
    }
}

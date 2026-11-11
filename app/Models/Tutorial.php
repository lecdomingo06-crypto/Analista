<?php

namespace App\Models;

use App\Support\YouTubeVideo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tutorial extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['video_path'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'featured' => 'boolean', 'reading_minutes' => 'integer', 'video_size' => 'integer'];
    }

    public function getIsVideoAttribute(): bool
    {
        return $this->is_youtube_video || $this->has_uploaded_video;
    }

    public function getIsYoutubeVideoAttribute(): bool
    {
        return YouTubeVideo::isValidId($this->youtube_video_id);
    }

    public function getHasUploadedVideoAttribute(): bool
    {
        return $this->safeVideoPath() !== null;
    }

    public function safeVideoPath(): ?string
    {
        return is_string($this->video_path) && preg_match('/\Atutorial-videos\/[A-Za-z0-9]{40}\.(mp4|webm)\z/', $this->video_path)
            ? $this->video_path : null;
    }

    public function scopeVideos(Builder $query): Builder
    {
        return $query->where(fn (Builder $videos) => $videos->whereNotNull('youtube_video_id')->orWhereNotNull('video_path'));
    }

    public function canView(?User $user): bool
    {
        return $this->is_published || $user?->isAdmin()
            || ($user?->isEvaluator() && (int) $this->user_id === (int) $user->id);
    }

    public function getYoutubeUrlAttribute(): ?string
    {
        return $this->is_youtube_video ? 'https://www.youtube.com/watch?v='.$this->youtube_video_id : null;
    }

    public function getEmbedUrlAttribute(): ?string
    {
        return $this->is_youtube_video ? 'https://www.youtube-nocookie.com/embed/'.$this->youtube_video_id.'?rel=0' : null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->is_youtube_video ? 'https://i.ytimg.com/vi/'.$this->youtube_video_id.'/hqdefault.jpg' : null;
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bookmarkedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }
}

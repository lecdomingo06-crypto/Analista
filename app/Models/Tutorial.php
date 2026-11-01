<?php

namespace App\Models;

use App\Support\YouTubeVideo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tutorial extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'featured' => 'boolean', 'reading_minutes' => 'integer'];
    }

    public function getIsVideoAttribute(): bool
    {
        return YouTubeVideo::isValidId($this->youtube_video_id);
    }

    public function getYoutubeUrlAttribute(): ?string
    {
        return $this->is_video ? 'https://www.youtube.com/watch?v='.$this->youtube_video_id : null;
    }

    public function getEmbedUrlAttribute(): ?string
    {
        return $this->is_video ? 'https://www.youtube-nocookie.com/embed/'.$this->youtube_video_id.'?rel=0' : null;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->is_video ? 'https://i.ytimg.com/vi/'.$this->youtube_video_id.'/hqdefault.jpg' : null;
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

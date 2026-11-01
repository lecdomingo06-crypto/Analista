<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectMessageAttachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class, 'direct_message_id');
    }

    public function getIsImageAttribute(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    public function getUrlAttribute(): string
    {
        return route('messages.attachments.show', $this);
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('messages.attachments.show', ['attachment' => $this->id, 'download' => 1]);
    }

    public function toMessageData(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'is_image' => $this->is_image,
            'url' => $this->url,
            'download_url' => $this->download_url,
        ];
    }
}

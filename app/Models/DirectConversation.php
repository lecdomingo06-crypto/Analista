<?php

namespace App\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DirectConversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function lowerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lower_user_id');
    }

    public function higherUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'higher_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(DirectMessage::class)->latestOfMany();
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $participants) => $participants
            ->where('lower_user_id', $userId)->orWhere('higher_user_id', $userId));
    }

    public function includesUser(User|int $user): bool
    {
        $userId = $user instanceof User ? (int) $user->id : $user;

        return $userId > 0 && in_array($userId, [(int) $this->lower_user_id, (int) $this->higher_user_id], true);
    }

    public function otherParticipant(User|int $user): User
    {
        if (! $this->includesUser($user)) {
            throw new AuthorizationException('Only conversation participants can access its members.');
        }
        $userId = $user instanceof User ? (int) $user->id : $user;
        $this->loadMissing(['lowerUser', 'higherUser']);

        return (int) $this->lower_user_id === $userId ? $this->higherUser : $this->lowerUser;
    }
}

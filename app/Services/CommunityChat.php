<?php

namespace App\Services;

use App\Models\CommunityChatMessage;
use App\Models\CommunityChatPresence;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CommunityChat
{
    public const PRESENCE_SECONDS = 90;

    public const PAGE_SIZE = 50;

    public function viewer(Request $request): array
    {
        $user = $request->user();
        if ($user) {
            return [
                'id' => 'a_'.$this->opaque('account:'.$user->id),
                'name' => $user->name,
                'avatar_url' => $user->profile_photo_url,
                'guest' => false,
                'needs_name' => false,
                'can_send' => ! $user->is_suspended,
            ];
        }

        $guestKey = $request->session()->get('community_chat.guest_key');
        if (! is_string($guestKey) || ! Str::isUuid($guestKey)) {
            $guestKey = (string) Str::uuid();
            $request->session()->put('community_chat.guest_key', $guestKey);
        }
        $id = 'g_'.$this->opaque('guest:'.$guestKey);
        $name = $request->session()->get('community_chat.name');
        $hasName = is_string($name) && trim($name) !== '';

        return [
            'id' => $id,
            'name' => $hasName ? $name : 'Guest '.strtoupper(substr($id, 2, 4)),
            'avatar_url' => null,
            'guest' => true,
            'needs_name' => ! $hasName,
            'can_send' => $hasName,
        ];
    }

    public function presence(): array
    {
        $active = CommunityChatPresence::query()->where('last_seen_at', '>', now()->subSeconds(self::PRESENCE_SECONDS));
        $visitors = (clone $active)->with('user:id,name,profile_photo_path')
            ->orderByDesc('last_seen_at')->orderBy('id')->limit(6)->get();

        return [
            'count' => $active->count(),
            'visitors' => $visitors->map(fn (CommunityChatPresence $presence) => [
                'id' => $presence->viewer_key,
                'name' => $presence->user?->name ?? $presence->guest_name,
                'avatar_url' => $presence->user?->profile_photo_url,
                'guest' => $presence->user_id === null,
            ])->values()->all(),
        ];
    }

    public function touchPresence(Request $request, array $viewer): void
    {
        // Signing in replaces this session's guest entry instead of counting it twice.
        $guestKey = $request->session()->get('community_chat.guest_key');
        if ($request->user() && is_string($guestKey)) {
            CommunityChatPresence::query()->where('viewer_key', 'g_'.$this->opaque('guest:'.$guestKey))->delete();
        }

        CommunityChatPresence::query()->upsert([[
            'viewer_key' => $viewer['id'],
            'user_id' => $request->user()?->id,
            'guest_name' => $viewer['guest'] ? $viewer['name'] : null,
            'last_seen_at' => now(),
        ]], ['viewer_key'], ['user_id', 'guest_name', 'last_seen_at']);

        // Expired rows never contribute to the count; housekeeping remains bounded.
        $expiredIds = CommunityChatPresence::query()->where('last_seen_at', '<=', now()->subSeconds(self::PRESENCE_SECONDS))
            ->orderBy('id')->limit(200)->pluck('id');
        if ($expiredIds->isNotEmpty()) {
            CommunityChatPresence::query()->whereIn('id', $expiredIds)->delete();
        }
    }

    public function messageData(CommunityChatMessage $message, array $viewer): array
    {
        return [
            'id' => $message->id,
            'author' => [
                'id' => $message->author_key,
                'name' => $message->user?->name ?? $message->author_name,
                'avatar_url' => $message->user?->profile_photo_url,
                'guest' => $message->is_guest,
            ],
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
            'own' => hash_equals($viewer['id'], $message->author_key),
        ];
    }

    public function opaque(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CommunityChatMessage;
use App\Services\CommunityChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommunityChatController extends Controller
{
    public function __construct(private readonly CommunityChat $chat) {}

    public function state(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX]]);
        $viewer = $this->chat->viewer($request);
        $query = CommunityChatMessage::query()->with('user:id,name,profile_photo_path');
        if (array_key_exists('after', $data)) {
            $messages = $query->where('id', '>', (int) $data['after'])->orderBy('id')->limit(CommunityChat::PAGE_SIZE)->get();
        } else {
            $messages = $query->orderByDesc('id')->limit(CommunityChat::PAGE_SIZE)->get()->reverse()->values();
        }

        return response()->json([
            'presence' => $this->chat->presence(),
            'viewer' => $viewer,
            'messages' => $messages->map(fn (CommunityChatMessage $message) => $this->chat->messageData($message, $viewer))->values(),
            'next_after_id' => $messages->last()?->id ?? (int) ($data['after'] ?? 0),
        ]);
    }

    public function presence(Request $request): JsonResponse
    {
        $viewer = $this->chat->viewer($request);
        $this->chat->touchPresence($request, $viewer);

        return response()->json(['presence' => $this->chat->presence(), 'viewer' => $viewer]);
    }

    public function store(Request $request): JsonResponse
    {
        $viewer = $this->chat->viewer($request);
        if ($viewer['needs_name']) {
            throw ValidationException::withMessages(['name' => 'Choose a name before joining the chat.']);
        }
        abort_unless($viewer['can_send'], 403, 'Community chat is read-only for this account.');
        if (is_string($request->input('body'))) {
            $request->merge(['body' => trim($request->input('body'))]);
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $message = CommunityChatMessage::query()->create([
            'user_id' => $request->user()?->id,
            'author_key' => $viewer['id'],
            'author_name' => $viewer['name'],
            'is_guest' => $viewer['guest'],
            'body' => $data['body'],
        ]);
        $message->setRelation('user', $request->user());

        return response()->json(['message' => $this->chat->messageData($message, $viewer)], 201);
    }

    public function identity(Request $request): JsonResponse
    {
        if (! $request->user()) {
            if (is_string($request->input('name'))) {
                $request->merge(['name' => trim($request->input('name'))]);
            }
            $data = $request->validate(['name' => ['required', 'string', 'max:40', 'not_regex:/[\p{C}]/u']]);
            $request->session()->put('community_chat.name', $data['name']);
            $this->chat->touchPresence($request, $this->chat->viewer($request));
        }

        return response()->json(['viewer' => $this->chat->viewer($request), 'presence' => $this->chat->presence()]);
    }
}

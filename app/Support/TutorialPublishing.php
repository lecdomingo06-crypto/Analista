<?php

namespace App\Support;

use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TutorialPublishing
{
    public const MAX_VIDEO_KB = 30 * 1024;

    public static function authorize(User $user, ?Tutorial $tutorial = null): void
    {
        abort_unless($user->isAdmin() || $user->isEvaluator(), 403);
        abort_if($tutorial && ! $user->isAdmin() && (int) $tutorial->user_id !== (int) $user->id, 403);
    }

    public static function save(Request $request, ?Tutorial $tutorial = null): Tutorial
    {
        $actor = $request->user();
        self::authorize($actor, $tutorial);
        foreach (['title', 'category', 'excerpt', 'body', 'video_creator'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim(strip_tags($request->input($field)))]);
            }
        }
        if (! $request->has('source_type')) {
            $request->merge(['source_type' => $request->hasFile('video') || ($tutorial?->has_uploaded_video && ! $request->filled('youtube_url')) ? 'upload' : 'youtube']);
        }
        $uploadRequired = $request->input('source_type') === 'upload' && ! $tutorial?->has_uploaded_video;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'excerpt' => ['required', 'string', 'max:500'],
            'video_creator' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:60000'],
            'source_type' => ['required', Rule::in(['youtube', 'upload'])],
            'youtube_url' => [Rule::excludeIf($request->input('source_type') !== 'youtube'), 'bail', 'required', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (YouTubeVideo::idFromUrl($value) === null) {
                    $fail('Enter a valid HTTPS YouTube or youtu.be video link.');
                }
            }],
            'video' => [$request->input('source_type') === 'youtube' ? 'prohibited' : ($uploadRequired ? 'required' : 'nullable'), 'file', 'extensions:mp4,webm', 'mimetypes:video/mp4,video/webm', 'max:'.self::MAX_VIDEO_KB],
            'is_published' => ['sometimes', 'boolean'],
            'featured' => $actor->isAdmin() ? ['sometimes', 'boolean'] : ['prohibited'],
        ], ['video.max' => 'Please choose a video no larger than 30 MB.', 'video.mimetypes' => 'Choose an MP4 or WebM video.']);
        $source = $data['source_type'];
        unset($data['source_type'], $data['youtube_url'], $data['video']);
        $data['body'] = $data['body'] ?? '';
        $data['is_published'] = $request->boolean('is_published');
        if ($actor->isAdmin()) {
            $data['featured'] = $request->boolean('featured');
        }
        $newPath = null;
        $oldPath = null;
        try {
            if ($source === 'youtube') {
                $data += ['youtube_video_id' => YouTubeVideo::idFromUrl($request->input('youtube_url')), 'video_path' => null, 'video_original_name' => null, 'video_mime_type' => null, 'video_size' => null];
            } elseif ($file = $request->file('video')) {
                $mime = $file->getMimeType();
                $handle = fopen($file->getRealPath(), 'rb');
                $header = $handle ? fread($handle, 32) : '';
                if ($handle) fclose($handle);
                if (($mime === 'video/mp4' && substr($header, 4, 4) !== 'ftyp')
                    || ($mime === 'video/webm' && ! str_starts_with($header, "\x1a\x45\xdf\xa3"))) {
                    throw ValidationException::withMessages(['video' => 'This file could not be read as an MP4 or WebM video.']);
                }
                $newPath = $file->storeAs('tutorial-videos', Str::random(40).($mime === 'video/webm' ? '.webm' : '.mp4'), 'local');
                if (! $newPath) throw ValidationException::withMessages(['video' => 'The video could not be saved. Please try again.']);
                $data += ['youtube_video_id' => null, 'video_path' => $newPath, 'video_original_name' => Str::limit($file->getClientOriginalName(), 255, ''), 'video_mime_type' => $mime, 'video_size' => $file->getSize()];
            }
            $saved = DB::transaction(function () use ($actor, $tutorial, $data, $source, $newPath, &$oldPath) {
                if ($tutorial) {
                    $record = Tutorial::query()->lockForUpdate()->findOrFail($tutorial->id);
                    self::authorize($actor, $record);
                    if ($source === 'upload' && ! $newPath && ! $record->has_uploaded_video) {
                        throw ValidationException::withMessages(['video' => 'Choose a video to upload.']);
                    }
                } else {
                    $base = Str::slug($data['title']) ?: 'tutorial';
                    $slug = $base;
                    for ($number = 2; Tutorial::where('slug', $slug)->exists(); $number++) $slug = $base.'-'.$number;
                    $record = new Tutorial(['user_id' => $actor->id, 'slug' => $slug]);
                }
                $oldPath = $record->safeVideoPath();
                $creating = ! $record->exists;
                $before = $creating ? [] : ['is_published' => $record->is_published, 'featured' => $record->featured];
                $record->fill($data);
                $changed = array_keys($record->getDirty());
                if ($creating || $changed !== []) {
                    $record->save();
                    if ($actor->isAdmin()) {
                        AdminAudit::record($actor, $creating ? 'tutorial.created' : 'tutorial.updated', 'tutorial', $record->id,
                            $before, ['is_published' => $record->is_published, 'featured' => $record->featured], null,
                            $creating ? [] : ['changed_fields' => $changed]);
                    }
                }
                if ($oldPath && $oldPath !== $record->safeVideoPath()) {
                    DB::afterCommit(fn () => self::deleteUnusedVideo($oldPath));
                }
                return $record;
            }, 3);
        } catch (Throwable $exception) {
            if ($newPath) self::deleteUnusedVideo($newPath);
            throw $exception;
        }

        return $saved;
    }

    public static function destroy(User $actor, Tutorial $tutorial): void
    {
        self::authorize($actor, $tutorial);
        DB::transaction(function () use ($actor, $tutorial) {
            $record = Tutorial::query()->lockForUpdate()->findOrFail($tutorial->id);
            self::authorize($actor, $record);
            $path = $record->safeVideoPath();
            $before = ['is_published' => $record->is_published, 'featured' => $record->featured];
            $record->delete();
            if ($actor->isAdmin()) AdminAudit::record($actor, 'tutorial.deleted', 'tutorial', $record->id, $before);
            if ($path) DB::afterCommit(fn () => self::deleteUnusedVideo($path));
        }, 3);
    }

    private static function deleteUnusedVideo(string $path): void
    {
        if (preg_match('/\Atutorial-videos\/[A-Za-z0-9]{40}\.(mp4|webm)\z/', $path) && ! Tutorial::where('video_path', $path)->exists()) {
            Storage::disk('local')->delete($path);
        }
    }
}

<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProfilePhoto
{
    public static function validatedImage(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $details = $path ? @getimagesize($path) : false;
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if (! $extension || ! $details || ($details['mime'] ?? null) !== $mime
            || $details[0] < 1 || $details[1] < 1 || $details[0] > 4096 || $details[1] > 4096
            || (function_exists('exif_imagetype') && @exif_imagetype($path) !== $details[2])) {
            throw ValidationException::withMessages([
                'profile_photo' => 'Choose a valid JPEG, PNG, or WebP image no larger than 4096 × 4096 pixels.',
            ]);
        }

        return ['path' => $path, 'mime' => $mime, 'extension' => $extension];
    }

    public static function store(UploadedFile $file, User $user): string
    {
        $image = self::validatedImage($file);
        $contents = file_get_contents($image['path']);

        // Decode and re-encode when GD is available. File identity and dimensions
        // are also checked above on installations that do not enable GD.
        if (function_exists('imagecreatefromstring')) {
            $decoded = @imagecreatefromstring($contents);
            if ($decoded === false) {
                throw ValidationException::withMessages(['profile_photo' => 'This image could not be read. Please choose another photo.']);
            }
            ob_start();
            try {
                imagealphablending($decoded, false);
                imagesavealpha($decoded, true);
                $encoded = match ($image['extension']) {
                    'jpg' => imagejpeg($decoded, null, 90),
                    'png' => imagepng($decoded, null, 8),
                    'webp' => imagewebp($decoded, null, 90),
                };
                $contents = ob_get_contents();
            } finally {
                ob_end_clean();
                imagedestroy($decoded);
            }
            if (! $encoded || ! is_string($contents)) {
                throw new RuntimeException('The profile photo could not be processed.');
            }
        }

        $path = 'profile-photos/'.$user->id.'/'.Str::random(40).'.'.$image['extension'];
        if (! is_string($contents) || ! Storage::disk('local')->put($path, $contents)) {
            Storage::disk('local')->delete($path);
            throw new RuntimeException('The profile photo could not be saved.');
        }

        return $path;
    }

    public static function safePath(User $user, ?string $path = null): ?string
    {
        $path ??= $user->profile_photo_path;

        return is_string($path) && preg_match('/\Aprofile-photos\/'.preg_quote((string) $user->id, '/').'\/[A-Za-z0-9]{40}\.(jpg|png|webp)\z/', $path)
            ? $path : null;
    }

    public static function localFile(User $user): ?string
    {
        $path = self::safePath($user);
        if (! $path) {
            return null;
        }
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $actual = realpath($disk->path($path));
        if (! $root || ! $actual || ! is_file($actual)) {
            return null;
        }
        $expected = str_replace('\\', '/', $root).'/'.$path;

        return strcasecmp(str_replace('\\', '/', $actual), $expected) === 0 ? $actual : null;
    }

    public static function delete(User $user, ?string $path): void
    {
        if ($path && self::safePath($user, $path) && ! User::where('profile_photo_path', $path)->exists()) {
            Storage::disk('local')->delete($path);
        }
    }
}

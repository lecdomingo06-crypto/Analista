<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ProfilePhoto;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfilePhotoController extends Controller
{
    public function show(User $user, string $filename): BinaryFileResponse
    {
        $path = ProfilePhoto::safePath($user);
        abort_unless(! $user->is_suspended && $path && hash_equals(basename($path), $filename), 404);
        $file = ProfilePhoto::localFile($user);
        abort_unless($file, 404);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        $details = @getimagesize($file);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && $details && ($details['mime'] ?? null) === $mime, 404);

        return response()->file($file, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}

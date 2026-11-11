<?php

namespace App\Http\Controllers;

use App\Models\Tutorial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TutorialVideoController extends Controller
{
    public function show(Request $request, Tutorial $tutorial): BinaryFileResponse
    {
        abort_unless($tutorial->canView($request->user()), 404);
        $path = $tutorial->safeVideoPath();
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $file = Storage::disk('local')->path($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        abort_unless(in_array($mime, ['video/mp4', 'video/webm'], true), 404);
        return response()->file($file, [
            'Content-Type' => $mime, 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}

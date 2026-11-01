<?php

namespace App\Support;

use App\Models\DirectMessage;
use App\Models\DirectMessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MessageAttachment
{
    public const MAX_FILES = 5;

    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    private const MIME_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip',
    ];

    /** Validate every upload before any message or file is written. */
    public static function validateUploads(array $uploads): array
    {
        if (count($uploads) > self::MAX_FILES) {
            throw ValidationException::withMessages(['attachments' => 'Attach up to 5 files per message.']);
        }
        $total = 0;
        $validated = [];
        foreach ($uploads as $index => $file) {
            $key = 'attachments.'.$index;
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                self::invalid($key);
            }
            $size = $file->getSize();
            if (! is_int($size) || $size < 1 || $size > self::MAX_FILE_BYTES) {
                throw ValidationException::withMessages([$key => 'Each attachment must be a nonempty file no larger than 10 MB.']);
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_BYTES) {
                throw ValidationException::withMessages(['attachments' => 'Attachments must total no more than 20 MB per message.']);
            }
            $name = self::safeName($file->getClientOriginalName());
            $validated[] = ['upload' => $file, 'name' => $name, ...self::inspect($file->getRealPath(), $name, $key)];
        }

        return $validated;
    }

    /** Record each generated path before writing so a partial failure can be cleaned up. */
    public static function store(DirectMessage $message, array $files, array &$written): void
    {
        foreach ($files as $index => $file) {
            $path = 'message-attachments/'.$message->direct_conversation_id.'/'.$message->id.'/'.Str::random(40).'.'.$file['extension'];
            $written[] = $path;
            try {
                $saved = Storage::disk('local')->putFileAs(dirname($path), $file['upload'], basename($path), ['visibility' => 'private']);
            } catch (Throwable) {
                $saved = false;
            }
            if (! is_string($saved) || $saved !== $path) {
                throw ValidationException::withMessages(['attachments.'.$index => 'This attachment could not be saved. Please try again.']);
            }
            $message->attachments()->create([
                'name' => $file['name'], 'path' => $path,
                'size' => $file['size'], 'mime_type' => $file['mime_type'],
            ]);
        }
    }

    public static function cleanup(array &$paths): void
    {
        foreach ($paths as $path) {
            if (self::safePath($path)) {
                Storage::disk('local')->delete($path);
            }
        }
        $paths = [];
    }

    /** A path from the database never grants access outside this attachment's own directory. */
    public static function localFile(DirectMessageAttachment $attachment, DirectMessage $message): ?string
    {
        $path = $attachment->path;
        if (! is_string($path) || ! self::safePath($path)
            || ! str_starts_with($path, 'message-attachments/'.$message->direct_conversation_id.'/'.$message->id.'/')) {
            return null;
        }
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $actual = realpath($disk->path($path));
        if (! $root || ! $actual || ! is_file($actual)
            || strcasecmp(str_replace('\\', '/', $actual), str_replace('\\', '/', $root).'/'.$path) !== 0) {
            return null;
        }
        try {
            $details = self::inspect($actual, $attachment->name, 'attachments');
        } catch (ValidationException) {
            return null;
        }

        return $details['size'] === (int) $attachment->size && $details['mime_type'] === $attachment->mime_type ? $actual : null;
    }

    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', mb_convert_encoding($name, 'UTF-8', 'UTF-8')));
        $name = preg_replace('/[\x00-\x1f\x7f\p{Cf}]/u', '', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') {
            return 'attachment';
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (mb_strlen($name) > 180) {
            $suffix = $extension !== '' ? '.'.mb_substr($extension, 0, 12) : '';
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 180 - mb_strlen($suffix)).$suffix;
        }

        return $name;
    }

    private static function safePath(string $path): bool
    {
        return preg_match('/\Amessage-attachments\/[1-9][0-9]*\/[1-9][0-9]*\/[A-Za-z0-9]{40}\.(jpg|jpeg|png|gif|webp|pdf|docx?|xlsx?|pptx?|txt|csv|zip)\z/', $path) === 1;
    }

    private static function inspect(string|false $path, string $name, string $key): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$extension] ?? null;
        $size = $path ? @filesize($path) : false;
        if (! $mime || ! is_int($size) || $size < 1 || $size > self::MAX_FILE_BYTES) {
            self::invalid($key);
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $bytes = @file_get_contents($path);
        if (! is_string($bytes)) {
            self::invalid($key);
        }
        if (str_starts_with($mime, 'image/')) {
            $details = @getimagesize($path);
            if ($detected !== $mime || ! $details || ($details['mime'] ?? null) !== $mime
                || $details[0] < 1 || $details[1] < 1 || $details[0] > 8192 || $details[1] > 8192
                || $details[0] * $details[1] > 32000000
                || (function_exists('exif_imagetype') && @exif_imagetype($path) !== $details[2])) {
                self::invalid($key, 'Choose a valid JPEG, PNG, GIF, or WebP image no larger than 8192 pixels per side and 32 megapixels.');
            }
        } elseif ($extension === 'pdf') {
            if ($detected !== $mime || preg_match('/\A%PDF-[12]\.\d/', $bytes) !== 1
                || preg_match('/\d+\s+\d+\s+obj\b/', $bytes) !== 1 || ! str_contains($bytes, 'endobj')
                || preg_match('/%%EOF\s*\z/', $bytes) !== 1) {
                self::invalid($key);
            }
        } elseif (in_array($extension, ['txt', 'csv'], true)) {
            // Some libmagic versions classify large plain-text files as binary.
            // Only use their prefix classification when the complete file also
            // passes the UTF-8, control-character and active-content checks below.
            $textMime = $detected === 'application/octet-stream'
                ? (new \finfo(FILEINFO_MIME_TYPE))->buffer(substr($bytes, 0, 1024)) : $detected;
            if (! in_array($textMime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'], true)
                || ! mb_check_encoding($bytes, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $bytes) !== 0
                || preg_match('/<\s*(?:!doctype|html|head|body|script|svg|iframe|object|embed)\b|<\?|\A(?:\xef\xbb\xbf)?\s*(?:#!|%PDF-|MZ|ZM)/i', $bytes) !== 0) {
                self::invalid($key);
            }
        } elseif (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            $stream = match ($extension) {
                'doc' => 'WordDocument', 'xls' => 'Workbook', 'ppt' => 'PowerPoint Document'
            };
            if (! in_array($detected, [$mime, 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'], true)
                || ! str_starts_with($bytes, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")
                || (! str_contains($bytes, mb_convert_encoding($stream, 'UTF-16LE', 'UTF-8'))
                    && ! ($extension === 'xls' && str_contains($bytes, mb_convert_encoding('Book', 'UTF-16LE', 'UTF-8'))))) {
                self::invalid($key);
            }
        } else {
            if (! in_array($detected, [$mime, 'application/zip', 'application/x-zip', 'application/x-zip-compressed'], true)) {
                self::invalid($key);
            }
            $entries = self::zipEntries($bytes);
            if ($entries === null) {
                self::invalid($key);
            }
            if ($extension !== 'zip') {
                $document = match ($extension) {
                    'docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml',
                };
                if (! in_array('[Content_Types].xml', $entries, true) || ! in_array($document, $entries, true)
                    || preg_grep('/(?:\A|\/)vbaProject\.bin\z/i', $entries)) {
                    self::invalid($key);
                }
            }
        }

        return ['mime_type' => $mime, 'size' => $size, 'extension' => $extension === 'jpeg' ? 'jpg' : $extension];
    }

    /** Inspect ZIP metadata without extracting files or depending on PHP's optional ZIP extension. */
    private static function zipEntries(string $bytes): ?array
    {
        $end = strrpos($bytes, "PK\x05\x06");
        if ($end === false || $end < strlen($bytes) - 65557 || strlen($bytes) < $end + 22) {
            return null;
        }
        $directory = unpack('vdisk/vcentralDisk/vdiskEntries/ventries/Vsize/Voffset/vcomment', substr($bytes, $end + 4, 18));
        if ($directory['disk'] !== 0 || $directory['centralDisk'] !== 0 || $directory['entries'] !== $directory['diskEntries']
            || $directory['entries'] > 10000 || $end !== $directory['offset'] + $directory['size']
            || $end + 22 + $directory['comment'] !== strlen($bytes)) {
            return null;
        }
        $position = $directory['offset'];
        $names = [];
        for ($entry = 0; $entry < $directory['entries']; $entry++) {
            if ($position + 46 > $end || substr($bytes, $position, 4) !== "PK\x01\x02") {
                return null;
            }
            $info = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($bytes, $position + 8, 38));
            $next = $position + 46 + $info['name'] + $info['extra'] + $info['comment'];
            $name = substr($bytes, $position + 46, $info['name']);
            if ($info['disk'] !== 0 || $next > $end || $name === '' || str_contains($name, "\0")
                || preg_match('~(?:\A[/\\\\]|(?:\A|[/\\\\])\.\.(?:[/\\\\]|\z)|\A[A-Za-z]:)~', $name)
                || $directory['offset'] < $info['offset'] + 30 || substr($bytes, $info['offset'], 4) !== "PK\x03\x04") {
                return null;
            }
            $names[] = str_replace('\\', '/', $name);
            $position = $next;
        }

        return $position === $end ? $names : null;
    }

    private static function invalid(string $key, ?string $message = null): never
    {
        throw ValidationException::withMessages([
            $key => $message ?? 'Choose a valid image, PDF, Word, Excel, PowerPoint, text, CSV, or ZIP file.',
        ]);
    }
}

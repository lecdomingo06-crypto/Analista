<?php

namespace App\Support;

final class YouTubeVideo
{
    public static function isValidId(?string $id): bool
    {
        return is_string($id) && preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id) === 1;
    }

    public static function idFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $id = null;

        if ($host === 'youtu.be') {
            if (preg_match('~\A/([A-Za-z0-9_-]{11})/?\z~', $path, $matches)) {
                $id = $matches[1];
            }
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('~\A/(?:embed|shorts)/([A-Za-z0-9_-]{11})/?\z~', $path, $matches)) {
                $id = $matches[1];
            }
        }

        return self::isValidId($id) ? $id : null;
    }
}

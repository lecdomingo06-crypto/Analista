<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventStalePages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // HTML includes session-dependent navigation, even on public pages.
        if (str_contains(strtolower($response->headers->get('Content-Type', '')), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}

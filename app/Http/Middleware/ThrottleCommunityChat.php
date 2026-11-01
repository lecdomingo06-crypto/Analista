<?php

namespace App\Http\Middleware;

use App\Services\CommunityChat;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;

class ThrottleCommunityChat
{
    public function __construct(private readonly CommunityChat $chat) {}

    public function handle(Request $request, Closure $next): Response
    {
        $viewer = $this->chat->viewer($request);
        $action = $request->route()->getActionMethod();
        [$viewerLimit, $ipLimit] = match ($action) {
            'store' => [15, 60],
            'identity' => [10, 60],
            'presence' => [12, 240],
            default => [120, 600],
        };
        $limits = [
            'community-chat:'.$action.':viewer:'.$viewer['id'] => $viewerLimit,
            'community-chat:'.$action.':ip:'.$this->chat->opaque('ip:'.$request->ip()) => $ipLimit,
        ];
        foreach ($limits as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $retry = max(1, RateLimiter::availableIn($key));

                return $this->private(response()->json([
                    'message' => 'Please wait a moment before trying again.',
                ], 429)->header('Retry-After', $retry));
            }
        }
        foreach ($limits as $key => $limit) {
            RateLimiter::hit($key, 60);
        }

        try {
            return $this->private($next($request));
        } catch (ValidationException $exception) {
            return $this->private(response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status));
        } catch (HttpExceptionInterface $exception) {
            return $this->private(response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode(), $exception->getHeaders()));
        }
    }

    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}

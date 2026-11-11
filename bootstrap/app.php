<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->appendToGroup('web', [
            \App\Http\Middleware\PreventStalePages::class,
            \App\Http\Middleware\EnsureActive::class,
        ]);
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);
        $middleware->redirectUsersTo('/account');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The dashboard's API is read by fetch(): a validation failure must be
        // a JSON 422 it can show, never a redirect that lands on an HTML page
        // with 200.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());

        // A period the resolver cannot build (e.g. "to" before "from") is bad
        // input, not a server error.
        $exceptions->render(function (InvalidArgumentException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['period' => [$e->getMessage()]]], 422);
            }
        });
    })->create();

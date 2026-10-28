<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequirePermission;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie-session auth for the SPA (Sanctum). Mobile apps use bearer tokens.
        $middleware->statefulApi();

        $middleware->alias([
            'permission' => RequirePermission::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This application is API-only: always answer with JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Business rules enforced by PostgreSQL (balance, immutability, closed
        // fiscal year...) arrive as database errors. Turn them into clean answers.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $mapped = DatabaseErrors::map($e);
            if ($mapped === null) {
                return null; // unknown database error: let Laravel answer with a 500
            }

            return response()->json(['message' => $mapped['message']], $mapped['status']);
        });
    })->create();

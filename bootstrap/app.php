<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \App\Http\Middleware\InjectBearerFromQueryToken::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API-only: always return JSON, never try to render HTML views
        $exceptions->render(function (\Throwable $e) {
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return new \Illuminate\Http\JsonResponse(['message' => 'Unauthenticated.'], 401);
            }
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return new \Illuminate\Http\JsonResponse(['message' => 'This action is unauthorized.'], 403);
            }
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            return new \Illuminate\Http\JsonResponse([
                'message' => $e->getMessage() ?: 'Server Error',
            ], $status);
        });
    })->create();

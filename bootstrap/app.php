<?php

use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant' => ResolveTenant::class, 'tenant.access' => EnsureTenantAccess::class]);

        // Stateful (session-authenticated) API stack. First-party API endpoints that
        // authenticate with the `web` guard need the cookie/session middleware to run
        // *before* authentication and tenant resolution, otherwise the request has no
        // session store attached and ResolveTenant cannot read `active_college_id`.
        $middleware->group('api.stateful', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->expectsJson() && ! app()->isProduction()) return null;
            if ($request->expectsJson()) return response()->json(['message' => 'An unexpected error occurred.'], 500);
        });
    })->create();

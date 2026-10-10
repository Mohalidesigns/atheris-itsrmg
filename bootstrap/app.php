<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTenantContext::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
        // SCIM v2, SAML ACS/SLS and SIEM webhooks are programmatic; exempt from CSRF.
        $middleware->validateCsrfTokens(except: [
            'scim/v2/*',
            'auth/saml/*/acs',
            'auth/saml/*/sls',
            'ea/mcp/rpc',
            'siem/ingest/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render permission failures (403) as a friendly Inertia error page.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 403 && ! $request->expectsJson()) {
                return Inertia::render('Error', [
                    'status' => 403,
                    'message' => 'You do not have permission to access this page. Contact your administrator if you believe this is a mistake.',
                ])->toResponse($request)->setStatusCode(403);
            }

            return $response;
        });
    })->create();

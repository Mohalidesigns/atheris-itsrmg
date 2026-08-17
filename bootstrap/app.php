<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\SetTenantContext::class,
        ]);
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        // SCIM v2 and SAML ACS/SLS are programmatic; exempt from CSRF.
        $middleware->validateCsrfTokens(except: [
            'scim/v2/*',
            'auth/saml/*/acs',
            'auth/saml/*/sls',
            'ea/mcp/rpc',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render permission failures (403) as a friendly Inertia error page.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 403 && ! $request->expectsJson()) {
                return \Inertia\Inertia::render('Error', [
                    'status' => 403,
                    'message' => 'You do not have permission to access this page. Contact your administrator if you believe this is a mistake.',
                ])->toResponse($request)->setStatusCode(403);
            }

            return $response;
        });
    })->create();

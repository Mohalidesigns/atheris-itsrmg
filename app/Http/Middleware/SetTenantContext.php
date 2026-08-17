<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->organization_id) {
            app()->instance('current_organization_id', $request->user()->organization_id);
            app()->instance('current_organization', $request->user()->organization);
        }

        return $next($request);
    }
}

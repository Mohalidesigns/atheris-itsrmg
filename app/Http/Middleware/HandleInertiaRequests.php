<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'job_title' => $user->job_title,
                    'department' => $user->department,
                    'avatar_path' => $user->avatar_path,
                    'organization_id' => $user->organization_id,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
                'organization' => $user?->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'slug' => $user->organization->slug,
                    'logo_path' => $user->organization->logo_path,
                    'currency' => $user->organization->currency,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // A save that succeeded but left work to do — a diagram stored
                // with metamodel errors still outstanding (ATH-EAR-002 WS 4.1).
                // Without a third tone that outcome has to be reported as either
                // a success or a failure, and it is neither.
                'warning' => fn () => $request->session()->get('warning'),
                // Dry-run report from the ArchiMate import inspector (WS 4.4).
                'import_report' => fn () => $request->session()->get('import_report'),
            ],
        ];
    }
}

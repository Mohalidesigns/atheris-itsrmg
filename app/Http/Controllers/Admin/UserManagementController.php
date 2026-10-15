<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()
            ->with('roles:id,name')
            ->where('organization_id', $request->user()->organization_id);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%")
                    ->orWhereLike('job_title', "%{$search}%")
                    ->orWhereLike('department', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $users = $query->latest()->paginate(15)->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'roles' => $this->assignableRoles($request),
            'filters' => $request->only(['search', 'role', 'status']),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Admin/Users/Create', [
            'roles' => $this->assignableRoles($request),
            'organizations' => $this->organizations($request),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'job_title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'organization_id' => 'nullable|exists:organizations,id',
            'roles' => 'required|array|min:1',
            'roles.*' => [Rule::exists('roles', 'name')],
        ]);

        $this->guardRoles($request, $validated['roles']);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'job_title' => $validated['job_title'] ?? null,
            'department' => $validated['department'] ?? null,
            'organization_id' => $this->targetOrganization($request, $validated['organization_id'] ?? null),
            'is_active' => true,
        ]);

        $user->syncRoles($validated['roles']);

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$user->name}\" created successfully.");
    }

    public function edit(Request $request, int $user)
    {
        $user = $this->resolveUser($request, $user);

        return Inertia::render('Admin/Users/Edit', [
            'user' => $user->load('roles:id,name'),
            'roles' => $this->assignableRoles($request),
            'organizations' => $this->organizations($request),
        ]);
    }

    public function update(Request $request, int $user)
    {
        $user = $this->resolveUser($request, $user);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'job_title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'organization_id' => 'nullable|exists:organizations,id',
            'roles' => 'required|array|min:1',
            'roles.*' => [Rule::exists('roles', 'name')],
        ]);

        $this->guardRoles($request, $validated['roles']);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'job_title' => $validated['job_title'] ?? null,
            'department' => $validated['department'] ?? null,
            'organization_id' => $this->targetOrganization($request, $validated['organization_id'] ?? null, $user),
        ]);

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        $user->syncRoles($validated['roles']);

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$user->name}\" updated successfully.");
    }

    public function destroy(Request $request, int $user)
    {
        $user = $this->resolveUser($request, $user);
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$name}\" deleted successfully.");
    }

    public function toggleActive(Request $request, int $user)
    {
        $user = $this->resolveUser($request, $user);
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', "User \"{$user->name}\" ".($user->is_active ? 'activated' : 'deactivated').' successfully.');
    }

    private function isSuperAdmin(Request $request): bool
    {
        return $request->user()->hasRole('Super Admin');
    }

    /** Users outside the actor's bank do not exist for a bank admin. */
    private function resolveUser(Request $request, int $id): User
    {
        $user = User::findOrFail($id);
        abort_unless($this->isSuperAdmin($request) || $user->organization_id === $request->user()->organization_id, 404);

        return $user;
    }

    private function targetOrganization(Request $request, ?int $requested, ?User $existing = null): ?int
    {
        if ($this->isSuperAdmin($request)) {
            return $requested ?? $existing?->organization_id ?? $request->user()->organization_id;
        }

        return $request->user()->organization_id;
    }

    private function assignableRoles(Request $request)
    {
        return Role::orderBy('name')
            ->when(! $this->isSuperAdmin($request), fn ($q) => $q->where('name', '!=', 'Super Admin'))
            ->pluck('name');
    }

    private function organizations(Request $request)
    {
        return Organization::orderBy('name')
            ->when(! $this->isSuperAdmin($request), fn ($q) => $q->where('id', $request->user()->organization_id))
            ->get(['id', 'name']);
    }

    private function guardRoles(Request $request, array $roles): void
    {
        abort_if(in_array('Super Admin', $roles, true) && ! $this->isSuperAdmin($request), 403, 'Only a Super Admin can assign the Super Admin role.');
    }
}

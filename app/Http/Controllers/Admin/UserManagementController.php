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
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('job_title', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
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
            'roles' => Role::orderBy('name')->pluck('name'),
            'filters' => $request->only(['search', 'role', 'status']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Users/Create', [
            'roles' => Role::orderBy('name')->pluck('name'),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
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
            'organization_id' => 'required|exists:organizations,id',
            'roles' => 'required|array|min:1',
            'roles.*' => [Rule::exists('roles', 'name')],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'job_title' => $validated['job_title'] ?? null,
            'department' => $validated['department'] ?? null,
            'organization_id' => $validated['organization_id'],
            'is_active' => true,
        ]);

        $user->syncRoles($validated['roles']);

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$user->name}\" created successfully.");
    }

    public function edit(User $user)
    {
        return Inertia::render('Admin/Users/Edit', [
            'user' => $user->load('roles:id,name'),
            'roles' => Role::orderBy('name')->pluck('name'),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'job_title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'organization_id' => 'required|exists:organizations,id',
            'roles' => 'required|array|min:1',
            'roles.*' => [Rule::exists('roles', 'name')],
        ]);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'job_title' => $validated['job_title'] ?? null,
            'department' => $validated['department'] ?? null,
            'organization_id' => $validated['organization_id'],
        ]);

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        $user->syncRoles($validated['roles']);

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$user->name}\" updated successfully.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $name = $user->name;
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', "User \"{$name}\" deleted successfully.");
    }

    public function toggleActive(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', "User \"{$user->name}\" ".($user->is_active ? 'activated' : 'deactivated').' successfully.');
    }
}

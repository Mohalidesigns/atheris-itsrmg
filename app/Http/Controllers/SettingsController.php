<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function organization()
    {
        $organization = auth()->user()->organization;

        return Inertia::render('Settings/Organization', [
            'organization' => $organization,
        ]);
    }

    public function updateOrganization(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:255',
            'size' => 'nullable|in:small,medium,large,enterprise',
            'country' => 'nullable|string|max:100',
            'currency' => 'nullable|in:NGN,USD,GBP,EUR,GHS,KES,ZAR',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
        ]);

        $organization = auth()->user()->organization;
        $organization->update($validated);

        return redirect()->back()->with('success', 'Organization settings updated successfully.');
    }

    public function users()
    {
        // User management moved to the dedicated admin module.
        return redirect()->route('admin.users.index');
    }

    public function notifications()
    {
        return Inertia::render('Settings/Notifications');
    }
}

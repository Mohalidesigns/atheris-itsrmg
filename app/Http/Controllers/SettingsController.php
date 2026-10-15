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

    public const NOTIFICATION_PREFS = [
        'email_risk_alerts' => true, 'email_compliance_deadlines' => true, 'email_incidents' => true,
        'email_weekly_digest' => false, 'inapp_task_assignments' => true, 'inapp_mentions' => true,
    ];

    public function notifications(Request $request)
    {
        return Inertia::render('Settings/Notifications', [
            'prefs' => array_merge(self::NOTIFICATION_PREFS, $request->user()->notification_preferences ?? []),
        ]);
    }

    /** Preferences of the signed-in user only. Email delivery is not configured yet. */
    public function updateNotifications(Request $request)
    {
        $rules = collect(array_keys(self::NOTIFICATION_PREFS))->mapWithKeys(fn ($k) => [$k => ['required', 'boolean']])->all();
        $prefs = $request->validate($rules);
        $request->user()->forceFill(['notification_preferences' => $prefs])->save();

        return back()->with('success', 'Notification preferences saved.');
    }
}

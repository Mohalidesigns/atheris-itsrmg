<?php

namespace App\Http\Controllers;

/**
 * The old Policy Management "Change Requests" stub. The module now lives at /changes
 * (App\Http\Controllers\Change\*); the route name is kept because the navigation and the route
 * smoke test reference it.
 */
class ChangeRequestController extends Controller
{
    public function index()
    {
        return redirect()->route('changes.index');
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ChangeRequestController extends Controller
{
    public function index()
    {
        return Inertia::render('Policies/ChangeRequests/Index');
    }
}

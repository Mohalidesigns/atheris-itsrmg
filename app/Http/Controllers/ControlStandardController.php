<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlStandardController extends Controller
{
    public function index()
    {
        return Inertia::render('Policies/ControlStandards/Index');
    }
}

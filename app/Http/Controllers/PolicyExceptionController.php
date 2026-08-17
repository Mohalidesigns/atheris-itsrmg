<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyExceptionController extends Controller
{
    public function index()
    {
        return Inertia::render('Policies/Exceptions/Index');
    }
}

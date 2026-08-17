<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class QuestionLibraryController extends Controller
{
    public function index()
    {
        return Inertia::render('Risks/QuestionLibrary/Index');
    }
}

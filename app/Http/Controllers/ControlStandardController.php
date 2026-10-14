<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Control Standards: for each policy, the controls that implement it and how effective they are.
 * A policy in force with no implementing control, or whose controls are failing, is a policy on
 * paper only; that is what this page is for.
 */
class ControlStandardController extends Controller
{
    public function index(Request $request)
    {
        $policies = Policy::query()->whereIn('status', ['active', 'approved', 'in_review'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->with(['controls' => fn ($q) => $q->select('controls.id', 'control_code', 'title', 'domain', 'effectiveness', 'status', 'last_tested')->orderBy('control_code')])
            ->orderBy('policy_code')->get(['id', 'policy_code', 'title', 'category', 'status', 'version_number']);

        $rows = $policies->map(function ($p) {
            $effective = $p->controls->where('effectiveness', 'effective')->count();
            $assessed = $p->controls->where('effectiveness', '!=', 'not_assessed')->count();

            return $p->toArray() + [
                'effective_count' => $effective,
                'failing_count' => $p->controls->where('effectiveness', 'ineffective')->count(),
                'coverage' => $p->controls->count() ? (int) round($effective / $p->controls->count() * 100) : null,
                'assessed' => $assessed,
            ];
        });

        return Inertia::render('Policies/ControlStandards/Index', [
            'policies' => $rows->values(),
            'totals' => [
                'policies' => $rows->count(),
                'unmapped' => $rows->where('controls', [])->count(),
                'with_failing' => $rows->where('failing_count', '>', 0)->count(),
                'controls' => $rows->sum(fn ($r) => count($r['controls'])),
            ],
            'categories' => Policy::CATEGORIES,
            'filters' => $request->only('category'),
            'canEdit' => $request->user()->can('edit policies'),
        ]);
    }
}

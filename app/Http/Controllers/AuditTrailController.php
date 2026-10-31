<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $query = Activity::with(['causer:id,name,email', 'subject'])
            ->latest();

        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }
        if ($request->filled('log_name')) {
            $query->where('log_name', $request->log_name);
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }
        if ($request->filled('search')) {
            $query->whereLike('description', '%'.$request->search.'%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $activities = $query->paginate(25)->withQueryString();

        if (! $request->user()->can('view it-finance-amounts')) {
            $activities->getCollection()->transform(function ($activity) {
                if ($activity->log_name === 'it-finance') {
                    $activity->properties = collect();
                    $activity->setRelation('subject', null);
                }

                return $activity;
            });
        }

        $users = User::where('organization_id', auth()->user()->organization_id)
            ->select('id', 'name')->get();

        $subjectTypes = Activity::distinct()->pluck('subject_type')
            ->map(fn ($type) => $type ? class_basename($type) : 'System')
            ->unique()->sort()->values();

        return Inertia::render('Settings/AuditTrail', [
            'activities' => $activities,
            'users' => $users,
            'subjectTypes' => $subjectTypes,
            'filters' => $request->only(['causer_id', 'log_name', 'subject_type', 'search', 'date_from', 'date_to']),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DataBreach;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DataBreachController extends Controller
{
    private const SORTABLE = ['created_at', 'breach_id_code', 'title', 'status', 'records_affected', 'discovered_at', 'ndpa_notification_deadline'];

    /** NDPA 2023 s.40: notify the NDPC within 72 hours of becoming aware of a personal data breach. */
    private function ndpaHours(): int
    {
        return (int) config('secops.notification_deadline_hours.NDPC', 72);
    }

    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $query = DataBreach::where('organization_id', $orgId)
            ->with(['incident:id,title,incident_id_code', 'assignee:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('breach_type')) {
            $query->where('breach_type', $request->breach_type);
        }
        if ($request->filled('ndpa_notification_required')) {
            $query->where('ndpa_notification_required', $request->boolean('ndpa_notification_required'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('breach_id_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $ndpa = $request->boolean('ndpa_pending') ? 'pending' : $request->get('ndpa');
        match ($ndpa) {
            'pending' => $query->where('ndpa_notification_required', true)->whereNull('ndpa_notified_at'),
            'notified' => $query->whereNotNull('ndpa_notified_at'),
            'not_required' => $query->where('ndpa_notification_required', false),
            default => null,
        };

        $sortField = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'created_at';
        $sortDir = $request->get('direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDir)->orderBy('id');

        $breaches = $query->paginate(15)->withQueryString();

        return Inertia::render('SecurityOps/Breaches/Index', [
            'breaches' => $breaches,
            'filters' => $request->only(['status', 'breach_type', 'ndpa_notification_required', 'search', 'sort', 'direction']) + ['ndpa' => $ndpa],
        ]);
    }

    public function create()
    {
        $orgId = auth()->user()->organization_id;

        return Inertia::render('SecurityOps/Breaches/Create', [
            'users' => User::where('organization_id', $orgId)
                ->select('id', 'name')->get(),
            'incidents' => Incident::where('organization_id', $orgId)
                ->select('id', 'title', 'incident_id_code')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $validated = $request->validate($this->rules($orgId));

        $validated['organization_id'] = $orgId;
        $validated['breach_id_code'] = DataBreach::generateNextCode($orgId);
        $validated['status'] = $validated['status'] ?? 'identified';
        // Awareness defaults to the linked incident's detection, else now.
        $validated['discovered_at'] = $validated['discovered_at']
            ?? (! empty($validated['incident_id']) ? Incident::find($validated['incident_id'])?->detected_at : null)
            ?? now();

        if (! empty($validated['ndpa_notification_required'])) {
            $validated['ndpa_notification_deadline'] = Carbon::parse($validated['discovered_at'])->addHours($this->ndpaHours());
        }

        $breach = DataBreach::create($validated);

        if ($breach->incident_id) {
            Incident::where('id', $breach->incident_id)->update(['is_data_breach' => true]);
        }

        return redirect()->route('data-breaches.show', $breach)
            ->with('success', "Data Breach {$breach->breach_id_code} created successfully.");
    }

    public function show(DataBreach $breach)
    {
        $breach->load([
            'incident:id,title,incident_id_code,severity,status',
            'assignee:id,name,email,job_title',
        ]);

        return Inertia::render('SecurityOps/Breaches/Show', [
            'breach' => $breach,
            'ndpcNotification' => $breach->incident_id
                ? IncidentNotification::where('incident_id', $breach->incident_id)->where('regulator_code', 'NDPC')
                    ->first(['id', 'delivery_status', 'deadline_at', 'submitted_at', 'submission_reference'])
                : null,
        ]);
    }

    public function edit(DataBreach $breach)
    {
        $orgId = auth()->user()->organization_id;

        return Inertia::render('SecurityOps/Breaches/Edit', [
            'breach' => $breach->load(['incident:id,title,incident_id_code', 'assignee:id,name']),
            'users' => User::where('organization_id', $orgId)
                ->select('id', 'name')->get(),
            'incidents' => Incident::where('organization_id', $orgId)
                ->select('id', 'title', 'incident_id_code')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function update(Request $request, DataBreach $breach)
    {
        $validated = $request->validate($this->rules(auth()->user()->organization_id));

        $discovered = Carbon::parse($validated['discovered_at'] ?? $breach->discovered_at ?? $breach->created_at);
        if (! empty($validated['ndpa_notification_required'])) {
            // The deadline always follows awareness, so correcting the discovery time moves the clock.
            $validated['ndpa_notification_deadline'] = $discovered->copy()->addHours($this->ndpaHours());
        } elseif (array_key_exists('ndpa_notification_required', $validated)) {
            $validated['ndpa_notification_deadline'] = null;
        }

        $breach->update($validated);

        if ($breach->incident_id) {
            Incident::where('id', $breach->incident_id)->update(['is_data_breach' => true]);
        }

        return redirect()->route('data-breaches.show', $breach)
            ->with('success', 'Data breach updated successfully.');
    }

    /** Record that the NDPC and/or the affected individuals have been notified. */
    public function recordNotification(Request $request, DataBreach $breach)
    {
        $validated = $request->validate([
            'party' => 'required|in:regulator,individuals',
            'notified_at' => 'nullable|date|before_or_equal:now',
        ]);
        $at = isset($validated['notified_at']) ? Carbon::parse($validated['notified_at']) : now();

        if ($validated['party'] === 'regulator') {
            $breach->update(['ndpa_notified_at' => $at, 'regulatory_body_notified' => true,
                'status' => in_array($breach->status, ['identified', 'investigating', 'contained'], true) ? 'notified' : $breach->status]);
            $late = $breach->ndpa_notification_deadline && $at->gt($breach->ndpa_notification_deadline);

            return back()->with($late ? 'warning' : 'success', $late
                ? 'NDPC notification recorded after the 72-hour deadline. Document the reasons for the delay (NDPA s.40(3)).'
                : 'NDPC notification recorded within the deadline.');
        }

        $breach->update(['individuals_notified' => true, 'individuals_notified_at' => $at]);

        return back()->with('success', 'Notification of affected individuals recorded.');
    }

    public function destroy(DataBreach $breach)
    {
        $breach->delete();

        return redirect()->route('data-breaches.index')
            ->with('success', "Data Breach {$breach->breach_id_code} deleted.");
    }

    private function rules(int $orgId): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'incident_id' => ['nullable', Rule::exists('incidents', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'breach_type' => 'nullable|in:confidentiality,integrity,availability',
            'data_types_affected' => 'nullable|array',
            'records_affected' => 'nullable|integer|min:0',
            'discovered_at' => 'nullable|date|before_or_equal:now',
            'status' => 'nullable|in:identified,investigating,contained,notified,resolved,closed',
            'ndpa_notification_required' => 'nullable|boolean',
            'regulatory_body_notified' => 'nullable|boolean',
            'individuals_notified' => 'nullable|boolean',
            'root_cause' => 'nullable|string',
            'remedial_actions' => 'nullable|string',
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
        ];
    }
}

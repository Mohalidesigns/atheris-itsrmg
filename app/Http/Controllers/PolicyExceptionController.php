<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\Risk;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Policies\PolicyMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Policy exceptions: time-bound, risk-accepted departures from a policy. Anyone who can view
 * policies may request one; a policy approver decides it, and the requester cannot approve their
 * own request. How long an exception may run depends on its risk rating
 * (PolicyException::MAX_MONTHS). Approved exceptions past their end date are marked expired by
 * the daily `policies:sweep` and shown as expired at once.
 */
class PolicyExceptionController extends Controller
{
    public function index(Request $request)
    {
        $query = PolicyException::query()->with(['policy:id,policy_code,title', 'control:id,control_code,title', 'requester:id,name', 'owner:id,name', 'decider:id,name']);

        if ($request->filled('status')) {
            $request->input('status') === 'expired'
                ? $query->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($q) => $q->where('status', 'approved')->whereDate('expires_on', '<', today())))
                : $query->where('status', $request->input('status'));
        }
        if ($request->filled('policy_id')) {
            $query->where('policy_id', $request->integer('policy_id'));
        }
        if ($request->input('flag') === 'expiring') {
            $query->where('status', 'approved')->whereDate('expires_on', '>=', today())->whereDate('expires_on', '<=', today()->addDays(30));
        }

        $user = $request->user();

        return Inertia::render('Policies/Exceptions/Index', [
            'exceptions' => $query->orderByRaw("case status when 'requested' then 0 when 'approved' then 1 else 2 end")->orderBy('expires_on')->paginate(20)->withQueryString(),
            'metrics' => app(PolicyMetrics::class)->summary(),
            'filters' => $request->only(['status', 'policy_id', 'flag']),
            'policies' => Policy::query()->whereIn('status', ['active', 'approved'])->orderBy('policy_code')->get(['id', 'policy_code', 'title']),
            'controls' => Control::query()->orderBy('control_code')->get(['id', 'control_code', 'title']),
            'risks' => Risk::query()->orderBy('risk_id_code')->get(['id', 'risk_id_code', 'title']),
            'users' => User::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name']),
            'statuses' => PolicyException::STATUSES,
            'riskRatings' => PolicyException::RISK_RATINGS,
            'maxMonths' => PolicyException::MAX_MONTHS,
            'can' => ['decide' => $user->can('approve policies'), 'request' => true],
            'me' => $user->id,
        ]);
    }

    public function store(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $data = $request->validate([
            'policy_id' => ['required', 'integer', Rule::exists('policies', 'id')->where('organization_id', $orgId)->whereIn('status', ['active', 'approved'])],
            'control_id' => ['nullable', 'integer', Rule::exists('controls', 'id')->where('organization_id', $orgId)],
            'risk_id' => ['nullable', 'integer', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'title' => 'required|string|max:255',
            'scope' => 'nullable|string|max:255',
            'justification' => 'required|string|min:20',
            'compensating_controls' => 'nullable|string',
            'risk_rating' => ['required', Rule::in(PolicyException::RISK_RATINGS)],
            'starts_on' => 'nullable|date',
            'expires_on' => 'required|date|after:today',
        ], ['policy_id.exists' => 'Exceptions can only be raised against an approved or active policy of this bank.']);

        $this->checkDuration($data['risk_rating'], $data['starts_on'] ?? null, $data['expires_on']);
        if (in_array($data['risk_rating'], ['critical', 'high'], true) && blank($data['compensating_controls'] ?? null)) {
            throw ValidationException::withMessages(['compensating_controls' => 'A high or critical exception needs compensating controls.']);
        }

        app(ApprovalEngine::class)->assertCanInitiate('policy.exception', $request->user());
        $exception = PolicyException::create($data + [
            'organization_id' => $orgId,
            'exception_code' => PolicyException::generateNextCode($orgId),
            'status' => 'requested',
            'requested_by' => $request->user()->id,
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
        ]);

        app(ApprovalEngine::class)->start('policy.exception', $exception, $request->user());

        return back()->with('success', "Exception {$exception->exception_code} requested.");
    }

    /** approve | reject | revoke */
    public function decide(Request $request, PolicyException $exception)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'revoke'])],
            'notes' => 'required_unless:decision,approve|nullable|string|max:2000',
            'expires_on' => 'nullable|date|after:today',
        ]);
        $user = $request->user();

        if ($data['decision'] === 'revoke') {
            abort_unless($exception->status === 'approved', 422, 'Only an approved exception can be revoked.');
        } else {
            abort_unless($exception->status === 'requested', 422, 'This exception has already been decided.');
            if ($user->id === (int) $exception->requested_by) {
                throw ValidationException::withMessages(['decision' => 'You cannot decide an exception you requested.']);
            }
        }
        if ($data['decision'] === 'approve' && ! empty($data['expires_on'])) {
            // The approver may shorten the term, within the rating's limit.
            $this->checkDuration($exception->risk_rating, $exception->starts_on?->toDateString(), $data['expires_on']);
        }

        // Workflow Studio stages (ATH-VAL-016): an approval stays "requested" until the last stage.
        $approvals = app(ApprovalEngine::class);
        if ($data['decision'] === 'approve' && ($pending = $approvals->gate('policy.exception', $exception, $user, $data['notes'] ?? null))) {
            return back()->with('success', "Exception {$exception->exception_code}: {$pending}");
        }
        if ($data['decision'] === 'reject') {
            $approvals->reject('policy.exception', $exception, $user, $data['notes']);
        }

        $exception->update([
            'status' => ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'][$data['decision']],
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_notes' => $data['notes'] ?? null,
            'expires_on' => $data['decision'] === 'approve' && ! empty($data['expires_on']) ? $data['expires_on'] : $exception->expires_on,
            'starts_on' => $data['decision'] === 'approve' ? ($exception->starts_on ?? today()) : $exception->starts_on,
        ]);

        return back()->with('success', "Exception {$exception->exception_code} {$exception->status}.");
    }

    private function checkDuration(string $rating, ?string $startsOn, string $expiresOn): void
    {
        $start = $startsOn ? Carbon::parse($startsOn) : today();
        $limit = $start->copy()->addMonths(PolicyException::MAX_MONTHS[$rating]);
        if (Carbon::parse($expiresOn)->gt($limit)) {
            throw ValidationException::withMessages(['expires_on' => "A {$rating} exception may run for at most ".PolicyException::MAX_MONTHS[$rating]." months (until {$limit->format('d M Y')})."]);
        }
    }
}

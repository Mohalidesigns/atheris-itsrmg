<?php

namespace App\Http\Controllers;

use App\Models\Change\ChangeRequest;
use App\Models\Issue;
use App\Models\ItsmConnection;
use App\Models\User;
use App\Services\Issues\CapaMetrics;
use App\Services\Issues\CapaPresenter;
use App\Services\Issues\CapaWorkflow;
use App\Services\Issues\IssueMetrics;
use App\Services\Issues\IssueWorkflow;
use App\Services\Issues\ItsmConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Issues & Remediation: the unified CAPA register, its SLA policies and the ITSM connectors.
 * These were read-only PlatformController stubs; see docs/ATH-VAL-006.
 */
class IssueController extends Controller
{
    private const SORTS = ['sla_due_at', 'severity', 'created_at', 'issue_code', 'status'];

    public function __construct(private IssueWorkflow $workflow, private CapaWorkflow $capa, private CapaPresenter $capaPresenter) {}

    /* ================================ Console ================================ */

    public function index(Request $r, IssueMetrics $metrics, CapaMetrics $capaMetrics)
    {
        $filters = $r->only(['status', 'severity', 'source', 'owner', 'q', 'view', 'sort', 'dir', 'framework', 'stage']);
        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'sla_due_at';
        $dir = ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = Issue::query()->with('owner:id,name')
            ->when($filters['status'] ?? null, function ($q, $status) {
                match ($status) {
                    'overdue' => $q->overdue(),
                    'escalated' => $q->whereIn('status', Issue::OPEN_STATUSES)->where('escalation_level', '>', 0),
                    'active' => $q->whereIn('status', Issue::OPEN_STATUSES),
                    default => $q->where('status', $status),
                };
            })
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source_type', $s))
            // CAPA filters: framework=any|iso27001|cobit2019|itilv4|none, stage=<capa stage>.
            ->when($filters['framework'] ?? null, fn ($q, $f) => match ($f) {
                'any' => $q->whereNotNull('capa_framework'),
                'none' => $q->whereNull('capa_framework'),
                default => $q->where('capa_framework', $f),
            })
            ->when($filters['stage'] ?? null, fn ($q, $s) => $q->whereNotNull('capa_framework')->where('capa_stage', $s))
            ->when(($filters['owner'] ?? null) === 'me', fn ($q) => $q->where('owner_id', $r->user()->id))
            ->when(($filters['owner'] ?? null) === 'none', fn ($q) => $q->whereNull('owner_id'))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereLike('title', "%{$term}%")->orWhereLike('issue_code', "%{$term}%")
                ->orWhereLike('external_ticket_id', "%{$term}%")));

        $query = $sort === 'severity'
            ? $query->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'moderate' then 3 else 4 end ".$dir)
            : $query->orderBy($sort, $dir);

        $issues = $query->orderBy('id')->get()->map(fn (Issue $i) => [
            'id' => $i->id,
            'issue_code' => $i->issue_code,
            'title' => $i->title,
            'severity' => $i->severity,
            'status' => $i->status,
            'source_type' => $i->source_type,
            'source_label' => Issue::SOURCES[$i->source_type]['label'] ?? $i->source_type,
            'owner' => $i->owner?->name,
            'sla_due_at' => $i->sla_due_at?->toIso8601String(),
            'sla_state' => $i->slaState(),
            'escalation_level' => $i->escalation_level,
            'external_ticket_id' => $i->external_ticket_id,
            'capa_framework' => $i->capa_framework,
            'capa_stage' => $i->capa_framework ? $i->capa_stage : null,
            'verification_due_at' => $i->verification_due_at?->toIso8601String(),
        ]);

        return Inertia::render('Issues/Index', [
            'issues' => $issues,
            'counts' => Issue::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'stats' => $metrics->summary(),
            'filters' => $filters,
            'sources' => collect(Issue::SOURCES)->map(fn ($s) => $s['label']),
            'owners' => $this->owners(),
            'sourceRecords' => Inertia::optional(fn () => $this->sourceRecords()),
            'capaKpis' => $capaMetrics->summary(),
            'capaConfig' => $this->capaPresenter->config(),
        ]);
    }

    public function show(Issue $issue)
    {
        $issue->load(['owner:id,name,job_title', 'creator:id,name', 'remediator:id,name', 'verifier:id,name', 'itsmConnection:id,name,system,mode',
            'events' => fn ($q) => $q->with('actor:id,name')]);
        $user = request()->user();
        $chain = $this->workflow->escalationChain($issue->organization_id, $issue->severity);

        return Inertia::render('Issues/Show', [
            'issue' => $issue,
            'source' => $issue->sourceSummary(),
            'slaState' => $issue->slaState(),
            'slaHours' => round($issue->sla_minutes / 60, 1),
            'chain' => $chain,
            'nextEscalation' => $chain[$issue->escalation_level + 1] ?? null,
            'canWork' => $this->canWork($issue, $user),
            'canApprove' => $user->can('approve issues'),
            'owners' => $this->owners(),
            'connections' => ItsmConnection::where('status', '!=', 'disabled')->orderBy('name')->get(['id', 'name', 'system', 'mode']),
            'sources' => collect(Issue::SOURCES)->map(fn ($s) => $s['label']),
            'capaConfig' => $this->capaPresenter->config(),
            // Change requests a corrective action can be routed through (loaded on demand).
            'changeRequests' => Inertia::optional(fn () => ChangeRequest::whereNotIn('status', ['closed', 'cancelled', 'rejected'])
                ->orderByDesc('id')->limit(200)->get(['id', 'change_code', 'title', 'status'])),
        ] + $this->capaPresenter->forIssue($issue, $user));
    }

    public function store(Request $r, ItsmConnector $itsm)
    {
        $data = $this->validated($r, true);
        $capa = $r->validate([
            'capa_framework' => ['nullable', Rule::in(array_keys(config('capa.frameworks')))],
            'capa_classification' => 'nullable|string|max:32',
            'framework_reference' => 'nullable|string|max:64',
            'cbn_provisions' => 'nullable|array',
            'cbn_provisions.*' => ['string', Rule::in(array_keys(config('capa.cbn_provisions')))],
            'settling_days' => 'nullable|integer',
            // A recurrence of an earlier CAPA in this bank (IT-GOV-CAPA-001 §6 recurrence rate).
            'recurrence_of_id' => ['nullable', Rule::exists('issues', 'id')->where('organization_id', $r->user()->organization_id)],
        ]);
        $issue = DB::transaction(function () use ($data, $capa, $r) {
            $issue = $this->workflow->open($data + array_filter(['recurrence_of_id' => $capa['recurrence_of_id'] ?? null]), $r->user());
            if (! empty($capa['capa_framework'])) {
                $this->capa->classify($issue, array_diff_key($capa, ['recurrence_of_id' => 1]), $r->user());
            }

            return $issue;
        });

        $auto = ItsmConnection::whereNotNull('auto_push_severity')->where('status', 'active')->get()
            ->first(fn ($c) => $this->severityRank($issue->severity) <= $this->severityRank($c->auto_push_severity));
        $note = '';
        if ($auto) {
            try {
                $note = ' Ticket '.$itsm->push($issue, $auto, $r->user()).' raised in '.$auto->name.'.';
            } catch (\RuntimeException $e) {
                $note = ' Automatic ticket push failed: '.$e->getMessage();
            }
        }

        return redirect()->route('issues.show', $issue)->with('success', "Issue {$issue->issue_code} raised.{$note}");
    }

    public function update(Request $r, Issue $issue)
    {
        abort_unless($this->canWork($issue, $r->user()), 403);
        if ($issue->status === 'closed') {
            return back()->with('error', 'A closed issue is locked. Reopen it to edit.');
        }
        $data = $this->validated($r, false);
        $severity = $data['severity'];
        unset($data['severity']);

        $current = fn ($k) => $issue->{$k} instanceof \DateTimeInterface ? $issue->{$k}->toDateString() : $issue->{$k};
        $changed = collect($data)->keys()->filter(fn ($k) => (string) $current($k) !== (string) ($data[$k] ?? ''));

        if ($changed->contains('owner_id')) {
            $this->workflow->log($issue, 'assigned', 'Owner set to '.(User::find($data['owner_id'])?->name ?? 'nobody').'.', $r->user());
        }
        $issue->update($data);
        $this->workflow->changeSeverity($issue, $severity, $r->user());
        $this->capa->notifyCritical($issue->fresh());
        $fields = $changed->reject(fn ($k) => $k === 'owner_id');
        if ($fields->isNotEmpty()) {
            $this->workflow->log($issue, 'updated', 'Updated '.$fields->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ').'.', $r->user());
        }

        return back()->with('success', 'Issue updated.');
    }

    public function transition(Request $r, Issue $issue)
    {
        abort_unless($this->canWork($issue, $r->user()) || $r->user()->can('approve issues'), 403);
        $data = $r->validate([
            'action' => ['required', Rule::in(array_keys(IssueWorkflow::TRANSITIONS))],
            'note' => 'nullable|string|max:2000',
        ]);

        return back()->with('success', $this->workflow->transition($issue, $data['action'], $r->user(), $data));
    }

    public function escalate(Request $r, Issue $issue)
    {
        abort_unless($this->canWork($issue, $r->user()), 403);
        $data = $r->validate(['note' => 'required|string|max:1000']);

        return $this->workflow->escalate($issue, $r->user(), $data['note'])
            ? back()->with('success', 'Escalated; the next level of the chain has been notified.')
            : back()->with('error', 'This issue cannot be escalated further.');
    }

    public function comment(Request $r, Issue $issue)
    {
        $data = $r->validate(['note' => 'required|string|max:2000']);
        $this->workflow->log($issue, 'comment', $data['note'], $r->user());

        return back()->with('success', 'Comment added.');
    }

    public function push(Request $r, Issue $issue, ItsmConnector $itsm)
    {
        abort_unless($this->canWork($issue, $r->user()), 403);
        $data = $r->validate(['connection_id' => ['required', Rule::exists('itsm_connections', 'id')->where('organization_id', $r->user()->organization_id)]]);

        try {
            $reference = $itsm->push($issue, ItsmConnection::findOrFail($data['connection_id']), $r->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Ticket {$reference} created.");
    }

    /* ============================== SLA policies ============================== */

    public function slaPolicies(Request $r)
    {
        $orgId = $r->user()->organization_id;
        $rows = DB::table('vulnerability_sla_policies')->where('organization_id', $orgId)->get()->keyBy('severity');
        $openBySeverity = Issue::whereIn('status', Issue::OPEN_STATUSES)->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity');
        $overdueBySeverity = Issue::overdue()->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity');

        $policies = collect(['critical', 'high', 'medium', 'low'])->map(function ($severity) use ($rows, $orgId, $openBySeverity, $overdueBySeverity) {
            $issueSeverity = array_search($severity, Issue::POLICY_SEVERITY, true);

            return [
                'severity' => $severity,
                'issue_severity' => $issueSeverity,
                'hours_to_remediate' => (int) ($rows[$severity]->hours_to_remediate ?? IssueWorkflow::DEFAULT_SLA_HOURS[$severity]),
                'chain' => $this->workflow->escalationChain($orgId, $issueSeverity),
                'is_default' => ! isset($rows[$severity]),
                'open' => (int) ($openBySeverity[$issueSeverity] ?? 0),
                'overdue' => (int) ($overdueBySeverity[$issueSeverity] ?? 0),
                'updated_at' => $rows[$severity]->updated_at ?? null,
            ];
        });

        return Inertia::render('Issues/SlaPolicies', ['policies' => $policies]);
    }

    /** Changing a policy applies to issues raised from now on; open issues keep the clock they were given. */
    public function slaPolicyUpdate(Request $r, string $severity)
    {
        abort_unless(in_array($severity, ['critical', 'high', 'medium', 'low'], true), 404);
        $data = $r->validate([
            'hours_to_remediate' => 'required|integer|min:1|max:8760',
            'chain' => 'required|array|min:1|max:4',
            'chain.*' => ['required', Rule::in(['owner', 'CISO', 'CRO', 'CCO', 'CIO'])],
        ]);
        $chain = array_values(array_unique($data['chain']));
        if ($chain[0] !== 'owner') {
            array_unshift($chain, 'owner');
            $chain = array_values(array_unique($chain));
        }

        DB::table('vulnerability_sla_policies')->updateOrInsert(
            ['organization_id' => $r->user()->organization_id, 'severity' => $severity],
            ['hours_to_remediate' => $data['hours_to_remediate'], 'escalation_rules' => json_encode(['chain' => $chain]), 'updated_at' => now(), 'created_at' => now()],
        );
        activity()->causedBy($r->user())->withProperties(['severity' => $severity, 'hours' => $data['hours_to_remediate'], 'chain' => $chain])
            ->log("SLA policy for {$severity} changed");

        return back()->with('success', ucfirst($severity).' SLA policy saved. It applies to issues raised from now on.');
    }

    /* ================================== ITSM ================================== */

    public function itsm(Request $r)
    {
        $connections = ItsmConnection::withCount([
            'issues',
            'issues as open_issues_count' => fn ($q) => $q->whereIn('status', Issue::OPEN_STATUSES),
        ])->orderBy('name')->get()->map(fn (ItsmConnection $c) => [
            'id' => $c->id, 'name' => $c->name, 'system' => $c->system, 'system_label' => $c->label(),
            'base_url' => $c->base_url, 'project_key' => $c->project_key, 'username' => $c->username,
            'mode' => $c->mode, 'status' => $c->status, 'auto_push_severity' => $c->auto_push_severity,
            'has_token' => filled($c->api_token), 'has_secret' => filled($c->webhook_secret),
            'last_tested_at' => $c->last_tested_at, 'last_test_ok' => $c->last_test_ok, 'last_test_message' => $c->last_test_message,
            'last_synced_at' => $c->last_synced_at, 'issues_count' => $c->issues_count, 'open_issues_count' => $c->open_issues_count,
            'webhook_url' => route('itsm.webhook', $c->id),
        ]);

        $tickets = Issue::whereNotNull('itsm_connection_id')->with('itsmConnection:id,name')
            ->orderByDesc('ticket_synced_at')->limit(25)
            ->get(['id', 'issue_code', 'title', 'status', 'severity', 'itsm_connection_id', 'external_ticket_id', 'external_ticket_url', 'external_ticket_status', 'ticket_synced_at']);

        return Inertia::render('Issues/Itsm', [
            'connections' => $connections,
            'tickets' => $tickets,
            'systems' => ItsmConnection::SYSTEMS,
            'unticketed' => Issue::whereIn('status', Issue::OPEN_STATUSES)->whereNull('external_ticket_id')->whereIn('severity', ['critical', 'high'])->count(),
            'newSecret' => session('itsm_secret'),
        ]);
    }

    public function itsmStore(Request $r)
    {
        $connection = ItsmConnection::create($this->validatedConnection($r, null) + ['organization_id' => $r->user()->organization_id]);

        return back()->with('success', "{$connection->name} added. Issue a webhook secret to receive status updates.");
    }

    public function itsmUpdate(Request $r, ItsmConnection $connection)
    {
        $connection->update($this->validatedConnection($r, $connection));

        return back()->with('success', "{$connection->name} saved.");
    }

    public function itsmTest(ItsmConnection $connection, ItsmConnector $itsm)
    {
        $result = $itsm->test($connection);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function itsmRotateSecret(ItsmConnection $connection)
    {
        $secret = Str::random(48);
        $connection->forceFill(['webhook_secret' => $secret])->save();

        return back()->with('itsm_secret', ['connection_id' => $connection->id, 'secret' => $secret])
            ->with('success', 'New webhook secret issued. Copy it now: it will not be shown again.');
    }

    /** Public, HMAC-authenticated status mirror (outside auth/CSRF). */
    public function itsmWebhook(Request $r, int $connection, ItsmConnector $itsm): JsonResponse
    {
        $connector = ItsmConnection::withoutGlobalScopes()->find($connection);
        $raw = $r->getContent();
        if (! $connector || $connector->status === 'disabled'
            || ! $itsm->verifySignature($connector, $raw, $r->header('X-Atheris-Timestamp'), $r->header('X-Atheris-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'payload must be a JSON object'], 422);
        }

        $result = $itsm->applyWebhook($connector, $payload);
        if (! $result['issue']) {
            return response()->json(['error' => 'no issue is linked to that ticket'], 404);
        }

        return response()->json([
            'issue' => $result['issue']->issue_code,
            'status' => $result['issue']->status,
            'changed' => $result['changed'],
        ]);
    }

    /* ================================ Helpers ================================ */

    /** Owners work their own issues; anyone with edit issues can work any issue in the bank. */
    private function canWork(Issue $issue, User $user): bool
    {
        return $user->can('edit issues') || $issue->owner_id === $user->id;
    }

    private function validated(Request $r, bool $creating): array
    {
        $orgId = $r->user()->organization_id;
        $data = $r->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'severity' => ['required', Rule::in(Issue::SEVERITIES)],
            'source_type' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(Issue::SOURCES))],
            'source_id' => 'nullable|integer',
            'source_reference' => 'nullable|string|max:255',
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'due_date' => 'nullable|date',
            'root_cause' => 'nullable|string|max:5000',
            'remediation_plan' => 'nullable|string|max:5000',
        ]);

        // A cited source record must exist inside this bank (tenant scopes apply to find()).
        $type = $data['source_type'] ?? null;
        $model = $type ? (Issue::SOURCES[$type]['model'] ?? null) : null;
        if (! empty($data['source_id']) && (! $model || ! $model::find($data['source_id']))) {
            throw ValidationException::withMessages(['source_id' => 'The source record was not found in this bank.']);
        }
        if ($creating && empty($data['source_id']) && blank($data['source_reference'] ?? null)) {
            throw ValidationException::withMessages(['source_reference' => 'Pick the source record or cite the report or finding this issue comes from.']);
        }
        if (! $creating) {
            unset($data['source_type'], $data['source_id']);
        }

        return $data;
    }

    private function validatedConnection(Request $r, ?ItsmConnection $existing): array
    {
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'system' => ['required', Rule::in(array_keys(ItsmConnection::SYSTEMS))],
            'base_url' => 'required|url:https|max:255',
            'project_key' => 'nullable|required_if:system,jira|string|max:64',
            'username' => 'nullable|string|max:255',
            'api_token' => 'nullable|string|max:500',
            'mode' => ['required', Rule::in(ItsmConnection::MODES)],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'auto_push_severity' => ['nullable', Rule::in(Issue::SEVERITIES)],
        ]);
        // A blank token on edit keeps the stored one; it is never sent back to the browser.
        if ($existing && blank($data['api_token'] ?? null)) {
            unset($data['api_token']);
        }
        if ($data['mode'] === 'live' && ! $existing?->api_token && blank($data['api_token'] ?? null)) {
            throw ValidationException::withMessages(['api_token' => 'A live connector needs an API token.']);
        }

        return $data;
    }

    private function owners()
    {
        return User::where('organization_id', request()->user()->organization_id)->orderBy('name')->get(['id', 'name', 'job_title']);
    }

    /** Pickable source records per type, for the "raise issue" form. */
    private function sourceRecords(): array
    {
        return collect(Issue::SOURCES)->filter(fn ($s) => $s['model'])
            ->map(fn ($s) => $s['model']::query()->with($s['with'] ?? [])->orderBy($s['order'] ?? $s['code'])->limit(400)
                ->get(Issue::sourceColumns($s))
                ->map(fn ($m) => ['id' => $m->id, 'label' => $m->{$s['code']}.' · '.Str::limit((string) $m->{$s['title'] ?? 'title'}, 70)]))
            ->all();
    }

    private function severityRank(string $severity): int
    {
        return array_search($severity, Issue::SEVERITIES, true);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\ItsmConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Issues\IssueWorkflow;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues & Remediation demo data (ATH-VAL-006). Runs after SecurityOperationsDemoSeeder.
 *
 * The bank seeders raise issues with random source ids (First Bank's pointed at Kano's risks),
 * random SLA minutes, every issue "raised" at seed time and no lifecycle trail. This seeder makes
 * each bank's register coherent, deterministically (mt_srand):
 * - every source resolves to a record of that type inside the same bank; audit and NDPA findings
 *   cite a report reference instead; First Bank's numbered titles become titles of their source;
 * - raised dates spread by status, SLA due times from the bank's policy, remediation mostly but
 *   not always within SLA, a verifier who is never the remediator;
 * - blocked issues with a reason and a paused clock; overdue issues escalated to the level the
 *   policy dictates; two open issues per bank left unassigned (deliberate gaps);
 * - a timeline for every issue; ITSM connectors per bank with a share of issues ticketed and the
 *   ticket status matching the issue.
 */
class IssuesDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['prefix' => 'KHB-ISS-', 'admin' => 'admin@kanoheritage.ng', 'seed' => 6102],
        'first-bank-nigeria' => ['prefix' => 'FBN-ISS-', 'admin' => 'ciso@firstbanknigeria.ng', 'seed' => 6103],
    ];

    /** Kano's register had 15 issues against a seeded spec of 25; these fill it from real findings. */
    private const KANO_TOPUP = [
        ['vulnerability', 'critical', 'in_progress'], ['vulnerability', 'high', 'open'], ['incident', 'high', 'remediated'],
        ['assessment', 'high', 'in_progress'], ['assessment', 'moderate', 'open'], ['risk', 'high', 'blocked'],
        ['control_test', 'moderate', 'verified'], ['risk', 'critical', 'in_progress'], ['incident', 'moderate', 'closed'],
        ['control_test', 'low', 'open'],
    ];

    /** Days before now an issue in each status was raised: [min, max]. */
    private const AGE = [
        'open' => [1, 25], 'in_progress' => [4, 70], 'blocked' => [10, 60],
        'remediated' => [15, 90], 'verified' => [25, 120], 'closed' => [40, 220],
    ];

    private const BLOCKED_REASONS = [
        'Waiting on the vendor patch (OEM ticket open).',
        'Change freeze for month-end close; change request CAB-approved for next window.',
        'Needs budget approval for the replacement appliance.',
        'Depends on the Finacle upgrade go-live.',
        'Awaiting confirmation from the third party that the fix is deployed on their side.',
    ];

    private const AUDIT_REFS = ['Internal Audit report IA-2026-07, finding %d', 'External audit (KPMG) management letter 2025, point %d', 'CBN examination report 2025, observation %d'];

    private const NDPA_REFS = ['NDPC compliance audit return 2025, observation %d', 'DPIA review DP-2026-%02d', 'DPCO quarterly privacy review, item %d'];

    private const TICKET_STATUS = [
        'jira' => ['open' => 'To Do', 'in_progress' => 'In Progress', 'blocked' => 'Blocked', 'remediated' => 'Done', 'verified' => 'Done', 'closed' => 'Closed'],
        'servicenow' => ['open' => 'New', 'in_progress' => 'In Progress', 'blocked' => 'On Hold', 'remediated' => 'Resolved', 'verified' => 'Resolved', 'closed' => 'Closed'],
        'freshservice' => ['open' => 'Open', 'in_progress' => 'Pending', 'blocked' => 'Pending', 'remediated' => 'Resolved', 'verified' => 'Resolved', 'closed' => 'Closed'],
    ];

    private IssueWorkflow $workflow;

    public function run(): void
    {
        $this->workflow = app(IssueWorkflow::class);

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin) {
                continue;
            }
            Auth::setUser($admin);
            mt_srand($bank['seed']);

            $this->policies($org->id, $slug);
            if ($slug === 'kano-heritage-bank') {
                $this->kanoTopUp($org->id);
            }
            $connections = $this->connections($org->id, $slug);
            $this->normalise($org->id, $bank['prefix'], $connections);
        }

        mt_srand();
        Auth::forgetUser();
    }

    /** Bank-specific chains: First Bank stops high-severity escalation at the CISO. */
    private function policies(int $orgId, string $slug): void
    {
        if ($slug === 'first-bank-nigeria') {
            DB::table('vulnerability_sla_policies')->where('organization_id', $orgId)->where('severity', 'high')
                ->update(['escalation_rules' => json_encode(['chain' => ['owner', 'CISO']])]);
            DB::table('vulnerability_sla_policies')->where('organization_id', $orgId)->where('severity', 'critical')
                ->update(['escalation_rules' => json_encode(['chain' => ['owner', 'CISO', 'CRO']])]);
        }
    }

    private function kanoTopUp(int $orgId): void
    {
        if (Issue::where('organization_id', $orgId)->count() >= 25) {
            return;
        }
        foreach (self::KANO_TOPUP as [$type, $severity, $status]) {
            Issue::create([
                'organization_id' => $orgId, 'source_type' => $type, 'severity' => $severity, 'status' => $status,
                'title' => 'pending', 'sla_minutes' => 0,
            ]);
        }
    }

    /** @return array<int, ItsmConnection> */
    private function connections(int $orgId, string $slug): array
    {
        $defs = $slug === 'kano-heritage-bank'
            ? [
                ['name' => 'IT Service Desk (Jira)', 'system' => 'jira', 'base_url' => 'https://kanoheritage.atlassian.net', 'project_key' => 'KHB', 'username' => 'grc-integration@kanoheritage.ng', 'auto_push_severity' => 'high', 'status' => 'active'],
                ['name' => 'Freshservice (Branch IT)', 'system' => 'freshservice', 'base_url' => 'https://kanoheritage.freshservice.com', 'project_key' => null, 'username' => null, 'auto_push_severity' => null, 'status' => 'disabled'],
            ]
            : [
                ['name' => 'Group IT ServiceNow', 'system' => 'servicenow', 'base_url' => 'https://firstbank.service-now.com', 'project_key' => null, 'username' => 'svc_atheris', 'auto_push_severity' => 'critical', 'status' => 'active'],
                ['name' => 'Digital Channels Jira', 'system' => 'jira', 'base_url' => 'https://fbn-digital.atlassian.net', 'project_key' => 'DIG', 'username' => 'grc-bot@firstbanknigeria.ng', 'auto_push_severity' => null, 'status' => 'active'],
            ];

        $out = [];
        foreach ($defs as $def) {
            $connection = ItsmConnection::where('organization_id', $orgId)->where('name', $def['name'])->first()
                ?? new ItsmConnection(['organization_id' => $orgId, 'name' => $def['name']]);
            $connection->fill($def + ['mode' => 'sandbox'])->forceFill([
                'webhook_secret' => $connection->webhook_secret ?: Str::random(48),
                'last_tested_at' => now()->subDays(mt_rand(1, 6)),
                'last_test_ok' => true,
                'last_test_message' => 'Sandbox connector: no call made. Tickets are recorded locally.',
            ])->save();
            $out[] = $connection;
        }

        return $out;
    }

    private function normalise(int $orgId, string $prefix, array $connections): void
    {
        $users = User::where('organization_id', $orgId)->orderBy('id')->get();
        $verifiers = $users->filter(fn (User $u) => $u->can('approve issues') && ! $u->hasRole('Super Admin'))->values();
        $owners = $users->reject(fn (User $u) => $u->hasRole('Super Admin'))->values();
        $records = $this->records($orgId);
        $cursor = [];
        $activeConnections = array_values(array_filter($connections, fn ($c) => $c->status === 'active'));
        $ticketCounter = [];
        $unassigned = 0;

        $issues = Issue::where('organization_id', $orgId)->orderBy('id')->get();
        IssueEvent::whereIn('issue_id', $issues->pluck('id'))->delete();

        foreach ($issues->values() as $n => $issue) {
            $status = $issue->status === 'escalated' ? 'in_progress' : $issue->status;

            // Source: a record of the declared type in this bank, or a cited report.
            $type = $issue->source_type;
            $sourceId = null;
            $reference = null;
            if (isset($records[$type]) && $records[$type]->isNotEmpty()) {
                $pool = $records[$type];
                $own = $issue->source_id ? $pool->firstWhere('id', $issue->source_id) : null;
                $record = $own ?? $pool[($cursor[$type] = ($cursor[$type] ?? -1) + 1) % $pool->count()];
                $sourceId = $record->id;
            } elseif (in_array($type, ['audit', 'ndpa_finding'], true)) {
                $refs = $type === 'audit' ? self::AUDIT_REFS : self::NDPA_REFS;
                $reference = sprintf($refs[$n % count($refs)], ($n % 11) + 1);
            } else {
                $type = 'audit';
                $reference = sprintf(self::AUDIT_REFS[0], ($n % 11) + 1);
            }
            $title = $this->title($issue->title, $type, $sourceId ? $records[$type]->firstWhere('id', $sourceId) : null);

            // Timeline anchors.
            [$min, $max] = self::AGE[$status];
            $raised = now()->subDays(mt_rand($min, $max))->setTime(mt_rand(8, 17), mt_rand(0, 59));
            $hours = $this->workflow->slaHours($orgId, $issue->severity);
            $owner = $status === 'open' && $unassigned < 2 && $issue->severity !== 'critical'
                ? null : $owners[$n % $owners->count()];
            if (! $owner) {
                $unassigned++;
            }
            $creator = $owners[($n * 7 + 3) % $owners->count()];

            $attrs = [
                'issue_code' => $prefix.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                'title' => $title,
                'description' => $issue->description && ! str_contains($issue->description, 'Demo remediation entry') && ! str_contains($issue->description, 'CAPA item')
                    ? $issue->description : $this->description($title, $type),
                'source_type' => $type, 'source_id' => $sourceId, 'source_reference' => $reference,
                'status' => $status, 'owner_id' => $owner?->id, 'created_by_id' => $creator->id,
                'sla_minutes' => $hours * 60, 'sla_paused_minutes' => 0,
                'sla_due_at' => $raised->copy()->addHours($hours), 'sla_paused_at' => null, 'blocked_reason' => null,
                'escalation_level' => 0, 'escalated_at' => null,
                'remediated_at' => null, 'remediated_by_id' => null, 'verified_at' => null, 'verified_by_id' => null, 'closed_at' => null,
                'root_cause' => $status === 'open' ? null : ($issue->root_cause && ! str_contains($issue->root_cause, '(demo)') && ! str_contains($issue->root_cause, 'Identified during') ? $issue->root_cause : $this->rootCause($type)),
                'remediation_plan' => $status === 'open' && mt_rand(0, 1) ? null : $this->plan($type),
                'itsm_connection_id' => null, 'external_ticket_id' => null, 'external_ticket_system' => null,
                'external_ticket_url' => null, 'external_ticket_status' => null, 'ticket_synced_at' => null,
                'created_at' => $raised, 'updated_at' => $raised,
            ];
            $events = [[$raised, 'created', 'Issue raised ('.ucfirst($issue->severity).', '.$this->workflow->formatHours($hours).' SLA).', $creator->id]];
            if ($owner) {
                $events[] = [$raised->copy()->addHours(2), 'assigned', "Owner set to {$owner->name}.", $creator->id];
            }

            if ($status !== 'open') {
                $started = $raised->copy()->addHours(mt_rand(4, 48));
                $events[] = [$started, 'status', 'Work started.', $owner?->id];
            }
            if ($status === 'blocked') {
                $pausedAt = now()->subDays(mt_rand(2, 9));
                $reason = self::BLOCKED_REASONS[$n % count(self::BLOCKED_REASONS)];
                $attrs['blocked_reason'] = $reason;
                $attrs['sla_paused_at'] = $pausedAt;
                $events[] = [$pausedAt, 'status', "Blocked: {$reason} The SLA clock is paused.", $owner?->id];
            }
            if (in_array($status, Issue::DONE_STATUSES, true)) {
                // Four in five fixed inside the SLA window.
                $fraction = mt_rand(1, 5) === 1 ? mt_rand(110, 160) / 100 : mt_rand(35, 95) / 100;
                $remediated = $raised->copy()->addMinutes((int) ($hours * 60 * $fraction));
                if ($remediated->gt(now()->subDay())) {
                    $remediated = now()->subDays(mt_rand(1, 3));
                }
                $remediator = $owner ?? $creator;
                $attrs['remediated_at'] = $remediated;
                $attrs['remediated_by_id'] = $remediator->id;
                $events[] = [$remediated, 'status', 'Marked remediated. Awaiting independent verification.', $remediator->id];

                if (in_array($status, ['verified', 'closed'], true)) {
                    $verifier = $verifiers->first(fn ($v) => $v->id !== $remediator->id && $v->id === $verifiers[$n % $verifiers->count()]->id)
                        ?? $verifiers->first(fn ($v) => $v->id !== $remediator->id);
                    $verified = $remediated->copy()->addDays(mt_rand(1, 6))->min(now()->subHours(6));
                    $attrs['verified_at'] = $verified;
                    $attrs['verified_by_id'] = $verifier->id;
                    $events[] = [$verified, 'status', 'Remediation verified: re-test passed and evidence filed.', $verifier->id];
                    if ($status === 'closed') {
                        $closed = $verified->copy()->addDays(mt_rand(1, 3))->min(now()->subHours(2));
                        $attrs['closed_at'] = $closed;
                        $events[] = [$closed, 'status', 'Closed.', $verifier->id];
                    }
                }
            }

            // Escalation as the hourly sweep would have left it.
            if (in_array($status, ['open', 'in_progress'], true) && $attrs['sla_due_at']->lt(now())) {
                $chain = $this->workflow->escalationChain($orgId, $issue->severity);
                $overdue = $attrs['sla_due_at']->diffInMinutes(now());
                $level = min(count($chain) - 1, $overdue >= $hours * 60 * 0.5 ? 2 : 1);
                for ($l = 1; $l <= $level; $l++) {
                    $at = $attrs['sla_due_at']->copy()->addMinutes($l === 1 ? 30 : (int) ($hours * 60 * 0.5) + 30);
                    $events[] = [$at, 'escalated', "Escalated to level {$l} ({$chain[$l]}): ".($l === 1 ? 'SLA breached.' : 'Still unremediated at 150% of the SLA window.'), null];
                }
                $attrs['escalation_level'] = $level;
                $attrs['escalated_at'] = $level ? $attrs['sla_due_at']->copy()->addMinutes($level === 1 ? 30 : (int) ($hours * 60 * 0.5) + 30) : null;
            }

            // Tickets: auto-pushed severities, plus a share of the rest.
            $auto = collect($activeConnections)->first(fn ($c) => $c->auto_push_severity && $this->rank($issue->severity) <= $this->rank($c->auto_push_severity));
            $connection = $auto ?? ($activeConnections[$n % max(1, count($activeConnections))] ?? null);
            if ($connection && ($auto || mt_rand(1, 10) <= 3)) {
                $k = $ticketCounter[$connection->id] = ($ticketCounter[$connection->id] ?? 0) + 1;
                $base = rtrim($connection->base_url, '/');
                [$ref, $url] = match ($connection->system) {
                    'jira' => [$connection->project_key.'-'.(100 + $k), null],
                    'servicenow' => ['INC'.str_pad((string) (10040 + $k), 7, '0', STR_PAD_LEFT), null],
                    default => [(string) (5200 + $k), null],
                };
                $url = match ($connection->system) {
                    'jira' => "{$base}/browse/{$ref}",
                    'servicenow' => "{$base}/nav_to.do?uri=incident.do?number={$ref}",
                    default => "{$base}/a/tickets/{$ref}",
                };
                $pushed = $raised->copy()->addMinutes(mt_rand(1, 90));
                $attrs = array_merge($attrs, [
                    'itsm_connection_id' => $connection->id, 'external_ticket_system' => $connection->system,
                    'external_ticket_id' => $ref, 'external_ticket_url' => $url,
                    'external_ticket_status' => self::TICKET_STATUS[$connection->system][$status],
                    'ticket_synced_at' => now()->subHours(mt_rand(1, 72)),
                ]);
                $events[] = [$pushed, 'ticket', "Ticket {$ref} created in {$connection->name} (sandbox).", $creator->id];
            }

            if (mt_rand(1, 3) === 1 && $status !== 'open') {
                $events[] = [$raised->copy()->addDays(mt_rand(1, 4))->min(now()->subHours(3)), 'comment', $this->comment($type), ($owner ?? $creator)->id];
            }

            $attrs['updated_at'] = collect($events)->max(fn ($e) => $e[0]);
            DB::table('issues')->where('id', $issue->id)->update($attrs);

            usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($events as [$at, $eventType, $description, $actorId]) {
                IssueEvent::create(['issue_id' => $issue->id, 'event_type' => $eventType, 'description' => $description, 'actor_id' => $actorId, 'created_at' => $at]);
            }
        }

        foreach ($connections as $connection) {
            $last = Issue::where('itsm_connection_id', $connection->id)->max('ticket_synced_at');
            $connection->forceFill(['last_synced_at' => $last])->save();
        }
    }

    /** Pickable source records per type, inside the bank. */
    private function records(int $orgId): array
    {
        $out = [];
        foreach (Issue::SOURCES as $type => $meta) {
            if (! $meta['model']) {
                continue;
            }
            $out[$type] = $meta['model']::withoutGlobalScopes()->where('organization_id', $orgId)
                ->when(in_array(SoftDeletes::class, class_uses_recursive($meta['model'])), fn ($q) => $q->whereNull('deleted_at'))
                // Read through the model: some sources have no code/title column (accessors, or `name`).
                ->orderBy('id')->get()
                ->map(fn ($r) => (object) ['id' => $r->id, 'code' => $r->{$meta['code']}, 'title' => $r->title ?? $r->name ?? $r->{$meta['code']}])
                ->values();
        }

        return $out;
    }

    /** First Bank's generated titles carry a counter ("… (26)"); those become titles of the source. */
    private function title(string $current, string $type, $record): string
    {
        $numbered = (bool) preg_match('/\s\(\d+\)$/', $current);
        if ($current !== 'pending' && ! $numbered) {
            return $current;
        }
        if (! $record) {
            return preg_replace('/\s\(\d+\)$/', '', $current);
        }
        $subject = Str::limit((string) $record->title, 150, '');

        return match ($type) {
            'vulnerability' => "Remediate {$record->code}: {$subject}",
            'incident' => "Corrective action from {$record->code}: {$subject}",
            'risk' => "Reduce exposure on {$record->code}: {$subject}",
            'assessment' => "Close compliance gap {$record->code}: {$subject}",
            'control_test' => "Control test failed on {$record->code}: {$subject}",
            'ea_arb_condition' => "ARB condition from {$record->code}: {$subject}",
            default => $subject,
        };
    }

    private function description(string $title, string $type): string
    {
        return match ($type) {
            'vulnerability' => "Raised so the fix for this finding is owned and verified, not just patched. {$title}.",
            'incident' => "Post-incident corrective action agreed at the lessons-learned review. {$title}.",
            'risk' => "Action from the risk register to bring residual exposure back within appetite. {$title}.",
            'assessment' => "Compliance gap identified in the framework assessment. {$title}.",
            'control_test' => "The last control test failed; the control must be fixed and re-tested. {$title}.",
            'audit' => "Audit observation requiring management action. {$title}.",
            'ndpa_finding' => "Data-protection finding to be closed before the next NDPC compliance audit return. {$title}.",
            default => $title.'.',
        };
    }

    private function rootCause(string $type): string
    {
        return [
            'vulnerability' => 'Patch cycle for this platform runs quarterly and the vendor fix landed mid-cycle.',
            'incident' => 'Detection rule existed but alert routing to the on-call analyst was not configured.',
            'risk' => 'Compensating control relied on a manual weekly review that had lapsed.',
            'assessment' => 'Procedure exists but evidence of operation was not retained.',
            'control_test' => 'Configuration drift after the last change; no automated baseline check.',
            'audit' => 'Ownership of the process was unclear after the 2025 reorganisation.',
            'ndpa_finding' => 'Retention schedule was not applied to the legacy archive.',
        ][$type] ?? 'Process gap.';
    }

    private function plan(string $type): string
    {
        return [
            'vulnerability' => 'Apply the vendor fix in the next emergency change window, re-scan, attach the clean scan as evidence.',
            'incident' => 'Configure routing, run a table-top to prove it, update the playbook.',
            'risk' => 'Automate the review in the CCM engine and add a KRI on review lapses.',
            'assessment' => 'Retain evidence monthly in the evidence vault; re-assess next quarter.',
            'control_test' => 'Restore the baseline, add a CCM drift test, re-test the control.',
            'audit' => 'Assign a named process owner, document the procedure, evidence one full cycle.',
            'ndpa_finding' => 'Apply the retention schedule, purge expired records, record the purge in the RoPA.',
        ][$type] ?? 'Assign owner, fix, evidence, verify.';
    }

    private function comment(string $type): string
    {
        return [
            'vulnerability' => 'Vendor confirmed the fix is in the next maintenance release.',
            'incident' => 'Discussed at the weekly incident review; on track.',
            'risk' => 'CRO asked for a progress update at the next ERM committee.',
            'assessment' => 'Evidence template shared with the process owner.',
            'control_test' => 'Re-test booked with the control tester.',
            'audit' => 'Internal Audit agreed the remediation approach.',
            'ndpa_finding' => 'DPCO reviewed the purge list.',
        ][$type] ?? 'Progress noted.';
    }

    private function rank(string $severity): int
    {
        $i = array_search($severity, Issue::SEVERITIES, true);

        return $i === false ? -1 : $i;
    }
}

<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Policy Management demo data (ATH-VAL-009). Runs after VendorManagementDemoSeeder.
 *
 * The bank seeders give policies a random category, owner and approver (the Information Security
 * Policy was owned by a control tester and approved by a network engineer) and hand out
 * attestations for drafts and retired policies. Per bank, deterministically, this seeder:
 * - sets each policy's category, owner and approver from its title, with the approver never the
 *   owner, and the policy author as submitter of anything past draft;
 * - keeps attestations only for policies in force, stamped with the version attested;
 * - maps each policy to the controls that implement it, leaving two policies in force unmapped
 *   (deliberate gap);
 * - raises policy exceptions in every state: two awaiting a decision, two approved (one expiring
 *   within 30 days), one rejected, one expired.
 */
class PolicyManagementDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'ciso' => 'admin', 'legacy' => 'flexcube'],
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'ciso' => 'ciso', 'legacy' => 'flexcube.app'],
    ];

    /** title fragment => [category, owner role, approver role, control domains] — first match wins. */
    private const RULES = [
        ['information security policy', 'information_security', 'ciso', 'board', ['Governance', 'Logging & Monitoring']],
        ['cbn cybersecurity', 'information_security', 'ciso', 'board', ['Governance']],
        ['acceptable use', 'acceptable_use', 'ciso', 'compliance', ['Identity & Access']],
        ['access control', 'access_control', 'ciso', 'compliance', ['Identity & Access']],
        ['password', 'access_control', 'ciso', 'compliance', ['Identity & Access']],
        ['remote access', 'access_control', 'itops', 'ciso', ['Identity & Access']],
        ['incident', 'incident_response', 'ciso', 'cro', ['Logging & Monitoring', 'Governance']],
        ['business continuity', 'bcdr', 'itops', 'cro', ['Governance']],
        ['backup', 'bcdr', 'itops', 'ciso', ['Data Protection']],
        ['data protection', 'data_protection', 'dpco', 'compliance', ['Data Protection']],
        ['classification', 'data_protection', 'dpco', 'ciso', ['Data Protection']],
        ['retention', 'data_protection', 'dpco', 'compliance', ['Data Protection']],
        ['third-party', 'vendor_management', 'tprm', 'cro', ['Governance']],
        ['outsourcing', 'vendor_management', 'tprm', 'cro', ['Governance']],
        ['cryptography', 'information_security', 'ciso', 'cro', ['Cryptography']],
        ['vulnerability', 'information_security', 'itops', 'ciso', ['Vulnerability Management', 'Governance']],
        ['patch', 'information_security', 'itops', 'ciso', ['Vulnerability Management', 'Governance']],
        ['logging', 'information_security', 'ciso', 'cro', ['Logging & Monitoring', 'Governance']],
        ['fraud', 'risk_management', 'cro', 'board', ['Governance']],
        ['risk', 'risk_management', 'cro', 'board', ['Governance']],
        ['pci', 'information_security', 'ciso', 'compliance', ['Cryptography', 'Data Protection']],
        ['cloud', 'information_security', 'itops', 'ciso', ['Identity & Access', 'Data Protection']],
        ['email', 'information_security', 'itops', 'ciso', ['Governance']],
        ['physical', 'information_security', 'itops', 'ciso', ['Governance']],
        ['software development', 'information_security', 'itops', 'ciso', ['Governance']],
        ['change management', 'governance', 'itops', 'ciso', ['Governance']],
    ];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || PolicyException::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }

            $byEmail = User::where('organization_id', $org->id)->pluck('id', 'email');
            $person = fn (string $role) => $byEmail[match ($role) {
                'ciso' => $bank['ciso'], 'board' => 'board.risk', 'legacy' => $bank['legacy'], default => $role,
            }."@{$bank['domain']}"] ?? null;

            $this->normalise($org->id);
            $policies = Policy::withoutGlobalScopes()->where('organization_id', $org->id)->orderBy('policy_code')->get();
            $this->ownership($policies, $person);
            $this->attestations($org->id);
            $this->controls($org->id, $policies->where('status', 'active')->values());
            $this->exceptions($org->id, $person);
        }
    }

    /**
     * The bank seeders still write the pre-ATH-VAL-009 vocabulary; on a fresh install they run after
     * the migration that maps it, so the same mapping is applied here.
     */
    private function normalise(int $orgId): void
    {
        $policies = DB::table('policies')->where('organization_id', $orgId);
        (clone $policies)->where('status', 'expired')->update(['status' => 'active', 'review_date' => now()->subDays(14)->toDateString()]);
        foreach (['under_review' => 'in_review', 'published' => 'active', 'archived' => 'retired'] as $from => $to) {
            (clone $policies)->where('status', $from)->update(['status' => $to]);
        }
        (clone $policies)->where('status', 'retired')->whereNull('retired_at')->update(['retired_at' => DB::raw('updated_at')]);

        $attestations = DB::table('policy_attestations')->where('organization_id', $orgId);
        (clone $attestations)->whereIn('status', ['acknowledged'])->update(['status' => 'completed']);
        (clone $attestations)->where('status', 'declined')->update(['status' => 'pending']);
        (clone $attestations)->where('status', 'overdue')->update(['status' => 'pending', 'due_date' => now()->subDays(7)->toDateString()]);
        (clone $attestations)->where('status', 'completed')->whereNull('acknowledged_at')->update(['acknowledged_at' => DB::raw('updated_at')]);
    }

    private function rule(string $title): array
    {
        $title = Str::lower($title);
        foreach (self::RULES as $rule) {
            if (str_contains($title, $rule[0])) {
                return array_slice($rule, 1);
            }
        }

        return ['governance', 'compliance', 'ciso', ['Governance']];
    }

    private function ownership($policies, callable $person): void
    {
        foreach ($policies as $policy) {
            [$category, $ownerRole, $approverRole] = $this->rule($policy->title);
            $owner = $person($ownerRole) ?? $person('compliance');
            $approver = $person($approverRole);
            if (! $approver || $approver === $owner) {
                $approver = $person($ownerRole === 'cro' ? 'compliance' : 'cro');
            }
            $pastDraft = $policy->status !== 'draft';

            DB::table('policies')->where('id', $policy->id)->update([
                'category' => $category,
                'owner_id' => $owner,
                'submitted_by' => $pastDraft ? $person('policy') : null,
                'submitted_at' => $pastDraft ? ($policy->approved_at ?? $policy->updated_at)?->copy()->subDays(10) : null,
                'approved_by' => in_array($policy->status, ['approved', 'active', 'retired'], true) ? $approver : null,
                'approved_at' => in_array($policy->status, ['approved', 'active', 'retired'], true) ? ($policy->approved_at ?? $policy->updated_at) : null,
                'published_at' => in_array($policy->status, ['active', 'retired'], true) ? ($policy->published_at ?? $policy->approved_at ?? $policy->updated_at) : null,
                'status_note' => $policy->status === 'retired' ? 'Superseded by the consolidated policy set.' : null,
                'updated_at' => now(),
            ]);
        }
    }

    /** Only policies in force are attested to; each attestation records the version it covers. */
    private function attestations(int $orgId): void
    {
        $inForce = Policy::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'active')->pluck('version_number', 'id');

        DB::table('policy_attestations')->where('organization_id', $orgId)->whereNotIn('policy_id', $inForce->keys())->delete();
        foreach ($inForce as $policyId => $version) {
            DB::table('policy_attestations')->where('policy_id', $policyId)->update(['version_number' => $version]);
        }
    }

    private function controls(int $orgId, $active): void
    {
        $controls = Control::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('control_code')->get(['id', 'domain'])->groupBy('domain');
        $skip = $active->slice(-2)->pluck('id')->all();

        foreach ($active as $i => $policy) {
            if (in_array($policy->id, $skip, true)) {
                continue;
            }
            $domains = $this->rule($policy->title)[3];
            $picked = collect($domains)->flatMap(function ($domain) use ($controls, $i) {
                $pool = $controls[$domain] ?? $controls['Governance'] ?? collect();

                return $pool->isEmpty() ? collect() : collect(range(0, 1))->map(fn ($k) => $pool[($i * 2 + $k) % $pool->count()]->id);
            })->unique()->take(4);

            foreach ($picked as $controlId) {
                DB::table('control_policy')->insertOrIgnore([
                    'organization_id' => $orgId, 'policy_id' => $policy->id, 'control_id' => $controlId,
                    'clause' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function exceptions(int $orgId, callable $person): void
    {
        // Exceptions can only be raised against an approved or active policy: try each related title in turn.
        $policy = function (string $fragments) use ($orgId) {
            $open = fn () => Policy::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('status', ['active', 'approved']);
            foreach (explode('|', $fragments) as $fragment) {
                if ($found = $open()->whereRaw('lower(title) like ?', ['%'.$fragment.'%'])->orderBy('policy_code')->first()) {
                    return $found;
                }
            }

            return $open()->orderBy('policy_code')->first();
        };
        $risk = Risk::withoutGlobalScopes()->where('organization_id', $orgId)->whereRaw('lower(title) like ?', ['%eol%'])->value('id');

        $rows = [
            ['password|access control', 'Legacy AIX core cannot enforce the password complexity standard', 'high', 'requested', 'legacy', null,
                'The AIX 7.1 backup host predates the directory integration and only supports 8-character passwords.', 'Host isolated in the core VLAN; access only through the PAM jump host with MFA; quarterly password rotation.', 0, 150, $risk],
            ['data protection|classification|retention|cloud', 'Marketing team staging campaign files in a personal cloud drive', 'medium', 'requested', 'digital', null,
                'The approved file-sharing platform cannot receive files above 2 GB from the creative agency.', 'Files limited to non-personal creative assets; drive shared with named agency staff only.', 0, 120, null],
            ['cryptography|pci|information security policy', 'TLS 1.0 on the internal Flexcube treasury interface until the upgrade', 'high', 'approved', 'legacy', 'ciso',
                'The Flexcube 12.0 interface library does not support TLS 1.2; the upgrade is scheduled for next quarter.', 'Interface restricted to the treasury subnet; traffic monitored by the SIEM; upgrade change request raised.', -70, 20, null],
            ['remote access|password|access control', 'Vendor support jump host without hardware MFA tokens', 'medium', 'approved', 'itops', 'ciso',
                'The OEM\'s support engineers rotate weekly and hardware tokens cannot be issued in time.', 'Time-boxed accounts, session recording and approval per session.', -30, 120, null],
            ['information security policy|cbn cybersecurity', 'Disable endpoint protection on the trading desk for latency', 'critical', 'rejected', 'itops', 'ciso',
                'Traders report latency on the dealing platform with EDR enabled.', null, -20, 60, null],
            ['patch|vulnerability', 'Deferred patching of the ATM fleet during the switch migration', 'high', 'expired', 'itops', 'cro',
                'ATM software could not be patched while the switch migration was in progress.', 'ATMs on a segregated network; daily integrity monitoring by the vendor.', -190, -12, null],
        ];

        foreach ($rows as $n => [$fragment, $title, $rating, $status, $requesterRole, $deciderRole, $justification, $compensating, $startOffset, $endOffset, $riskId]) {
            $p = $policy($fragment);
            if (! $p) {
                continue;
            }
            PolicyException::withoutGlobalScopes()->create([
                'organization_id' => $orgId,
                'exception_code' => PolicyException::generateNextCode($orgId),
                'policy_id' => $p->id,
                'control_id' => $p->controls()->value('controls.id'),
                'risk_id' => $riskId,
                'title' => $title,
                'justification' => $justification,
                'compensating_controls' => $compensating,
                'risk_rating' => $rating,
                'status' => $status,
                'requested_by' => $person($requesterRole),
                'owner_id' => $person($requesterRole),
                'decided_by' => $deciderRole ? $person($deciderRole) : null,
                'decided_at' => $deciderRole ? now()->addDays($startOffset)->subDays(2) : null,
                'decision_notes' => match ($status) {
                    'rejected' => 'Not acceptable: endpoint protection is a CBN baseline control. Raise a performance ticket with the EDR vendor instead.',
                    'approved' => 'Approved for the stated term with the compensating controls listed.',
                    'expired' => 'Approved for the migration window only.',
                    default => null,
                },
                'starts_on' => now()->addDays($startOffset)->toDateString(),
                'expires_on' => now()->addDays($endOffset)->toDateString(),
                'created_at' => now()->addDays($startOffset)->subDays(5),
                'updated_at' => now(),
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\BoardPackRun;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use Illuminate\Database\Seeder;

/**
 * Workflow Studio demo configuration (ATH-VAL-016). Runs last.
 *
 * Per bank, the approval flows a Nigerian bank typically runs, with approvers who hold the module
 * permission (so every stage can actually be decided):
 * - policies: Head of Compliance review, then CISO approval; started only by Compliance Officers;
 * - policy exceptions: compliance review, plus CISO sign-off for high and critical exceptions;
 * - Statement of Applicability: ISMS review, then CISO;
 * - BCP plans: CISO, then a second executive (another Organization Admin);
 * - drift acceptance: security operations review, plus CISO for high and critical drift;
 * - access reviews: Compliance sign-off; board packs: CISO; EA maturity: CISO.
 * BIA approval is left on the module default to show the difference.
 *
 * Live approvals are started for the policies already in review (one of them past its first
 * stage) and the draft board packs, so the Approval Inbox has work in it.
 */
class WorkflowStudioDemoSeeder extends Seeder
{
    private const BANKS = ['kano-heritage-bank' => 'kanoheritage.ng', 'first-bank-nigeria' => 'firstbanknigeria.ng'];

    /** process => [initiator roles, stages [name, type, ref, min, sla, condition field, condition values]] */
    private const FLOWS = [
        'policy.approval' => [['Compliance Officer'], [
            ['Head of Compliance review', 'job_title', 'Head of Compliance', 1, 72, null, null],
            ['CISO approval', 'job_title', 'CISO', 1, 72, null, null],
        ]],
        'policy.exception' => [[], [
            ['Compliance review', 'role', 'Compliance Officer', 1, 48, null, null],
            ['CISO sign-off (high and critical)', 'job_title', 'CISO', 1, 48, 'risk_rating', ['high', 'critical']],
        ]],
        'isms.soa' => [['Compliance Officer'], [
            ['ISMS steering review', 'role', 'Compliance Officer', 1, 120, null, null],
            ['CISO approval', 'job_title', 'CISO', 1, 72, null, null],
        ]],
        'bcp.plan' => [[], [
            ['CISO approval', 'job_title', 'CISO', 1, 72, null, null],
            ['Executive sign-off', 'role', 'Organization Admin', 1, 120, null, null],
        ]],
        'drift.acceptance' => [[], [
            ['Security operations review', 'role', 'Security Analyst', 1, 24, null, null],
            ['CISO acceptance (high and critical)', 'job_title', 'CISO', 1, 24, 'severity', ['high', 'critical']],
        ]],
        'access_review.signoff' => [['Compliance Officer', 'Organization Admin'], [
            ['Compliance sign-off', 'role', 'Compliance Officer', 1, 72, null, null],
        ]],
        'board_pack.approval' => [['Risk Manager', 'Organization Admin'], [
            ['CISO approval', 'job_title', 'CISO', 1, 48, null, null],
        ]],
        'ea.maturity' => [[], [
            ['CISO approval', 'job_title', 'CISO', 1, 120, null, null],
        ]],
    ];

    public function run(): void
    {
        $engine = app(ApprovalEngine::class);

        foreach (self::BANKS as $slug => $domain) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }
            $admin = User::where('email', "sysadmin@{$domain}")->first();

            foreach (self::FLOWS as $process => [$initiators, $stages]) {
                $wf = ApprovalWorkflow::withoutGlobalScopes()->create([
                    'organization_id' => $org->id, 'process' => $process, 'is_active' => true,
                    'initiator_roles' => $initiators ?: null, 'updated_by' => $admin?->id,
                ]);
                foreach ($stages as $i => [$name, $type, $ref, $min, $sla, $field, $values]) {
                    $wf->stages()->create(['organization_id' => $org->id, 'sequence' => ($i + 1) * 10, 'name' => $name,
                        'approver_type' => $type, 'approver_ref' => $ref, 'min_approvals' => $min, 'sla_hours' => $sla,
                        'condition_field' => $field, 'condition_values' => $values, 'is_active' => true]);
                }
            }

            // Live approvals: the policies already in review, and the draft board packs.
            // Only where every stage has someone eligible: the CISO cannot approve a policy they own.
            $compliance = User::where('email', "compliance@{$domain}")->first();
            $ciso = User::where('organization_id', $org->id)->where('job_title', 'like', '%CISO%')->pluck('id');
            $policies = Policy::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'in_review')
                ->whereNotIn('owner_id', $ciso)->whereNotIn('submitted_by', $ciso)->orderBy('id')->get();
            foreach ($policies as $i => $policy) {
                $engine->start('policy.approval', $policy);
                if ($i === 0 && $compliance && (int) $policy->submitted_by !== (int) $compliance->id) {
                    $engine->approve('policy.approval', $policy, $compliance, 'Reviewed against the policy framework; wording agreed with Legal.');
                }
            }
            foreach (BoardPackRun::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'draft')->get() as $run) {
                $engine->start('board_pack.approval', $run);
            }
            ApprovalRequest::withoutGlobalScopes()->where('organization_id', $org->id)->update(['created_at' => now()->subDays(2)]);
        }
    }
}

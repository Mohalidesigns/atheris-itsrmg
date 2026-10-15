<?php

namespace App\Services\Finance;

use App\Models\Approvals\ApprovalWorkflow;

/**
 * Default Workflow Studio stages for the finance processes (ATH-FIN-001 §6, plan C28/C32/C34).
 * Idempotent per process: a bank that already has a workflow row (even with no stages) is left alone.
 */
class FinancePolicySeeder
{
    public static function ensureDefaults(int $orgId): void
    {
        $cfg = config('finance.approval_defaults');
        $plans = [
            'finance.budget_approval' => [
                self::stage(10, 'Head of IT review', 'job_title', 'Head of IT', 72),
                self::stage(20, 'CFO approval', 'job_title', 'Chief Financial Officer', 72),
                self::stage(30, 'CIO approval', 'job_title', 'Chief Information Officer', 72),
                self::stage(40, 'MD/CEO approval', 'job_title', 'Managing Director', 120, 'amount_band', self::bandsFrom($orgId, $cfg['budget_md_ceo_from'])),
            ],
            'finance.budget_transfer' => [
                self::stage(10, 'Finance Controller review', 'role', 'IT Finance Controller', 48),
                self::stage(20, 'CFO approval (cross cost centre)', 'job_title', 'Chief Financial Officer', 48, 'crosses_cost_centre', ['yes']),
                self::stage(30, 'CIO approval (high value)', 'job_title', 'Chief Information Officer', 48, 'amount_band', self::bandsFrom($orgId, $cfg['transfer_cio_from'])),
            ],
            'finance.supplementary_budget' => [
                self::stage(10, 'Head of IT review', 'job_title', 'Head of IT', 72),
                self::stage(20, 'CFO approval', 'job_title', 'Chief Financial Officer', 72),
                self::stage(30, 'CIO approval', 'job_title', 'Chief Information Officer', 72, 'amount_band', self::bandsFrom($orgId, $cfg['supplementary_cio_from'])),
            ],
        ];

        foreach ($plans as $process => $stages) {
            if (ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $orgId)->where('process', $process)->exists()) {
                continue;
            }
            $wf = ApprovalWorkflow::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'process' => $process, 'is_active' => true, 'initiator_roles' => null,
                'notes' => 'Seeded default (ATH-FIN-001 §6)',
            ]);
            foreach ($stages as $s) {
                $wf->stages()->create($s + ['organization_id' => $orgId, 'min_approvals' => 1, 'is_active' => true]);
            }
        }
    }

    /** Keys of the active bands whose max is open or above the threshold: the band holding it and every band above. */
    public static function bandsFrom(int $orgId, int|string $threshold): array
    {
        $bands = app(DoaBandService::class)->active($orgId)->bands;

        return array_values(array_map(fn ($b) => $b['key'], array_filter(
            $bands, fn ($b) => $b['max'] === null || bccomp((string) $b['max'], (string) $threshold, 2) > 0
        )));
    }

    private static function stage(int $seq, string $name, string $type, string $ref, int $sla, ?string $field = null, ?array $values = null): array
    {
        return ['sequence' => $seq, 'name' => $name, 'approver_type' => $type, 'approver_ref' => $ref, 'sla_hours' => $sla,
            'condition_field' => $field, 'condition_values' => $values];
    }
}

<?php

namespace App\Services\Change;

use App\Models\Change\ChangeApprovalStage;
use App\Models\Change\ChangeCabBoard;
use App\Models\User;

/**
 * The default approval policy every bank starts with (ATH-CHG-001 §2.2): six stages and a CAB
 * and ECAB board. Idempotent per organisation; called by the demo seeder and lazily by the
 * router when a bank has no stages yet.
 */
class ChangePolicySeeder
{
    public static function ensureDefaults(int $orgId): void
    {
        [$cab, $ecab] = self::ensureBoards($orgId);

        if (ChangeApprovalStage::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            return;
        }

        $stages = [
            [10, 'technical_review', 'Technical / peer review', ['normal'], 'medium', 'job_title', 'Head of IT', null, 24],
            [20, 'security_review', 'Information security review', ['normal'], 'medium', 'job_title', 'CISO', null, 48],
            [30, 'business_approval', 'Business owner approval', ['normal'], 'high', 'owner', null, null, 48],
            [40, 'cab', 'Change Advisory Board', ['normal'], 'high', 'board', (string) $cab->id, null, 72],
            [50, 'ecab', 'Emergency CAB', ['emergency'], 'low', 'board', (string) $ecab->id, 2, 4],
            [60, 'change_manager', 'Change Manager authorisation', ['normal', 'emergency'], 'low', 'role', 'Change Manager', null, 24],
        ];
        foreach ($stages as [$seq, $key, $name, $types, $minRisk, $approverType, $ref, $quorum, $sla]) {
            ChangeApprovalStage::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'sequence' => $seq, 'key' => $key, 'name' => $name,
                'applies_to_types' => $types, 'min_risk_level' => $minRisk, 'approver_type' => $approverType,
                'approver_ref' => $ref, 'quorum' => $quorum, 'sla_hours' => $sla, 'is_active' => true,
            ]);
        }
    }

    /** @return array{0: ChangeCabBoard, 1: ChangeCabBoard} */
    public static function ensureBoards(int $orgId): array
    {
        $chair = User::where('organization_id', $orgId)->whereLike('job_title', '%CISO%')->orderBy('id')->value('id');

        $cab = ChangeCabBoard::withoutGlobalScopes()->where('organization_id', $orgId)->where('type', 'cab')->orderBy('id')->first()
            ?? ChangeCabBoard::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'name' => 'Change Advisory Board', 'type' => 'cab', 'chair_id' => $chair,
                'quorum' => 3, 'meeting_cadence' => 'Weekly, Thursdays 10:00',
                'description' => 'Advises the Change Manager on normal changes rated high or critical.', 'is_active' => true,
            ]);
        $ecab = ChangeCabBoard::withoutGlobalScopes()->where('organization_id', $orgId)->where('type', 'ecab')->orderBy('id')->first()
            ?? ChangeCabBoard::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'name' => 'Emergency CAB', 'type' => 'ecab', 'chair_id' => $chair,
                'quorum' => 2, 'meeting_cadence' => 'Convened on demand (within 4 hours)',
                'description' => 'Authorises emergency changes, retrospectively where the fix could not wait.', 'is_active' => true,
            ]);

        return [$cab, $ecab];
    }
}

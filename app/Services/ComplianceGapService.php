<?php

namespace App\Services;

use App\Models\ComplianceResult;
use App\Models\Gap;
use App\Models\User;

/**
 * Keeps the gap register in step with assessment results.
 *
 * A non-compliant or partially-compliant result opens a gap (or re-opens / re-grades the one
 * already raised for that requirement in that assessment). Re-assessing the requirement as
 * compliant or not applicable resolves its open gap as remediated. Risk-accepted gaps are left
 * alone — acceptance is a management decision, not something an assessor's click should undo.
 */
class ComplianceGapService
{
    public function syncForResult(ComplianceResult $result, User $user): ?Gap
    {
        $assessment = $result->assessment;
        $orgId = (int) $assessment->organization_id;

        $existing = Gap::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('assessment_id', $assessment->id)
            ->where('requirement_id', $result->requirement_id)
            ->first();

        $severity = ComplianceResult::GAP_SEVERITY[$result->status] ?? null;

        if ($severity) {
            $requirement = $result->requirement;
            $attributes = [
                'severity' => $severity,
                'priority' => Gap::SEVERITY_PRIORITY[$severity],
                'control_id' => $result->control_id ?? $existing?->control_id,
            ];
            if (filled($result->findings)) {
                $attributes['description'] = $result->findings;
            }

            if (! $existing) {
                return Gap::create($attributes + [
                    'organization_id' => $orgId,
                    'assessment_id' => $assessment->id,
                    'requirement_id' => $result->requirement_id,
                    'gap_code' => Gap::generateNextCode($orgId),
                    'title' => 'Gap: '.trim(($requirement?->requirement_code ? $requirement->requirement_code.' ' : '').($requirement?->title ?? 'requirement')),
                    'status' => 'identified',
                    'remediation_plan' => $result->recommendations,
                ]);
            }

            if (in_array($existing->status, ['remediated', 'closed'], true)) {
                $attributes['status'] = 'identified';
                $attributes['completed_at'] = null;
                $attributes['notes'] = $this->appendNote($existing->notes, "Re-opened: re-assessed as {$this->label($result->status)} by {$user->name}.");
            }
            $existing->update($attributes);

            return $existing;
        }

        if (in_array($result->status, ['compliant', 'not_applicable'], true)
            && $existing && ! in_array($existing->status, Gap::RESOLVED_STATUSES, true)) {
            $existing->update([
                'status' => 'remediated',
                'completed_at' => now()->toDateString(),
                'notes' => $this->appendNote($existing->notes, "Resolved: re-assessed as {$this->label($result->status)} by {$user->name}."),
            ]);
        }

        return $existing;
    }

    private function label(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    private function appendNote(?string $notes, string $line): string
    {
        $line = now()->format('Y-m-d').' — '.$line;

        return $notes ? $notes."\n".$line : $line;
    }
}

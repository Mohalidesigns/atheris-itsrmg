<?php

namespace App\Listeners\Ea;

use App\Events\Core\BiaRecordSaved;
use App\Models\Ea\Process;
use App\Services\Ea\AuditLogger;

/**
 * Contract I-6 — BIA → EA Process / Capability.
 *
 * §7.3: "RTO/RPO and criticality write into `ea_processes`; capability map
 * heat-maps by BIA criticality; blast radius consumes real RTO."
 *
 * Status before this: "**Absent — EA process RTO/RPO columns are seeded, not
 * read from `bia_records`**". That is the more serious half of the finding:
 * §2.5's warning that "any coverage figure the module currently produces is
 * arithmetic over noise" applies equally to recovery exposure. A blast radius
 * that reports RTO from seeded numbers is worse than one that reports nothing,
 * because it looks authoritative.
 *
 * The BIA is the authority here — it is the artefact the business signed off —
 * so it overwrites the EA process rather than merging.
 */
class UpdateProcessCriticality
{
    public function handle(BiaRecordSaved $event): void
    {
        if (! config('ea.contracts.bia_to_process', true)) {
            return;
        }

        $record = $event->record;

        $process = $this->matchProcess($record);

        if (! $process) {
            return;
        }

        $before = [
            'criticality' => $process->criticality,
            'rto_hours' => $process->rto_hours,
            'rpo_hours' => $process->rpo_hours,
        ];

        $process->forceFill([
            'criticality' => $record->criticality ?: $process->criticality,
            'rto_hours' => $record->rto_hours ?? $process->rto_hours,
            'rpo_hours' => $record->rpo_hours ?? $process->rpo_hours,
            'owner_id' => $record->owner_id ?? $process->owner_id,
        ])->save();

        // Only log when something actually moved — this fires on every BIA
        // save, including no-op edits.
        $after = [
            'criticality' => $process->criticality,
            'rto_hours' => $process->rto_hours,
            'rpo_hours' => $process->rpo_hours,
        ];

        if ($before !== $after) {
            AuditLogger::logBare('contract.i6.sync', Process::class, $process->id, [
                'bia_record_id' => $record->id,
                'before' => $before,
                'after' => $after,
            ]);
        }
    }

    /**
     * Match a BIA record to an EA process.
     *
     * `bia_records` carries `process_name` as free text rather than a foreign
     * key — §7.4 asks the BCP module to "consume EA business services as the
     * BIA scope list", which would make this a real reference. Until that
     * lands, match on name, case-insensitively, and do nothing rather than
     * guess when there is no clean match. Writing a recovery objective onto the
     * wrong process is worse than writing none.
     */
    private function matchProcess(\App\Models\BiaRecord $record): ?Process
    {
        $name = trim((string) $record->process_name);

        if ($name === '') {
            return null;
        }

        $matches = Process::withoutGlobalScopes()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($record->organization_id, fn ($q) => $q->where(
                fn ($inner) => $inner->where('organization_id', $record->organization_id)
                    ->orWhereNull('organization_id')
            ))
            ->get();

        // An ambiguous match is not a match.
        return $matches->count() === 1 ? $matches->first() : null;
    }
}

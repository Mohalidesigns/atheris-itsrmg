<?php

namespace App\Services\Finance;

use App\Models\Finance\Contract;
use App\Models\Finance\ContractEvent;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Derives a contract's status from its dates (ATH-FIN-001 §5.4, plan §2.4). Pure with respect to
 * notifications: it only writes the status and the timeline events of each transition.
 */
class ContractStatusDeriver
{
    private const UNCHANGED = ['draft', 'renewed', 'terminated', 'replaced'];

    public function derive(Contract $c, CarbonInterface $today): string
    {
        if (in_array($c->status, self::UNCHANGED, true)) {
            return $c->status;
        }
        $d = $today->toDateString();

        return match (true) {
            $d > $c->ends_on->toDateString() => 'expired',
            $d > $c->notice_deadline_on->toDateString() => 'notice_deadline_passed',
            $d >= $c->renewal_window_opens_on->toDateString() => 'in_renewal_window',
            default => 'active',
        };
    }

    /** @return array{from:string,to:string}|null */
    public function apply(Contract $c, CarbonInterface $today, ?User $by = null): ?array
    {
        $to = $this->derive($c, $today);
        if ($to === $c->status) {
            return null;
        }
        $from = $c->status;
        $c->forceFill(['status' => $to])->save();
        ContractEvent::record($c, 'status_changed', ['from' => $from, 'to' => $to], $by);
        $specific = ['in_renewal_window' => 'window_opened', 'notice_deadline_passed' => 'notice_deadline_passed', 'expired' => 'expired'][$to] ?? null;
        $specific && ContractEvent::record($c, $specific, ['from' => $from], $by);

        return ['from' => $from, 'to' => $to];
    }

    /** An auto-renewing contract past its end with no decision and no successor rolls into a new term. */
    public function needsRoll(Contract $c, CarbonInterface $today): bool
    {
        return $c->is_auto
            && $today->toDateString() > $c->ends_on->toDateString()
            && $c->decision === null
            && $c->renewed_to_id === null
            && in_array($c->status, array_merge(Contract::LIVE, ['expired']), true);
    }
}

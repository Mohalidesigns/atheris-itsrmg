<?php

namespace App\Services\Finance;

use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentChange;
use App\Models\Finance\StageGate;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Facades\DB;

/**
 * Change control on an approved investment (FIN-05.11). Changes within tolerance apply at once; once the cumulative unapproved cost or
 * schedule movement since the last baseline would exceed tolerance, the change goes through the gate approval and re-baselines on approval.
 */
class InvestmentChangeService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    /** @param array{description?:string,reason?:?string,cost_delta?:mixed,schedule_delta_days?:mixed} $data */
    public function request(Investment $i, array $data, User $by): InvestmentChange
    {
        in_array($i->stage, ['approved', 'in_delivery'], true) || $this->fail('Changes are requested while an investment is approved or in delivery.', 'description');
        $i->status === 'on_hold' && $this->fail('This investment is on hold; resume it first.', 'description');
        (int) $i->organization_id === (int) $by->organization_id || $this->fail('That investment belongs to another bank.', 'description');
        StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'submitted')->exists()
            && $this->fail('A gate or change request for this investment is already awaiting approval.', 'description');
        $description = trim((string) ($data['description'] ?? ''));
        $description === '' && $this->fail('Describe the change.', 'description');
        $reason = trim((string) ($data['reason'] ?? ''));
        mb_strlen($reason) >= (int) config('finance.investments.reason_min') || $this->fail('Give the reason (at least '.config('finance.investments.reason_min').' characters).', 'reason');
        $cost = $this->money($data['cost_delta'] ?? '0', 'cost_delta', false, 'The cost change');
        $days = $data['schedule_delta_days'] ?? 0;
        (is_int($days) || (is_string($days) && preg_match('/^-?\d{1,4}$/', $days))) || $this->fail('The schedule change must be a whole number of days.', 'schedule_delta_days');
        $days = (int) $days;
        bccomp($cost, '0', 2) === 0 && $days === 0 && $this->fail('Enter a cost change or a schedule change.', 'cost_delta');
        $i->bac === null && $this->fail('The investment has no approved budget to change.', 'cost_delta');

        $orgId = (int) $i->organization_id;
        $tolPct = (string) ($i->bac_baselined_tolerance_pct ?? $i->cost_tolerance_pct); // the tolerance approved with the baseline, not the editable field
        $since = $i->bac_baselined_at;
        $prior = InvestmentChange::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'applied')->where('requires_approval', false)
            ->when($since, fn ($q) => $q->where('requested_at', '>=', $since))->get();
        $cumCost = $prior->reduce(fn ($c, $p) => bcadd($c, ltrim((string) $p->cost_delta, '-'), 2), '0.00');
        $cumCost = bcadd($cumCost, ltrim($cost, '-'), 2);
        $cumDays = $prior->sum(fn ($p) => abs((int) $p->schedule_delta_days)) + abs($days);
        $limit = bcmul((string) $i->bac, bcdiv($tolPct, '100', 6), 2);
        $scheduleLimit = (int) config('finance.investments.schedule_tolerance_days');
        $over = bccomp($cumCost, $limit, 2) > 0 || $cumDays > $scheduleLimit;

        return DB::transaction(function () use ($i, $orgId, $by, $description, $reason, $cost, $days, $over, $tolPct, $cumCost, $cumDays, $limit, $scheduleLimit) {
            $c = InvestmentChange::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'change_code' => InvestmentChange::generateNextCode($orgId),
                'description' => $description, 'reason' => $reason, 'cost_delta' => $cost, 'schedule_delta_days' => $days,
                'status' => $over ? 'submitted' : 'applied', 'requires_approval' => $over, 'requested_by_id' => $by->id, 'requested_at' => now(),
                'tolerance_snapshot' => ['cost_tolerance_pct' => $tolPct, 'bac' => (string) $i->bac, 'cumulative_cost' => $cumCost, 'cost_limit' => $limit, 'cumulative_days' => $cumDays, 'schedule_tolerance_days' => $scheduleLimit],
            ]);
            $this->audit($c, $by, 'requested', "Change {$c->change_code} requested on {$i->investment_code}", ['requires_approval' => $over]);
            if ($over) {
                app(StageGateService::class)->requestChange($c, $by);
            } else {
                $this->apply($c, $by);
            }

            return $c->refresh();
        });
    }

    public function apply(InvestmentChange $c, User $by, bool $approved = false): void
    {
        $i = Investment::withoutGlobalScopes()->findOrFail($c->investment_id);
        $bac = bcadd((string) $i->bac, (string) $c->cost_delta, 2);
        $attrs = ['bac' => bccomp($bac, '0', 2) < 0 ? '0.00' : $bac];
        if ($c->schedule_delta_days && $i->planned_ends_on) {
            $attrs['planned_ends_on'] = $i->planned_ends_on->addDays((int) $c->schedule_delta_days)->toDateString();
        }
        $approved && $attrs['bac_baselined_at'] = now();
        $i->forceFill($attrs)->save();
        $c->forceFill(['status' => 'applied', 'applied_at' => now()] + ($approved ? ['decided_by_id' => $by->id, 'decided_at' => now()] : []))->save();
        $this->audit($i, $by, 'change_applied', "Change {$c->change_code} applied to {$i->investment_code}", ['cost_delta' => (string) $c->cost_delta, 'days' => (int) $c->schedule_delta_days, 'bac' => $attrs['bac']]);
    }

    public function withdraw(InvestmentChange $c, User $by): void
    {
        $c->status === 'submitted' || $this->fail("A {$c->status} change cannot be withdrawn.");
        $gate = $c->gate()->withoutGlobalScopes()->first();
        $gate || $this->fail('This change has no pending request.');
        app(StageGateService::class)->withdraw($gate, $by);
    }
}

<?php

namespace App\Services\Finance;

use App\Models\Finance\CostCentre;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentChange;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
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

    private const NEGATIVE_BAC = 'The change would take the approved budget below zero.';

    /** @param array{description?:string,reason?:?string,cost_delta?:mixed,schedule_delta_days?:mixed,kind?:?string,field_changes?:?array} $data */
    public function request(Investment $i, array $data, User $by): InvestmentChange
    {
        $kind = $data['kind'] ?? 'scope';
        in_array($kind, InvestmentChange::KINDS, true) || $this->fail('Choose a scope or a reassignment change.', 'kind');
        (int) $i->organization_id === (int) $by->organization_id || $this->fail('That investment belongs to another bank.', 'description');

        return DB::transaction(function () use ($i, $data, $by, $kind) {
            // one pending request per investment: serialise on the investment row before the check (P5 security M5)
            Investment::withoutGlobalScopes()->whereKey($i->id)->lockForUpdate()->first();
            $i->refresh();

            return $kind === 'reassign' ? $this->requestReassign($i, $data, $by) : $this->requestScope($i, $data, $by);
        });
    }

    private function requestScope(Investment $i, array $data, User $by): InvestmentChange
    {
        in_array($i->stage, ['approved', 'in_delivery'], true) || $this->fail('Changes are requested while an investment is approved or in delivery.', 'description');
        $i->status === 'on_hold' && $this->fail('This investment is on hold; resume it first.', 'description');
        $this->assertNoPending($i);
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
        bccomp(bcadd((string) $i->bac, $cost, 2), '0', 2) < 0 && $this->fail(self::NEGATIVE_BAC, 'cost_delta'); // domain L2

        $orgId = (int) $i->organization_id;
        $tolPct = (string) ($i->bac_baselined_tolerance_pct ?? $i->cost_tolerance_pct); // the tolerance approved with the baseline, not the editable field
        $since = $i->bac_baselined_at;
        $prior = InvestmentChange::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'applied')->where('requires_approval', false)->where('kind', '!=', 'reassign')
            ->when($since, fn ($q) => $q->where('requested_at', '>=', $since))->get();
        $cumCost = $prior->reduce(fn ($c, $p) => bcadd($c, ltrim((string) $p->cost_delta, '-'), 2), '0.00');
        $cumCost = bcadd($cumCost, ltrim($cost, '-'), 2);
        $cumDays = $prior->sum(fn ($p) => abs((int) $p->schedule_delta_days)) + abs($days);
        // the limit is a share of the BAC baselined at the last approval, so within-tolerance drift cannot move the goalposts (domain L2)
        $base = (string) ($i->bac_baseline_amount ?? $i->bac);
        $limit = bcmul($base, bcdiv($tolPct, '100', 6), 2);
        $scheduleLimit = (int) config('finance.investments.schedule_tolerance_days');
        $over = bccomp($cumCost, $limit, 2) > 0 || $cumDays > $scheduleLimit;

        $c = InvestmentChange::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'change_code' => InvestmentChange::generateNextCode($orgId), 'kind' => 'scope',
            'description' => $description, 'reason' => $reason, 'cost_delta' => $cost, 'schedule_delta_days' => $days,
            'status' => $over ? 'submitted' : 'applied', 'requires_approval' => $over, 'requested_by_id' => $by->id, 'requested_at' => now(),
            'tolerance_snapshot' => ['cost_tolerance_pct' => $tolPct, 'bac' => (string) $i->bac, 'baseline_bac' => $base, 'cumulative_cost' => $cumCost, 'cost_limit' => $limit, 'cumulative_days' => $cumDays, 'schedule_tolerance_days' => $scheduleLimit],
        ]);
        $this->audit($c, $by, 'requested', "Change {$c->change_code} requested on {$i->investment_code}", ['requires_approval' => $over]);
        if ($over) {
            app(StageGateService::class)->requestChange($c, $by);
        } else {
            $this->apply($c, $by);
        }

        return $c->refresh();
    }

    /** A reassignment of sponsor, manager, cost centre, rgt or the regulatory flag after approval: always decided at the change gate (P6-22, C168). */
    private function requestReassign(Investment $i, array $data, User $by): InvestmentChange
    {
        in_array($i->stage, ['approved', 'in_delivery', 'delivered', 'benefits_tracking'], true) || $this->fail('A reassignment is requested once the business case is approved and before the investment closes.', 'field_changes');
        $i->status === 'on_hold' && $this->fail('This investment is on hold; resume it first.', 'field_changes');
        $this->assertNoPending($i);
        $reason = trim((string) ($data['reason'] ?? ''));
        mb_strlen($reason) >= 20 || $this->fail('Give the reason for the reassignment (at least 20 characters).', 'reason');
        (bccomp($this->money($data['cost_delta'] ?? '0', 'cost_delta', false, 'The cost change'), '0', 2) === 0 && (int) ($data['schedule_delta_days'] ?? 0) === 0)
            || $this->fail('A reassignment cannot also change cost or schedule; request a separate scope change.', 'cost_delta');
        $changes = $this->reassignment($i, (array) ($data['field_changes'] ?? []), $by);
        $orgId = (int) $i->organization_id;
        $description = trim((string) ($data['description'] ?? '')) ?: 'Reassign '.implode(', ', array_map(fn ($k) => str_replace('_id', '', $k), array_keys($changes)));

        $c = InvestmentChange::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'change_code' => InvestmentChange::generateNextCode($orgId), 'kind' => 'reassign',
            'description' => mb_substr($description, 0, 5000), 'reason' => $reason, 'cost_delta' => '0.00', 'schedule_delta_days' => 0, 'field_changes' => $changes,
            'status' => 'submitted', 'requires_approval' => true, 'requested_by_id' => $by->id, 'requested_at' => now(),
            'tolerance_snapshot' => ['reassign' => true, 'bac' => $i->bac === null ? null : (string) $i->bac],
        ]);
        $this->audit($c, $by, 'requested', "Reassignment {$c->change_code} requested on {$i->investment_code}", ['fields' => array_keys($changes)]);
        app(StageGateService::class)->requestChange($c, $by);

        return $c->refresh();
    }

    /**
     * Validates the proposed values and returns {field: {from, to}} for the fields that really change.
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private function reassignment(Investment $i, array $proposed, User $by): array
    {
        $orgId = (int) $i->organization_id;
        $unknown = array_diff(array_keys($proposed), InvestmentChange::REASSIGN_FIELDS);
        $unknown && $this->fail('These fields cannot be reassigned: '.implode(', ', $unknown).'.', 'field_changes');
        $out = [];
        foreach (InvestmentChange::REASSIGN_FIELDS as $k) {
            if (! array_key_exists($k, $proposed)) {
                continue;
            }
            $to = $proposed[$k];
            switch ($k) {
                case 'sponsor_id':
                case 'manager_id':
                    $to = $to ? (int) $to : $this->fail('Pick a user.', "field_changes.{$k}");
                    $u = User::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($to)->first() ?? $this->fail('Pick a user of this bank.', "field_changes.{$k}");
                    ($u->is_active ?? true) || $this->fail('That user is not active.', "field_changes.{$k}");
                    $u->can('view it-finance') || $this->fail("{$u->name} has no access to IT Finance.", "field_changes.{$k}");
                    (int) $u->id === (int) $by->id && $this->fail('You cannot reassign a role to yourself.', "field_changes.{$k}");
                    break;
                case 'cost_centre_id':
                    $to = $to ? (int) $to : $this->fail('Pick a cost centre.', 'field_changes.cost_centre_id');
                    $this->inBank(CostCentre::class, $orgId, $to, 'field_changes.cost_centre_id', 'Pick a cost centre of this bank.');
                    break;
                case 'rgt':
                    in_array($to, Investment::RGT, true) || $this->fail('Choose one of the listed values.', 'field_changes.rgt');
                    break;
                case 'is_regulatory':
                    $to = filter_var($to, FILTER_VALIDATE_BOOLEAN);
                    break;
            }
            $from = $i->{$k};
            $from = $k === 'is_regulatory' ? (bool) $from : ($from === null ? null : (is_numeric($from) ? (int) $from : $from));
            if ($from !== $to) {
                $out[$k] = ['from' => $from, 'to' => $to];
            }
        }
        $out || $this->fail('Change at least one value; nothing differs from the current assignment.', 'field_changes');
        $sponsor = $out['sponsor_id']['to'] ?? $i->sponsor_id;
        $manager = $out['manager_id']['to'] ?? $i->manager_id;
        $sponsor && $manager && (int) $sponsor === (int) $manager && $this->fail('The manager cannot also be the sponsor.', 'field_changes');

        return $out;
    }

    private function assertNoPending(Investment $i): void
    {
        StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'submitted')->exists()
            && $this->fail('A gate or change request for this investment is already awaiting approval.', 'description');
    }

    public function apply(InvestmentChange $c, User $by, bool $approved = false): void
    {
        $i = Investment::withoutGlobalScopes()->findOrFail($c->investment_id);
        if ($c->kind === 'reassign') {
            $this->applyReassign($c, $i, $by);

            return;
        }
        $bac = bcadd((string) $i->bac, (string) $c->cost_delta, 2);
        bccomp($bac, '0', 2) < 0 && $this->fail(self::NEGATIVE_BAC, 'cost_delta'); // domain L2: refuse, never clamp
        $attrs = ['bac' => $bac];
        if ($c->schedule_delta_days && $i->planned_ends_on) {
            $attrs['planned_ends_on'] = $i->planned_ends_on->addDays((int) $c->schedule_delta_days)->toDateString();
        }
        $approved && $attrs += ['bac_baselined_at' => now(), 'bac_baseline_amount' => $bac];
        $i->forceFill($attrs)->save();
        $c->forceFill(['status' => 'applied', 'applied_at' => now()] + ($approved ? ['decided_by_id' => $by->id, 'decided_at' => now()] : []))->save();
        $this->audit($i, $by, 'change_applied', "Change {$c->change_code} applied to {$i->investment_code}", ['cost_delta' => (string) $c->cost_delta, 'days' => (int) $c->schedule_delta_days, 'bac' => $attrs['bac']]);
    }

    private function applyReassign(InvestmentChange $c, Investment $i, User $by): void
    {
        $changes = (array) $c->field_changes;
        $attrs = array_map(fn ($f) => $f['to'], $changes);
        $i->forceFill($attrs)->save();
        if (isset($changes['sponsor_id']) && $changes['sponsor_id']['from']) {
            ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->whereIn('status', ValueReview::OPEN)->where('reviewer_id', $changes['sponsor_id']['from'])
                ->update(['reviewer_id' => $changes['sponsor_id']['to']]);
        }
        $c->forceFill(['status' => 'applied', 'applied_at' => now(), 'decided_by_id' => $by->id, 'decided_at' => now()])->save();
        $this->audit($i, $by, 'reassigned', "Change {$c->change_code} reassigned {$i->investment_code}", ['changes' => $changes]);
    }

    public function withdraw(InvestmentChange $c, User $by): void
    {
        $c->status === 'submitted' || $this->fail("A {$c->status} change cannot be withdrawn.");
        $gate = $c->gate()->withoutGlobalScopes()->first();
        $gate || $this->fail('This change has no pending request.');
        app(StageGateService::class)->withdraw($gate, $by);
    }
}

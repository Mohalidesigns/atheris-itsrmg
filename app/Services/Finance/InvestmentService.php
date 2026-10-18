<?php

namespace App\Services\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentLink;
use App\Models\Finance\StageGate;
use App\Models\LegalEntity;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Facades\DB;

/**
 * The investment register and its lifecycle (ATH-FIN-001 §3.5). Stages move only through StageGateService (plan P5-4); this service owns
 * creation, editing, hold/resume/cancel, the manager's progress report, budget-line tagging (plan C134) and links.
 */
class InvestmentService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public const TRANSITIONS = [
        'to_business_case' => ['from' => ['idea'], 'to' => 'business_case'],
        'to_approved' => ['from' => ['business_case'], 'to' => 'approved'],
        'to_in_delivery' => ['from' => ['approved'], 'to' => 'in_delivery'],
        'to_delivered' => ['from' => ['in_delivery'], 'to' => 'delivered'],
        'to_benefits_tracking' => ['from' => ['delivered'], 'to' => 'benefits_tracking'],
        'to_closed' => ['from' => ['benefits_tracking'], 'to' => 'closed'],
        'cancel' => ['from' => ['idea', 'business_case', 'approved', 'in_delivery'], 'to' => 'cancelled'],
    ];

    /** Fields frozen once the business case is approved: they change only through an investment change. */
    private const FROZEN_AFTER_APPROVAL = ['starts_on', 'planned_ends_on'];

    /** Fields that drive SoD, change control, visibility and the approval conditions: frozen once approved and while a gate or change awaits a decision. */
    private const CONTROL_FIELDS = ['cost_tolerance_pct', 'sponsor_id', 'manager_id', 'cost_centre_id', 'is_regulatory', 'rgt'];

    private const TEXT = ['title' => 200, 'description' => 10000, 'strategic_objective' => 2000, 'regulatory_reference' => 200, 'external_reference' => 100];

    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly InvestmentScoring $scoring,
        private readonly InvestmentFinancials $financials,
    ) {}

    /** @param array<string,mixed> $data */
    public function store(array $data, User $by): Investment
    {
        $orgId = (int) $by->organization_id;
        $attrs = $this->fields($orgId, $data, null, $by);
        foreach (['title', 'type', 'rgt', 'driver'] as $required) {
            blank($attrs[$required] ?? null) && $this->fail('This field is required.', $required);
        }
        $attrs['cost_tolerance_pct'] ??= $this->settings->costTolerancePct($orgId);
        $attrs['fiscal_year_id'] ??= null;

        return DB::transaction(function () use ($orgId, $attrs, $by) {
            $i = Investment::withoutGlobalScopes()->create($attrs + [
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_code' => Investment::generateNextCode($orgId), 'stage' => 'idea', 'status' => 'on_track',
            ]);
            $this->audit($i, $by, 'created', "Investment {$i->investment_code} created", ['title' => $i->title]);

            return $i->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Investment $i, array $data, User $by): Investment
    {
        $this->assertOpen($i, 'edited');
        $attrs = $this->fields((int) $i->organization_id, $data, $i, $by);
        if ($i->bac !== null || in_array($i->stage, ['approved', 'in_delivery', 'delivered', 'benefits_tracking'], true)) {
            foreach (self::FROZEN_AFTER_APPROVAL as $k) {
                if (array_key_exists($k, $attrs) && (string) ($attrs[$k] ?? '') !== (string) ($i->{$k}?->toDateString() ?? '')) {
                    $this->fail('Dates are frozen once the business case is approved; request an investment change.', $k);
                }
            }
        }
        $this->assertControlFieldsFree($i, $attrs);
        $oldFy = $i->fiscal_year_id;

        return DB::transaction(function () use ($i, $attrs, $by, $oldFy) {
            $i->fill($attrs)->save();
            if ($i->wasChanged()) {
                $this->audit($i, $by, 'updated', "Investment {$i->investment_code} updated", ['changed' => array_keys($i->getChanges())]);
            }
            if ((int) $oldFy !== (int) $i->fiscal_year_id) {
                $this->scoring->rank((int) $i->organization_id, $oldFy ? (int) $oldFy : null);
                $this->scoring->rank((int) $i->organization_id, $i->fiscal_year_id ? (int) $i->fiscal_year_id : null);
            }

            return $i->refresh();
        });
    }

    /** @param array<string,mixed> $attrs */
    private function assertControlFieldsFree(Investment $i, array $attrs): void
    {
        $changed = array_values(array_filter(self::CONTROL_FIELDS, function ($k) use ($i, $attrs) {
            if (! array_key_exists($k, $attrs)) {
                return false;
            }

            return match ($k) {
                'cost_tolerance_pct' => bccomp((string) $attrs[$k], (string) $i->{$k}, 2) !== 0,
                'is_regulatory' => (bool) $attrs[$k] !== (bool) $i->{$k},
                default => (string) ($attrs[$k] ?? '') !== (string) ($i->{$k} ?? ''),
            };
        }));
        if (! $changed) {
            return;
        }
        $pending = StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'submitted')->exists();
        $pending && $this->fail('A gate or change request is awaiting a decision, so '.implode(', ', $changed).' cannot be changed until it is decided or withdrawn.', $changed[0]);
        if ($i->bac !== null || in_array($i->stage, ['approved', 'in_delivery', 'delivered', 'benefits_tracking'], true)) {
            $this->fail(implode(', ', $changed).' is frozen once the business case is approved; request an investment change.', $changed[0]);
        }
    }

    public function destroy(Investment $i, User $by): void
    {
        $i->stage === 'idea' || $this->fail('Only an investment still at the idea stage can be deleted; cancel it instead.');
        StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->exists() && $this->fail('This investment has gate history; cancel it instead.');
        BudgetLine::withoutGlobalScopes()->where('investment_id', $i->id)->exists() && $this->fail('Budget lines are tagged to this investment; untag them first.');
        DB::transaction(function () use ($i, $by) {
            $this->audit($i, $by, 'deleted', "Investment {$i->investment_code} deleted");
            $i->delete();
            $this->scoring->rank((int) $i->organization_id, $i->fiscal_year_id ? (int) $i->fiscal_year_id : null);
        });
    }

    public function hold(Investment $i, string $reason, User $by): Investment
    {
        $this->assertOpen($i, 'put on hold');
        $i->status === 'on_hold' && $this->fail('This investment is already on hold.');
        blank(trim($reason)) && $this->fail('Give the reason for the hold.', 'reason');
        $i->forceFill(['status_before_hold' => $i->status, 'status' => 'on_hold'])->save();
        $this->audit($i, $by, 'held', "Investment {$i->investment_code} put on hold", ['reason' => trim($reason)]);

        return $i->refresh();
    }

    public function resume(Investment $i, User $by): Investment
    {
        $this->assertOpen($i, 'resumed');
        $i->status === 'on_hold' || $this->fail('This investment is not on hold.');
        $i->forceFill(['status' => $i->status_before_hold ?: 'on_track', 'status_before_hold' => null])->save();
        $this->audit($i, $by, 'resumed', "Investment {$i->investment_code} resumed");

        return $i->refresh();
    }

    public function cancel(Investment $i, string $reason, User $by): Investment
    {
        $t = self::TRANSITIONS['cancel'];
        in_array($i->stage, $t['from'], true) || $this->fail("A {$i->stage} investment cannot be cancelled.");
        $reason = trim($reason);
        mb_strlen($reason) >= (int) config('finance.investments.reason_min') || $this->fail('Give the reason for cancelling (at least '.config('finance.investments.reason_min').' characters).', 'reason');

        return DB::transaction(function () use ($i, $reason, $by, $t) {
            app(StageGateService::class)->cancelPending($i, $by);
            foreach ($i->valueReviews()->withoutGlobalScopes()->whereIn('status', ['scheduled', 'due', 'overdue'])->get() as $r) {
                $r->forceFill(['status' => 'cancelled', 'cancel_reason' => 'Investment cancelled'])->save();
            }
            foreach ($i->benefits()->withoutGlobalScopes()->whereNotIn('status', ['realised', 'partially_realised', 'not_realised', 'cancelled'])->get() as $b) {
                $b->forceFill(['status' => 'cancelled', 'cancel_reason' => 'Investment cancelled'])->save();
            }
            $i->forceFill(['stage' => $t['to'], 'cancelled_at' => now(), 'cancelled_by_id' => $by->id, 'cancel_reason' => $reason, 'priority_rank' => null])->save();
            $this->scoring->rank((int) $i->organization_id, $i->fiscal_year_id ? (int) $i->fiscal_year_id : null);
            $this->audit($i, $by, 'cancelled', "Investment {$i->investment_code} cancelled", ['reason' => $reason]);

            return $i->refresh();
        });
    }

    /**
     * The manager's progress report: percent complete with an as-of date, and an optional manual EAC with its reason (plan P5-2).
     *
     * @param  array{percent_complete?:mixed,as_of?:?string,eac?:mixed,eac_reason?:?string,clear_eac?:mixed}  $data
     */
    public function progress(Investment $i, array $data, User $by): Investment
    {
        in_array($i->stage, ['approved', 'in_delivery', 'delivered', 'benefits_tracking'], true) || $this->fail('Progress is reported once the business case is approved.', 'percent_complete');
        $pct = $data['percent_complete'] ?? null;
        $pctS = is_scalar($pct) ? trim((string) $pct) : '';
        (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $pctS) && bccomp($pctS, '100', 2) <= 0) || $this->fail('Percent complete must be a number from 0 to 100.', 'percent_complete');
        $asOf = empty($data['as_of']) ? FinanceClock::today() : $this->date($data['as_of'], 'as_of');
        $asOf->toDateString() > FinanceClock::today()->toDateString() && $this->fail('The as-of date cannot be in the future.', 'as_of');

        $attrs = ['percent_complete' => bcadd($pctS, '0', 2), 'percent_complete_as_of' => $asOf->toDateString()];
        if (! empty($data['clear_eac'])) {
            $attrs += ['eac' => null, 'eac_reason' => null];
        } elseif (isset($data['eac']) && $data['eac'] !== '') {
            $reason = trim((string) ($data['eac_reason'] ?? ''));
            mb_strlen($reason) >= (int) config('finance.investments.reason_min') || $this->fail('A manual EAC needs a reason of at least '.config('finance.investments.reason_min').' characters.', 'eac_reason');
            $attrs += ['eac' => $this->money($data['eac'], 'eac', false, 'The EAC'), 'eac_reason' => $reason];
            bccomp($attrs['eac'], '0', 2) < 0 && $this->fail('The EAC cannot be negative.', 'eac');
        }

        return DB::transaction(function () use ($i, $attrs, $by) {
            $i->forceFill($attrs)->save();
            $health = $this->financials->evm($i->refresh())['health'];
            $i->status !== 'on_hold' && $health !== null && $i->forceFill(['status' => $health])->save();
            $this->audit($i, $by, 'progress', "Progress reported on {$i->investment_code}: {$attrs['percent_complete']}%", ['eac_override' => $i->eac !== null]);

            return $i->refresh();
        });
    }

    /**
     * Tags the given budget lines to the investment and untags the ones no longer listed. Tagging is a classification, not a money change,
     * so baseline lines may be tagged without a revision (plan C134); every change is audited.
     *
     * @param  list<int>  $lineIds
     * @return array{tagged:list<int>,untagged:list<int>}
     */
    public function linkLines(Investment $i, array $lineIds, User $by): array
    {
        $this->assertOpen($i, 'funded');
        $orgId = (int) $i->organization_id;
        $want = array_values(array_unique(array_map('intval', $lineIds)));
        $visible = FinancePolicy::visibleCostCentreIds($by);
        $valid = [];
        if ($want) {
            $lines = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $want)->get(['id', 'budget_id', 'cost_centre_id', 'investment_id']);
            $budgets = Budget::withoutGlobalScopes()->whereIn('id', $lines->pluck('budget_id'))->pluck('status', 'id');
            foreach ($lines as $l) {
                in_array($budgets[$l->budget_id] ?? null, ['baseline', 'draft'], true) || $this->fail('Only lines of a baseline or draft budget can be tagged.', 'line_ids');
                $visible !== null && ! in_array((int) $l->cost_centre_id, $visible, true) && $this->fail('One of those lines belongs to a cost centre outside your access.', 'line_ids');
                $l->investment_id && (int) $l->investment_id !== (int) $i->id && $this->fail('One of those lines is already tagged to another investment.', 'line_ids');
                $valid[] = (int) $l->id;
            }
            count($valid) === count($want) || $this->fail('Unknown budget line for this bank.', 'line_ids');
        }
        $current = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->where('investment_id', $i->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $tag = array_values(array_diff($valid, $current));
        $untag = array_values(array_diff($current, $valid));

        DB::transaction(function () use ($i, $tag, $untag, $by) {
            $tag && BudgetLine::withoutGlobalScopes()->whereIn('id', $tag)->toBase()->update(['investment_id' => $i->id]);
            $untag && BudgetLine::withoutGlobalScopes()->whereIn('id', $untag)->toBase()->update(['investment_id' => null]);
            ($tag || $untag) && $this->audit($i, $by, 'lines_linked', "Budget lines tagged to {$i->investment_code} changed", ['tagged' => $tag, 'untagged' => $untag]);
        });

        return ['tagged' => $tag, 'untagged' => $untag];
    }

    public function addLink(Investment $i, string $type, int $id, ?string $note, User $by): InvestmentLink
    {
        $class = InvestmentLink::TYPES[$type] ?? $this->fail('Unknown link type.', 'type');
        $orgId = (int) $i->organization_id;
        $shared = in_array($class, [EaApplication::class, Capability::class, BusinessService::class], true); // platform-shared EA rows have a NULL organization
        $class::withoutGlobalScopes()->whereKey($id)
            ->where(fn ($q) => $shared ? $q->where('organization_id', $orgId)->orWhereNull('organization_id') : $q->where('organization_id', $orgId))->exists()
            || $this->fail('That record does not exist in this bank.', 'id');
        InvestmentLink::withoutGlobalScopes()->where('investment_id', $i->id)->where('linkable_type', $class)->where('linkable_id', $id)->exists()
            && $this->fail('That record is already linked.', 'id');
        $l = InvestmentLink::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'linkable_type' => $class, 'linkable_id' => $id,
            'note' => $note ? mb_substr(trim($note), 0, 300) : null,
        ]);
        $this->audit($i, $by, 'link_added', "Link added to {$i->investment_code}", ['type' => $type, 'id' => $id]);

        return $l;
    }

    public function removeLink(InvestmentLink $l, User $by): void
    {
        $i = Investment::withoutGlobalScopes()->findOrFail($l->investment_id);
        $type = $l->type_key;
        $id = $l->linkable_id;
        $l->delete();
        $this->audit($i, $by, 'link_removed', "Link removed from {$i->investment_code}", ['type' => $type, 'id' => $id]);
    }

    /** Internal: StageGateService moves the stage after a gate passes. */
    public function applyStage(Investment $i, string $to, User $by): void
    {
        $action = collect(self::TRANSITIONS)->filter(fn ($t, $k) => $k !== 'cancel' && $t['to'] === $to)->keys()->first() ?? $this->fail("Unknown stage {$to}.");
        in_array($i->stage, self::TRANSITIONS[$action]['from'], true) || $this->fail("A {$i->stage} investment cannot move to {$to}.");
        $from = $i->stage;
        $i->forceFill(['stage' => $to])->save();
        if ($to === 'closed') {
            $i->forceFill(['priority_rank' => null])->save();
            $this->scoring->rank((int) $i->organization_id, $i->fiscal_year_id ? (int) $i->fiscal_year_id : null);
        }
        $this->audit($i, $by, 'stage_changed', "{$i->investment_code} moved from {$from} to {$to}", ['from' => $from, 'to' => $to]);
    }

    private function assertOpen(Investment $i, string $verb): void
    {
        $i->is_open || $this->fail("A {$i->stage} investment cannot be {$verb}.");
    }

    /**
     * Validated attributes from the posted fields (only keys that were posted).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function fields(int $orgId, array $data, ?Investment $existing, User $by): array
    {
        $out = [];
        $has = fn (string $k) => array_key_exists($k, $data);
        foreach (self::TEXT as $k => $max) {
            if ($has($k)) {
                $v = trim((string) ($data[$k] ?? ''));
                mb_strlen($v) > $max && $this->fail("Keep this under {$max} characters.", $k);
                $out[$k] = $v === '' ? null : $v;
            }
        }
        if ($has('title') && blank($out['title'])) {
            $this->fail('Give the investment a title.', 'title');
        }
        foreach (['type' => Investment::TYPES, 'rgt' => Investment::RGT, 'driver' => Investment::DRIVERS] as $k => $allowed) {
            if ($has($k)) {
                in_array($data[$k], $allowed, true) || $this->fail('Choose one of the listed values.', $k);
                $out[$k] = $data[$k];
            }
        }
        foreach (['sponsor_id', 'manager_id'] as $k) {
            if ($has($k)) {
                $out[$k] = $data[$k] ? (int) $this->inBank(User::class, $orgId, $data[$k], $k, 'Pick a user of this bank.')->id : null;
            }
        }
        if ($has('cost_centre_id')) {
            if ($data['cost_centre_id']) {
                $cc = $this->inBank(CostCentre::class, $orgId, $data['cost_centre_id'], 'cost_centre_id', 'Pick a cost centre of this bank.');
                $ids = FinancePolicy::visibleCostCentreIds($by);
                $ids !== null && (int) $existing?->cost_centre_id !== (int) $cc->id && ! in_array((int) $cc->id, $ids, true) && $this->fail('That cost centre is outside your access.', 'cost_centre_id');
                $out['cost_centre_id'] = (int) $cc->id;
            } else {
                $out['cost_centre_id'] = null;
            }
        }
        if ($has('legal_entity_id')) {
            $out['legal_entity_id'] = null;
            if ($data['legal_entity_id']) {
                LegalEntity::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->whereKey($data['legal_entity_id'])->exists()
                    || $this->fail('Pick a legal entity of this bank.', 'legal_entity_id');
                $out['legal_entity_id'] = (int) $data['legal_entity_id'];
            }
        }
        if ($has('fiscal_year_id')) {
            $out['fiscal_year_id'] = $data['fiscal_year_id'] ? (int) $this->inBank(FiscalYear::class, $orgId, $data['fiscal_year_id'], 'fiscal_year_id', 'Pick a fiscal year of this bank.')->id : null;
        }
        foreach (['starts_on', 'planned_ends_on'] as $k) {
            if ($has($k)) {
                $out[$k] = $data[$k] ? $this->date($data[$k], $k)->toDateString() : null;
            }
        }
        $starts = $out['starts_on'] ?? $existing?->starts_on?->toDateString();
        $ends = $out['planned_ends_on'] ?? $existing?->planned_ends_on?->toDateString();
        $starts && $ends && $ends < $starts && $this->fail('The planned end date cannot be before the start date.', 'planned_ends_on');
        if ($has('is_regulatory')) {
            $out['is_regulatory'] = (bool) $data['is_regulatory'];
        }
        if ($has('cost_tolerance_pct')) {
            $v = is_scalar($data['cost_tolerance_pct']) ? trim((string) $data['cost_tolerance_pct']) : '';
            (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v) && bccomp($v, '100', 2) <= 0) || $this->fail('The cost tolerance must be a percentage from 0 to 100.', 'cost_tolerance_pct');
            $out['cost_tolerance_pct'] = bcadd($v, '0', 2);
        }
        if ($has('is_regulatory') || $has('regulatory_reference')) {
            $reg = $out['is_regulatory'] ?? (bool) $existing?->is_regulatory;
            $ref = array_key_exists('regulatory_reference', $out) ? $out['regulatory_reference'] : $existing?->regulatory_reference;
            if (! $reg && $has('is_regulatory')) {
                $out['regulatory_reference'] = null;
            }
        }

        return $out;
    }
}

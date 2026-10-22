<?php

namespace App\Services\Finance;

use App\Models\Asset;
use App\Models\Finance\Actual;
use App\Models\Finance\AmortisationLine;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\Investment;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Capitalising investment and capex spend as assets (FIN-03.13; plan P6-11 to P6-14). The eligible pool is the counted capex actuals of the
 * source up to the available-for-use date (IAS 38.30), less what is already capitalised. A capitalisation is prepared with a criteria
 * checklist, approved through Workflow Studio, and on final approval books an asset and a memo amortisation schedule.
 */
class CapitalisationService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public const PROCESS = 'finance.capitalisation_approval';

    public const PERMISSION = 'approve it-finance';

    public const TRANSITIONS = [
        'submit' => ['from' => ['draft'], 'to' => 'submitted'],
        'withdraw' => ['from' => ['submitted'], 'to' => 'draft'],
        'approve' => ['from' => ['submitted'], 'to' => 'capitalised'],
        'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
        'reopen' => ['from' => ['rejected'], 'to' => 'draft'],
        'cancel' => ['from' => ['draft', 'rejected'], 'to' => 'cancelled'],
    ];

    public function __construct(private readonly FinanceApprovals $approvals, private readonly InvestmentFinancials $investments) {}

    /* ---------------------------------------------------------------- eligibility */

    /**
     * Sources with something left to capitalise as of today.
     *
     * @param  list<int>|null  $ccIds
     * @return list<array<string,mixed>>
     */
    public function candidates(int $orgId, ?array $ccIds, User $viewer): array
    {
        $today = FinanceClock::today();
        $out = [];
        $investments = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->where('is_capitalisable', true)
            ->whereIn('stage', config('finance.capitalisation.investment_stages'))->orderBy('investment_code')->get();
        foreach ($investments as $i) {
            if (! Gate::forUser($viewer)->allows('view', $i)) {
                continue;
            }
            $e = $this->eligibility('investment', (int) $i->id, $today->toDateString(), $orgId);
            bccomp($e['remaining'], '0', 2) > 0 && $out[] = [
                'source_type' => 'investment', 'id' => (int) $i->id, 'label' => trim("{$i->investment_code} {$i->title}"), 'eligible_amount' => $e['pool'],
                'already_capitalised' => $e['already'], 'remaining' => $e['remaining'], 'delivered_on' => ($i->actual_ends_on ?? $i->planned_ends_on)?->toDateString(),
                'standard_hint' => $i->capitalisation_standard ?: 'ias_38',
            ];
        }
        $expenses = ExpenseRequest::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->where('is_capex', true)
            ->whereIn('status', config('finance.capitalisation.expense_statuses'))->orderBy('request_code')->get();
        foreach ($expenses as $x) {
            if (! Gate::forUser($viewer)->allows('view', $x)) {
                continue;
            }
            $e = $this->eligibility('expense', (int) $x->id, $today->toDateString(), $orgId);
            bccomp($e['remaining'], '0', 2) > 0 && $out[] = [
                'source_type' => 'expense', 'id' => (int) $x->id, 'label' => trim("{$x->request_code} {$x->title}"), 'eligible_amount' => $e['pool'],
                'already_capitalised' => $e['already'], 'remaining' => $e['remaining'], 'delivered_on' => $x->closed_at?->toDateString(), 'standard_hint' => 'ias_16',
            ];
        }

        return $out;
    }

    /**
     * The pool: counted capex actuals of the source posted within [recognition date, available-for-use date], at IAS 16.16 / IAS 38.27 cost (net of
     * recoverable VAT when the bank recovers it, leases excluded, actuals already claimed by a capitalisation of another source excluded), less what
     * is already capitalised or excluded by submitted and capitalised rows of this source.
     *
     * @return array{pool:string,already:string,remaining:string,actuals:list<array<string,mixed>>,actual_ids:list<int>}
     */
    public function eligibility(string $sourceType, int $sourceId, string $availableForUseOn, int $orgId, ?int $excludeCapId = null, ?string $recognitionOn = null): array
    {
        $q = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_capex', true)->where('is_lease', false)->where('match_status', '!=', 'duplicate')->whereDate('posted_on', '<=', $availableForUseOn);
        $recognitionOn && $q->whereDate('posted_on', '>=', $recognitionOn);
        if ($sourceType === 'investment') {
            $inv = Investment::withoutGlobalScopes()->where('organization_id', $orgId)->find($sourceId);
            $lineIds = $inv ? $this->investments->lineIds($inv) : [];
            $q->where(fn ($b) => $b->where('investment_id', $sourceId)->when($lineIds, fn ($w) => $w->orWhereIn('budget_line_id', $lineIds)));
        } else {
            $invoiceIds = Invoice::withoutGlobalScopes()->where('organization_id', $orgId)->where('expense_request_id', $sourceId)->pluck('id');
            $q->whereIn('invoice_id', $invoiceIds->all() ?: [0]);
        }
        $sourceCol = $sourceType === 'investment' ? 'investment_id' : 'expense_request_id';
        $claimed = [];
        Capitalisation::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['submitted', 'capitalised'])->where(fn ($b) => $b->where($sourceCol, '!=', $sourceId)->orWhereNull($sourceCol))
            ->when($excludeCapId, fn ($b) => $b->where('id', '!=', $excludeCapId))->whereNotNull('consumed_actual_ids')->pluck('consumed_actual_ids')
            ->each(function ($ids) use (&$claimed) {
                foreach ((array) (is_string($ids) ? json_decode($ids, true) : $ids) as $id) {
                    $claimed[(int) $id] = true;
                }
            });
        $recoverable = (bool) app(FinanceSettings::class)->for($orgId)->capitalisation_vat_recoverable;
        $rows = $q->orderByDesc('base_amount')->get(['id', 'posted_on', 'description', 'base_amount', 'amount', 'invoice_id'])->reject(fn ($a) => isset($claimed[(int) $a->id]));
        $inv = $recoverable ? Invoice::withoutGlobalScopes()->whereIn('id', $rows->pluck('invoice_id')->filter()->unique()->all() ?: [0])->get(['id', 'tax_amount', 'total_amount', 'tax_type_id'])->keyBy('id') : collect();
        $kinds = $recoverable ? DB::table('fin_tax_types')->where('organization_id', $orgId)->pluck('kind', 'id') : collect();
        $pool = '0.00';
        $actuals = [];
        $ids = [];
        foreach ($rows as $a) {
            $net = bcadd((string) $a->base_amount, '0', 2);
            $i = $a->invoice_id ? ($inv[$a->invoice_id] ?? null) : null;
            if ($i && bccomp((string) $i->total_amount, '0', 2) > 0 && bccomp((string) $i->tax_amount, '0', 2) > 0 && ($i->tax_type_id === null || ($kinds[$i->tax_type_id] ?? 'vat') === 'vat')) {
                $net = bcsub($net, bcdiv(bcmul($net, (string) $i->tax_amount, 8), (string) $i->total_amount, 2), 2); // recoverable input VAT is not cost (IAS 16.16, IAS 38.27)
            }
            $pool = bcadd($pool, $net, 2);
            $ids[] = (int) $a->id;
            count($actuals) < 100 && $actuals[] = ['id' => (int) $a->id, 'posted_on' => $a->posted_on->toDateString(), 'description' => $a->description, 'base_amount' => $net];
        }
        $taken = Capitalisation::withoutGlobalScopes()->where('organization_id', $orgId)->where($sourceCol, $sourceId)
            ->whereIn('status', ['submitted', 'capitalised'])->when($excludeCapId, fn ($b) => $b->where('id', '!=', $excludeCapId));
        $already = bcadd((string) (clone $taken)->sum('amount'), (string) (clone $taken)->sum('excluded_amount'), 2); // excluded costs (research, training) are never re-capitalised
        $remaining = bcsub($pool, $already, 2);

        return ['pool' => $pool, 'already' => $already, 'remaining' => bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining, 'actuals' => $actuals, 'actual_ids' => $ids];
    }

    /* ---------------------------------------------------------------- lifecycle */

    /** @param array<string,mixed> $data */
    public function store(array $data, User $by): Capitalisation
    {
        $orgId = (int) $by->organization_id;
        $sourceType = (string) ($data['source_type'] ?? '');
        in_array($sourceType, Capitalisation::SOURCE_TYPES, true) || $this->fail('Pick an investment or a capex expense.', 'source_type');
        $source = $sourceType === 'investment'
            ? Investment::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) ($data['source_id'] ?? 0))
            : ExpenseRequest::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) ($data['source_id'] ?? 0));
        $source || $this->fail('That source does not belong to this bank.', 'source_id');
        Gate::forUser($by)->allows('capitalise', $source) || $this->fail('You cannot prepare a capitalisation for that source.', 'source_id');
        $this->assertSourceCapitalisable($source);
        $clean = $this->clean($data, $orgId, $source, null);
        $standard = $clean['standard'];

        return DB::transaction(function () use ($clean, $source, $sourceType, $standard, $orgId, $by) {
            $c = Capitalisation::withoutGlobalScopes()->create($clean + [
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'capitalisation_code' => Capitalisation::generateNextCode($orgId), 'source_type' => $sourceType,
                'investment_id' => $source instanceof Investment ? $source->id : null, 'expense_request_id' => $source instanceof ExpenseRequest ? $source->id : null,
                'cost_centre_id' => $source->cost_centre_id, 'status' => 'draft', 'criteria_checklist' => $this->freshChecklist($standard, []),
            ]);
            $this->audit($c, $by, 'created', "Capitalisation {$c->capitalisation_code} prepared for {$c->source_label}");

            return $c->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Capitalisation $c, array $data, User $by): Capitalisation
    {
        $this->sameBank($c, $by);
        if ($c->status === 'rejected') {
            $c = $this->reopen($c, $by);
        }
        $c->status === 'draft' || $this->fail("A {$c->status} capitalisation can no longer be edited.");
        $source = $c->source() ?? $this->fail('The source of this capitalisation is gone.');
        $merged = $data + $c->only(['title', 'standard', 'amount', 'excluded_amount', 'excluded_reason', 'residual_value', 'useful_life_months', 'method', 'declining_rate_pct', 'create_asset', 'asset_id', 'asset_name', 'asset_type', 'asset_owner_id'])
            + ['available_for_use_on' => $c->available_for_use_on->toDateString(), 'recognition_date' => $c->recognition_date?->toDateString()];
        $clean = $this->clean($merged, (int) $c->organization_id, $source, $c);
        $checklist = $this->freshChecklist($clean['standard'], (array) $c->criteria_checklist);
        if (isset($data['criteria_checklist'])) {
            $checklist = $this->applyChecklist($checklist, (array) $data['criteria_checklist'], $c);
        }
        $c->fill($clean + ['criteria_checklist' => $checklist])->save();
        $this->audit($c, $by, 'updated', "Capitalisation {$c->capitalisation_code} updated");

        return $c->refresh();
    }

    public function submit(Capitalisation $c, User $by): Capitalisation
    {
        $this->sameBank($c, $by);
        $c->status === 'draft' || $this->fail("A {$c->status} capitalisation cannot be submitted.");

        return DB::transaction(function () use ($c, $by) {
            $c = Capitalisation::withoutGlobalScopes()->lockForUpdate()->findOrFail($c->id);
            $c->status === 'draft' || $this->fail("A {$c->status} capitalisation cannot be submitted.");
            $source = $c->source() ?? $this->fail('The source of this capitalisation is gone.');
            $source instanceof Investment
                ? Investment::withoutGlobalScopes()->lockForUpdate()->find($source->id)
                : ExpenseRequest::withoutGlobalScopes()->lockForUpdate()->find($source->id);
            $this->assertSourceCapitalisable($source);

            $min = (int) config('finance.capitalisation.note_min', 10);
            foreach ((array) $c->criteria_checklist as $item) {
                (! empty($item['met']) && mb_strlen(trim((string) ($item['note'] ?? ''))) >= $min)
                    || $this->fail('Tick every criterion and give each a note of at least '.$min.' characters: "'.($item['label'] ?? $item['key']).'" is incomplete.', 'criteria_checklist');
            }
            $c->available_for_use_on->toDateString() <= FinanceClock::today()->toDateString() || $this->fail('The asset must already be available for use.', 'available_for_use_on');
            $e = $this->eligibility($c->source_type, (int) ($c->investment_id ?: $c->expense_request_id), $c->available_for_use_on->toDateString(), (int) $c->organization_id, (int) $c->id, $c->recognition_date?->toDateString());
            bccomp((string) $c->amount, $e['remaining'], 2) <= 0 || $this->fail("The amount ({$c->amount}) exceeds what is left to capitalise ({$e['remaining']}).", 'amount');
            AmortisationSchedule::build((string) $c->amount, (string) $c->residual_value, (int) $c->useful_life_months, $c->method, $c->declining_rate_pct === null ? null : (string) $c->declining_rate_pct, $c->available_for_use_on->startOfMonth()->toDateString());

            $c->forceFill([
                'status' => 'submitted', 'requested_by_id' => $by->id, 'requested_at' => now(), 'eligible_amount' => $e['pool'], 'consumed_actual_ids' => $e['actual_ids'], 'rejection_reason' => null,
                'sod_excluded_ids' => array_values(array_unique(array_merge($c->sodExcludedIds(), [(int) $by->id]))),
            ])->save();
            $request = $this->approvals->start(self::PROCESS, $c, $by);
            $request && $c->forceFill(['approval_request_id' => $request->id])->save();
            $this->audit($c, $by, 'submitted', "Capitalisation {$c->capitalisation_code} sent for approval", ['amount' => (string) $c->amount]);

            return $c->refresh();
        });
    }

    /** Returns a message while stages remain; the final approval books the asset and the schedule. */
    public function approve(Capitalisation $c, User $by, ?string $note = null): string
    {
        $this->assertSubmitted($c);
        $this->assertApprover($c, $by);

        return DB::transaction(function () use ($c, $by, $note) {
            $c = Capitalisation::withoutGlobalScopes()->lockForUpdate()->findOrFail($c->id);
            $this->assertSubmitted($c);
            $today = FinanceClock::today();
            $period = $this->assertOpenPeriod($this->periodContaining((int) $c->organization_id, $today), 'period_id'); // the CFO's decision is not wasted on a closed month
            $this->approvals->assertZeroStepSoD(self::PROCESS, $c, $by, 'requested_by_id', null, self::PERMISSION);
            if (! $this->approvals->configured(self::PROCESS, (int) $c->organization_id)) {
                $this->approvals->assertDefaultApprover($c, $by, 'requested_by_id', null, self::PERMISSION);
            } else {
                $this->approvals->assertNoStageStranded(self::PROCESS, $c, $by);
                if ($message = $this->approvals->approve(self::PROCESS, $c, $by, $note)) {
                    $this->audit($c, $by, 'stage_approved', $message);

                    return $message;
                }
            }
            $e = $this->eligibility($c->source_type, (int) ($c->investment_id ?: $c->expense_request_id), $c->available_for_use_on->toDateString(), (int) $c->organization_id, (int) $c->id, $c->recognition_date?->toDateString());
            bccomp((string) $c->amount, $e['remaining'], 2) <= 0 || $this->fail("The amount ({$c->amount}) now exceeds what is left to capitalise ({$e['remaining']}).", 'amount');

            $lines = AmortisationSchedule::build((string) $c->amount, (string) $c->residual_value, (int) $c->useful_life_months, $c->method,
                $c->declining_rate_pct === null ? null : (string) $c->declining_rate_pct, $c->available_for_use_on->startOfMonth()->toDateString(), $today->startOfMonth()->toDateString());
            $assetId = $this->linkOrCreateAsset($c, $by);
            $periods = Period::withoutGlobalScopes()->where('organization_id', $c->organization_id)->where('is_adjustment', false)->get(['id', 'starts_on', 'ends_on']);
            $now = now();
            $rows = [];
            foreach ($lines as $l) {
                $pid = $l['is_catch_up'] ? $period->id : optional($periods->first(fn ($p) => $p->starts_on->toDateString() <= $l['month'] && $p->ends_on->toDateString() >= $l['month']))->id;
                $rows[] = ['organization_id' => $c->organization_id, 'created_by_id' => $by->id, 'capitalisation_id' => $c->id, 'line_no' => $l['line_no'], 'month' => $l['month'], 'period_id' => $pid,
                    'opening_nbv' => $l['opening_nbv'], 'amount' => $l['amount'], 'closing_nbv' => $l['closing_nbv'], 'is_catch_up' => $l['is_catch_up'], 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('fin_amortisation_lines')->insert($chunk);
            }
            $c->forceFill([
                'status' => 'capitalised', 'capitalised_on' => $today->toDateString(), 'booking_period_id' => $period->id, 'approved_by_id' => $by->id, 'approved_at' => now(),
                'eligible_amount' => $e['pool'], 'asset_id' => $assetId,
            ])->save();
            $this->approvals->finalised(self::PROCESS, $c, $by, $note);
            $this->audit($c, $by, 'approved', "Capitalisation {$c->capitalisation_code} approved: {$c->amount} capitalised on {$today->toDateString()}", ['asset_id' => $assetId, 'lines' => count($rows)]);

            return "Capitalisation {$c->capitalisation_code} approved: the asset is booked and its amortisation schedule created.";
        });
    }

    public function reject(Capitalisation $c, User $by, string $note): Capitalisation
    {
        $this->assertSubmitted($c);
        blank(trim($note)) && $this->fail('Give a reason.', 'note');
        $this->assertApprover($c, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $c->organization_id)) {
            $this->approvals->assertDefaultApprover($c, $by, 'requested_by_id', null, self::PERMISSION);
        }
        DB::transaction(function () use ($c, $by, $note) {
            $this->approvals->decline(self::PROCESS, $c, $by, $note, 'rejected');
            $c->forceFill(['status' => 'rejected', 'rejection_reason' => $note])->save();
            $this->audit($c, $by, 'rejected', "Capitalisation {$c->capitalisation_code} rejected", ['note' => $note]);
        });

        return $c->refresh();
    }

    public function withdraw(Capitalisation $c, User $by): Capitalisation
    {
        $this->assertSubmitted($c);
        (int) $c->requested_by_id === (int) $by->id || (int) $c->created_by_id === (int) $by->id || $this->fail('Only the preparer can withdraw a capitalisation.');
        DB::transaction(function () use ($c, $by) {
            $c->forceFill(['status' => 'draft', 'sod_excluded_ids' => null, 'approval_request_id' => null])->save();
            $this->approvals->withdraw(self::PROCESS, $c, $by);
            $this->audit($c, $by, 'withdrawn', "Capitalisation {$c->capitalisation_code} withdrawn");
        });

        return $c->refresh();
    }

    public function reopen(Capitalisation $c, User $by): Capitalisation
    {
        $this->sameBank($c, $by);
        $c->status === 'rejected' || $this->fail("A {$c->status} capitalisation cannot be re-opened.");
        $c->forceFill(['status' => 'draft', 'sod_excluded_ids' => null, 'approval_request_id' => null])->save();
        $this->audit($c, $by, 'reopened', "Capitalisation {$c->capitalisation_code} re-opened for correction");

        return $c->refresh();
    }

    public function cancel(Capitalisation $c, string $reason, User $by): Capitalisation
    {
        $this->sameBank($c, $by);
        in_array($c->status, self::TRANSITIONS['cancel']['from'], true) || $this->fail("A {$c->status} capitalisation cannot be cancelled.");
        mb_strlen(trim($reason)) >= 10 || $this->fail('Give the reason for cancelling (at least 10 characters).', 'reason');
        $c->forceFill(['status' => 'cancelled', 'cancel_reason' => trim($reason)])->save();
        $this->audit($c, $by, 'cancelled', "Capitalisation {$c->capitalisation_code} cancelled", ['reason' => trim($reason)]);

        return $c->refresh();
    }

    /**
     * The memo amortisation lines of a capitalisation with the period of each month resolved by date.
     *
     * @return list<array<string,mixed>>
     */
    public function scheduleFor(Capitalisation $c): array
    {
        $periods = Period::withoutGlobalScopes()->where('organization_id', $c->organization_id)->where('is_adjustment', false)->get(['id', 'name', 'status', 'starts_on', 'ends_on']);
        $byId = $periods->keyBy('id');

        return $c->lines()->get()->map(function (AmortisationLine $l) use ($periods, $byId) {
            $m = $l->month->toDateString();
            $p = $l->period_id ? ($byId[$l->period_id] ?? null) : $periods->first(fn ($p) => $p->starts_on->toDateString() <= $m && $p->ends_on->toDateString() >= $m);

            return [
                'line_no' => (int) $l->line_no, 'month' => $m, 'period' => $p ? ['id' => $p->id, 'name' => $p->name, 'status' => $p->status] : null,
                'opening_nbv' => (string) $l->opening_nbv, 'amount' => (string) $l->amount, 'closing_nbv' => (string) $l->closing_nbv, 'is_catch_up' => (bool) $l->is_catch_up,
            ];
        })->all();
    }

    /* ---------------------------------------------------------------- internals */

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function clean(array $d, int $orgId, object $source, ?Capitalisation $self): array
    {
        $title = trim((string) ($d['title'] ?? ''));
        ($title !== '' && mb_strlen($title) <= 200) || $this->fail('Give the capitalisation a title (max 200 characters).', 'title');
        $standard = (string) ($d['standard'] ?? '');
        in_array($standard, Capitalisation::STANDARDS, true) || $this->fail('Pick IAS 38 (software) or IAS 16 (hardware).', 'standard');
        $afu = $this->date($d['available_for_use_on'] ?? null, 'available_for_use_on')->toDateString();
        $afu <= FinanceClock::today()->toDateString() || $this->fail('The available-for-use date cannot be in the future.', 'available_for_use_on');
        $amount = $this->money($d['amount'] ?? null, 'amount');
        $excluded = ($d['excluded_amount'] ?? '') === '' || ($d['excluded_amount'] ?? null) === null ? '0.00' : $this->money($d['excluded_amount'], 'excluded_amount', false, 'The excluded amount');
        bccomp($excluded, '0', 2) >= 0 || $this->fail('The excluded amount cannot be negative.', 'excluded_amount');
        $reason = isset($d['excluded_reason']) ? trim((string) $d['excluded_reason']) : '';
        bccomp($excluded, '0', 2) > 0 && mb_strlen($reason) < 10 && $this->fail('Say what the excluded amount is (research, training ...) in at least 10 characters.', 'excluded_reason');
        $residual = ($d['residual_value'] ?? '') === '' || ($d['residual_value'] ?? null) === null ? '0.00' : $this->money($d['residual_value'], 'residual_value', false, 'The residual value');
        (bccomp($residual, '0', 2) >= 0 && bccomp($residual, $amount, 2) < 0) || $this->fail('The residual value must be at least 0 and less than the amount.', 'residual_value');
        $life = (int) ($d['useful_life_months'] ?? 0);
        ($life >= 1 && $life <= (int) config('finance.capitalisation.max_life_months', 600)) || $this->fail('The useful life must be between 1 and '.config('finance.capitalisation.max_life_months', 600).' months.', 'useful_life_months');
        $method = (string) ($d['method'] ?? 'straight_line');
        in_array($method, Capitalisation::METHODS, true) || $this->fail('Pick straight line or declining balance.', 'method');
        $rate = null;
        if ($method === 'declining') {
            $rate = ($d['declining_rate_pct'] ?? '') === '' || ($d['declining_rate_pct'] ?? null) === null ? null : (string) $d['declining_rate_pct'];
            $rate !== null && (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $rate) || bccomp($rate, '0', 2) <= 0 || bccomp($rate, '100', 2) > 0) && $this->fail('The declining rate must be above 0 and at most 100 percent a year.', 'declining_rate_pct');
            $rate = $rate === null ? null : bcadd($rate, '0', 2);
            $life < 24 && $rate === null && $this->fail('A declining-balance asset with a life under 24 months needs an explicit rate (1 to 100 percent a year).', 'declining_rate_pct');
        }
        $recognition = ($d['recognition_date'] ?? '') === '' || ($d['recognition_date'] ?? null) === null ? null : $this->date($d['recognition_date'], 'recognition_date')->toDateString();
        $recognition === null || $recognition <= $afu || $this->fail('The recognition date (when the criteria were first met, IAS 38.65) cannot be after the available-for-use date.', 'recognition_date');
        $e = $this->eligibility($source instanceof Investment ? 'investment' : 'expense', (int) $source->id, $afu, $orgId, $self?->id, $recognition);
        bccomp($amount, $e['remaining'], 2) <= 0 || $this->fail("The amount ({$amount}) exceeds what is left to capitalise ({$e['remaining']}) of the capex booked up to {$afu}.", 'amount');

        $assetId = null;
        if (! empty($d['asset_id'])) {
            Asset::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey((int) $d['asset_id'])->exists() || $this->fail('That asset does not belong to this bank.', 'asset_id');
            $assetId = (int) $d['asset_id'];
        }
        $ownerId = null;
        if (! empty($d['asset_owner_id'])) {
            User::where('organization_id', $orgId)->whereKey((int) $d['asset_owner_id'])->exists() || $this->fail('The asset owner must be a user of this bank.', 'asset_owner_id');
            $ownerId = (int) $d['asset_owner_id'];
        }
        $assetType = (string) ($d['asset_type'] ?? '') ?: ($standard === 'ias_38' ? 'software' : 'hardware');
        in_array($assetType, Capitalisation::ASSET_TYPES, true) || $this->fail('Pick an asset type.', 'asset_type');
        $assetName = isset($d['asset_name']) ? trim((string) $d['asset_name']) : '';
        mb_strlen($assetName) <= 200 || $this->fail('The asset name is too long (max 200 characters).', 'asset_name');

        return [
            'title' => $title, 'standard' => $standard, 'available_for_use_on' => $afu, 'recognition_date' => $recognition, 'amount' => $amount, 'excluded_amount' => $excluded, 'excluded_reason' => $reason === '' ? null : $reason,
            'residual_value' => $residual, 'useful_life_months' => $life, 'method' => $method, 'declining_rate_pct' => $rate,
            'create_asset' => array_key_exists('create_asset', $d) ? (bool) $d['create_asset'] : true, 'asset_id' => $assetId, 'asset_name' => $assetName === '' ? null : $assetName,
            'asset_type' => $assetType, 'asset_owner_id' => $ownerId,
        ];
    }

    private function assertSourceCapitalisable(object $source): void
    {
        if ($source instanceof Investment) {
            $source->getAttribute('is_capitalisable') || $this->fail('This investment is not flagged as capitalisable; mark it on the investment first.', 'source_id');
            in_array($source->stage, config('finance.capitalisation.investment_stages'), true) || $this->fail("An investment in stage {$source->stage} cannot be capitalised yet.", 'source_id');
        } else {
            $source->is_capex || $this->fail('Only capex expense requests can be capitalised.', 'source_id');
            in_array($source->status, config('finance.capitalisation.expense_statuses'), true) || $this->fail("An expense request that is {$source->status} cannot be capitalised yet.", 'source_id');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $previous
     * @return list<array<string,mixed>>
     */
    private function freshChecklist(string $standard, array $previous): array
    {
        $prev = collect($previous)->keyBy('key');

        return array_map(fn ($row) => [
            'key' => $row[0], 'label' => $row[1], 'met' => (bool) ($prev[$row[0]]['met'] ?? false), 'note' => $prev[$row[0]]['note'] ?? null, 'document_id' => $prev[$row[0]]['document_id'] ?? null,
        ], (array) config("finance.capitalisation.checklists.{$standard}"));
    }

    /**
     * @param  list<array<string,mixed>>  $checklist
     * @param  list<array<string,mixed>>  $input
     * @return list<array<string,mixed>>
     */
    private function applyChecklist(array $checklist, array $input, Capitalisation $c): array
    {
        $in = collect($input)->keyBy('key');
        foreach ($checklist as &$item) {
            if (! isset($in[$item['key']])) {
                continue;
            }
            $row = $in[$item['key']];
            $item['met'] = (bool) ($row['met'] ?? false);
            $note = isset($row['note']) ? trim((string) $row['note']) : null;
            ($note === null || mb_strlen($note) <= 1000) || $this->fail('A checklist note is too long (max 1000 characters).', 'criteria_checklist');
            $item['note'] = $note === '' ? null : $note;
            if (! empty($row['document_id'])) {
                FinanceDocument::withoutGlobalScopes()->where('organization_id', $c->organization_id)->where('documentable_type', Capitalisation::class)->where('documentable_id', $c->id)->whereKey((int) $row['document_id'])->exists()
                    || $this->fail('That document is not attached to this capitalisation.', 'criteria_checklist');
                $item['document_id'] = (int) $row['document_id'];
            } else {
                $item['document_id'] = null;
            }
        }

        return $checklist;
    }

    private function linkOrCreateAsset(Capitalisation $c, User $by): ?int
    {
        $orgId = (int) $c->organization_id;
        if ($c->asset_id) {
            Asset::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($c->asset_id)->exists() || $this->fail('The linked asset no longer exists in this bank.', 'asset_id');

            return (int) $c->asset_id;
        }
        if (! $c->create_asset) {
            return null;
        }
        $source = $c->source();
        $owner = $c->asset_owner_id ?: ($source instanceof Investment ? $source->manager_id : ($source instanceof ExpenseRequest ? $source->requested_by_id : null));
        $afu = $c->available_for_use_on;
        $asset = Asset::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'asset_id_code' => Asset::generateNextCode($orgId), 'name' => $c->asset_name ?: $c->title, 'asset_type' => $c->asset_type ?: ($c->standard === 'ias_38' ? 'software' : 'hardware'),
            'category' => config('finance.capitalisation.asset_category'), 'owner_id' => $owner, 'purchase_date' => $afu->toDateString(), 'end_of_life' => $afu->addMonths((int) $c->useful_life_months)->toDateString(),
            'source' => 'manual', 'tags' => ['capitalised', $c->capitalisation_code], 'status' => 'active', 'criticality' => 'medium',
        ]);

        return (int) $asset->id;
    }

    private function assertSubmitted(Capitalisation $c): void
    {
        $c->status === 'submitted' || $this->fail("A {$c->status} capitalisation cannot be decided.");
    }

    private function assertApprover(Capitalisation $c, User $by): void
    {
        (int) $by->organization_id === (int) $c->organization_id || $this->fail('This capitalisation belongs to another bank.', 'approval');
        $by->can(self::PERMISSION) || $this->fail('You need the "'.self::PERMISSION.'" permission to decide a capitalisation.', 'approval');
    }

    private function sameBank(Capitalisation $c, User $by): void
    {
        (int) $c->organization_id === (int) $by->organization_id || $this->fail('That capitalisation belongs to another bank.');
    }
}

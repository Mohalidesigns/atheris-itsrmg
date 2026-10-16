<?php

namespace App\Services\Finance;

use App\Events\Finance\ContractRenewed;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractLink;
use App\Models\Finance\ContractTeamMember;
use App\Models\Finance\Renewal;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contract renewal decisions (FIN-06.05): recommend, submit through Workflow Studio, approve, execute
 * into a successor term, plus the idempotent auto-roll of auto-renewing contracts (plan C66).
 */
class RenewalService
{
    use LogsFinanceEvents;

    public const PROCESS = 'finance.contract_renewal';

    public const PERMISSION = 'approve it-finance-contracts';

    public const TRANSITIONS = [
        'recommend' => ['from' => ['open'], 'to' => 'open'],
        'submit' => ['from' => ['open'], 'to' => 'submitted'],
        'withdraw' => ['from' => ['open', 'submitted'], 'to' => 'withdrawn'],
        'approve' => ['from' => ['submitted'], 'to' => 'approved'], // stays submitted while stages remain
        'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
        'execute' => ['from' => ['approved'], 'to' => 'executed'],
    ];

    /** Attributes carried over from a contract to its successor term. */
    private const CLONED = [
        'title', 'type', 'vendor_id', 'legal_entity_id', 'cost_centre_id', 'budget_line_id', 'category_id', 'owner_id', 'technical_owner_id',
        'procurement_contact_id', 'description', 'notice_period_days', 'renewal_type', 'auto_renew', 'renewal_lead_days', 'notification_offsets',
        'billing_frequency', 'licence_metric', 'licence_quantity', 'deployed_quantity', 'payment_terms_days', 'price_escalation_pct',
        'data_processing_role', 'dpa_status', 'sla_summary', 'exit_terms', 'key_clauses', 'external_reference',
    ];

    public function __construct(
        private readonly ContractService $contracts,
        private readonly ContractStatusDeriver $deriver,
        private readonly CurrencyService $fx,
        private readonly FinanceApprovals $approvals,
    ) {}

    public function open(Contract $c, ?User $by, bool $auto = false): Renewal
    {
        in_array($c->status, Contract::RENEWABLE, true) || $this->fail("A {$c->status} contract cannot be renewed.");
        Renewal::withoutGlobalScopes()->where('contract_id', $c->id)->whereIn('status', Renewal::ACTIVE)->exists()
            && $this->fail('This contract already has a renewal in progress.');

        return DB::transaction(function () use ($c, $by, $auto) {
            $r = Renewal::withoutGlobalScopes()->create([
                'organization_id' => $c->organization_id, 'created_by_id' => $by?->id, 'renewal_code' => Renewal::generateNextCode((int) $c->organization_id),
                'contract_id' => $c->id, 'opened_on' => FinanceClock::today()->toDateString(), 'status' => 'open', 'is_auto' => $auto,
            ]);
            $this->contracts->event($c, 'renewal_opened', ['renewal' => $r->renewal_code], $by);
            $this->audit($r, $by, 'opened', "Renewal {$r->renewal_code} opened for {$c->contract_code}");

            return $r->refresh();
        });
    }

    public function recommend(Renewal $r, array $data, User $by): Renewal
    {
        $this->assertTransition($r, 'recommend');
        $rec = $data['recommendation'] ?? null;
        in_array($rec, Renewal::DECISIONS, true) || $this->fail('Pick a recommendation.', 'recommendation');
        $c = $this->contract($r);
        $orgId = (int) $r->organization_id;

        $attrs = [
            'recommendation' => $rec, 'justification' => $this->text($data['justification'] ?? null), 'benchmark_notes' => $this->text($data['benchmark_notes'] ?? null),
            'alternatives_considered' => $this->text($data['alternatives_considered'] ?? null),
            'proposed_term_months' => null, 'proposed_commences_on' => null, 'proposed_value' => null, 'proposed_currency' => null, 'proposed_fx_rate' => null,
            'proposed_fx_rate_type' => null, 'proposed_fx_rate_date' => null, 'proposed_base_value' => null, 'uplift_pct' => null,
            'uplift_base_pct' => null, 'fx_effect_pct' => null,
        ];
        if ($rec !== 'terminate') {
            $term = (int) ($data['proposed_term_months'] ?? 0);
            ($term < 1 || $term > 120) && $this->fail('Give the proposed term in months (1 to 120).', 'proposed_term_months');
            $rec === 'extend_short_term' && $term > 12 && $this->fail('A short-term extension is at most 12 months.', 'proposed_term_months');
            $attrs['proposed_term_months'] = $term;
            $attrs['proposed_commences_on'] = ! empty($data['proposed_commences_on']) ? CarbonImmutable::parse($data['proposed_commences_on'])->toDateString() : null;
            if ($attrs['proposed_commences_on'] && $attrs['proposed_commences_on'] <= CarbonImmutable::parse($c->ends_on->toDateString())->subDay()->toDateString()) {
                $this->fail('The next term cannot start before the current term ends ('.$c->ends_on->toDateString().').', 'proposed_commences_on');
            }
            $hasValue = isset($data['proposed_value']) && $data['proposed_value'] !== '' && $data['proposed_value'] !== null;
            if (! $hasValue && $rec !== 'replace') {
                $this->fail('Give the proposed value and currency.', 'proposed_value');
            }
            if ($hasValue) {
                $attrs = array_merge($attrs, $this->quote($orgId, (string) $data['proposed_value'], strtoupper((string) ($data['proposed_currency'] ?? $c->currency))));
                $attrs = array_merge($attrs, $this->upliftSet($c, $attrs['proposed_value'], $attrs['proposed_currency'], $attrs['proposed_base_value'], $term));
            }
        }
        $r->fill($attrs);
        $r->requested_by_id ??= $by->id;
        $r->recommended_by_id ??= $by->id; // the first recommender is excluded from approving, whoever submits later
        $r->save();
        $this->audit($r, $by, 'recommended', "Renewal {$r->renewal_code} recommendation recorded", ['recommendation' => $rec]);

        return $r->refresh();
    }

    public function submit(Renewal $r, User $by): Renewal
    {
        $this->assertTransition($r, 'submit');
        $c = $this->contract($r);
        in_array($c->status, Contract::RENEWABLE, true) || $this->fail("A {$c->status} contract cannot be renewed.");
        $r->recommendation || $this->fail('Record a recommendation before submitting.');
        blank($r->justification) && $this->fail('Give a business justification before submitting.', 'justification');

        return DB::transaction(function () use ($r, $by, $c) {
            // Re-quote at submit so the DoA snapshot uses today's rate (the lesson of the P1 supplementary review).
            if ($r->proposed_value !== null) {
                $r->forceFill($this->quote((int) $r->organization_id, (string) $r->proposed_value, (string) $r->proposed_currency));
                $r->forceFill($this->upliftSet($c, (string) $r->proposed_value, (string) $r->proposed_currency, (string) $r->proposed_base_value, (int) $r->proposed_term_months));
            }
            $r->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'requested_by_id' => $by->id, 'rejection_reason' => null])->save();
            $this->approvals->start(self::PROCESS, $r, $by);
            $this->contracts->event($c, 'renewal_submitted', ['renewal' => $r->renewal_code, 'recommendation' => $r->recommendation], $by);
            $this->audit($r, $by, 'submitted', "Renewal {$r->renewal_code} submitted for approval", ['recommendation' => $r->recommendation]);

            return $r->refresh();
        });
    }

    /** Returns a message while stages remain; the final approval records the decision on the contract. */
    public function approve(Renewal $r, User $by, ?string $note = null): string
    {
        $this->assertTransition($r, 'approve');
        $this->assertApprover($r, $by);

        return DB::transaction(function () use ($r, $by, $note) {
            $this->approvals->assertZeroStepSoD(self::PROCESS, $r, $by, 'requested_by_id', 'contract_owner_id', self::PERMISSION);
            if (! $this->approvals->configured(self::PROCESS, (int) $r->organization_id)) {
                $this->approvals->assertDefaultApprover($r, $by, 'requested_by_id', 'contract_owner_id', self::PERMISSION);
            } elseif ($message = $this->approvals->approve(self::PROCESS, $r, $by, $note)) {
                $this->audit($r, $by, 'stage_approved', $message);

                return $message;
            }
            $r->forceFill(['status' => 'approved', 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
            $c = $this->contract($r);
            $c->forceFill(['decision' => $r->recommendation, 'decision_at' => now(), 'decision_by_id' => $by->id])->save();
            $this->contracts->event($c, 'decision_recorded', ['renewal' => $r->renewal_code, 'decision' => $r->recommendation], $by);
            $this->approvals->finalised(self::PROCESS, $r, $by, $note);
            $this->audit($r, $by, 'approved', "Renewal {$r->renewal_code} approved", ['decision' => $r->recommendation]);

            return "Renewal {$r->renewal_code} approved. Execute it to create the next term.";
        });
    }

    public function reject(Renewal $r, User $by, string $note): Renewal
    {
        $this->assertTransition($r, 'reject');
        blank(trim($note)) && $this->fail('Give a reason.', 'note');
        $this->assertApprover($r, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $r->organization_id)) {
            $this->approvals->assertDefaultApprover($r, $by, 'requested_by_id', 'contract_owner_id', self::PERMISSION);
        }
        DB::transaction(function () use ($r, $by, $note) {
            $this->approvals->decline(self::PROCESS, $r, $by, $note, 'rejected');
            $r->forceFill(['status' => 'rejected', 'rejection_reason' => $note])->save();
            $this->contracts->event($this->contract($r), 'renewal_rejected', ['renewal' => $r->renewal_code, 'note' => $note], $by);
            $this->audit($r, $by, 'rejected', "Renewal {$r->renewal_code} rejected", ['note' => $note]);
        });

        return $r->refresh();
    }

    public function withdraw(Renewal $r, User $by): Renewal
    {
        $this->assertTransition($r, 'withdraw');
        $c = $this->contract($r);
        $allowed = (int) $r->requested_by_id === (int) $by->id || (int) $c->owner_id === (int) $by->id
            || (! $r->requested_by_id && (int) $r->created_by_id === (int) $by->id);
        $allowed || $this->fail('Only the requester or the contract owner can withdraw a renewal.');
        DB::transaction(fn () => $this->close($r, $by));

        return $r->refresh();
    }

    /** Withdraws a renewal because the contract is closing (termination or auto-roll). */
    public function withdrawForClosure(Renewal $r, ?User $by): void
    {
        $this->close($r, $by);
    }

    /** Creates the successor term (or closes the contract) for an approved renewal. */
    public function execute(Renewal $r, User $by, array $data = []): ?Contract
    {
        $this->assertTransition($r, 'execute');

        [$old, $new] = DB::transaction(function () use ($r, $by, $data) {
            $old = Contract::withoutGlobalScopes()->lockForUpdate()->findOrFail($r->contract_id);
            $r = Renewal::withoutGlobalScopes()->lockForUpdate()->findOrFail($r->id);
            $this->assertTransition($r, 'execute');
            in_array($old->status, Contract::RENEWABLE, true) || $this->fail("A {$old->status} contract cannot be renewed.");
            $rec = $r->recommendation;
            $new = null;

            if ($rec === 'terminate') {
                $on = ! empty($data['terminated_on']) ? CarbonImmutable::parse($data['terminated_on'])->toDateString() : $old->ends_on->toDateString();
                $old->forceFill(['status' => 'terminated', 'terminated_on' => $on, 'termination_reason' => $r->justification])->save();
                $this->contracts->event($old, 'terminated', ['renewal' => $r->renewal_code, 'terminated_on' => $on], $by);
                $this->audit($old, $by, 'terminated', "Contract {$old->contract_code} terminated by renewal {$r->renewal_code}", ['terminated_on' => $on]);
            } else {
                $term = (int) ($r->proposed_term_months ?: $old->term_months);
                $start = $r->proposed_commences_on ? CarbonImmutable::parse($r->proposed_commences_on->toDateString()) : CarbonImmutable::parse($old->ends_on->toDateString())->addDay();
                if (! empty($data['commenced_on']) && CarbonImmutable::parse($data['commenced_on'])->toDateString() !== $start->toDateString()) {
                    $this->fail('The next term starts on '.$start->toDateString().', the date that was approved. Leave the date out or send a new renewal for approval.', 'commenced_on');
                }
                $money = $r->proposed_value !== null ? [
                    'currency' => $r->proposed_currency, 'contract_value' => $r->proposed_value, 'fx_rate' => $r->proposed_fx_rate,
                    'fx_rate_type' => $r->proposed_fx_rate_type, 'fx_rate_date' => $r->proposed_fx_rate_date?->toDateString(), 'base_contract_value' => $r->proposed_base_value,
                ] : $this->sameMoney($old);
                $replace = $rec === 'replace';
                $new = $this->successor($old, ['title' => $replace ? "Replacement for {$old->contract_code}: {$old->title}" : $old->title] + $money, $start, $term, $by, ! $replace);
                $old->forceFill(['status' => $replace ? 'replaced' : 'renewed', 'renewed_to_id' => $new->id])->save();
                $new->forceFill(['renewed_from_id' => $old->id])->save();
                $this->contracts->event($old, 'renewed', ['renewal' => $r->renewal_code, 'outcome' => $replace ? 'replaced' : 'renewed', 'successor' => $new->contract_code], $by);
                $this->contracts->event($new, 'renewed', ['renewal' => $r->renewal_code, 'from' => $old->contract_code], $by);
                $this->audit($old, $by, $replace ? 'replaced' : 'renewed', "Contract {$old->contract_code} ".($replace ? 'replaced' : 'renewed')." as {$new->contract_code}");
            }
            app(TerminationService::class)->withdrawForClosure($old, $by);
            $r->forceFill(['status' => 'executed', 'executed_by_id' => $by->id, 'executed_at' => now(), 'new_contract_id' => $new?->id])->save();
            $this->audit($r, $by, 'executed', "Renewal {$r->renewal_code} executed", ['successor' => $new?->contract_code]);
            $this->contracts->syncVendorSummary($old->vendor_id);

            return [$old, $new];
        });

        $r->refresh();
        $new && ContractRenewed::dispatch($r, $old->refresh(), $new, false);

        return $new;
    }

    /** Rolls an auto-renewing contract into its next term, exactly once (plan C66, §2.4). */
    public function autoRenew(Contract $c, ?CarbonInterface $today = null): ?Contract
    {
        $today ??= FinanceClock::today();
        $result = DB::transaction(function () use ($c, $today) {
            $c = Contract::withoutGlobalScopes()->lockForUpdate()->find($c->id);
            if (! $c || ! $this->deriver->needsRoll($c, $today)) {
                return null;
            }
            foreach (Renewal::withoutGlobalScopes()->where('contract_id', $c->id)->whereIn('status', Renewal::ACTIVE)->get() as $active) {
                $this->close($active, null);
            }
            app(TerminationService::class)->withdrawForClosure($c, null);
            $orgId = (int) $c->organization_id;
            $factor = bcadd('1', bcdiv((string) ($c->price_escalation_pct ?? '0'), '100', 8), 8);
            $value = bcadd(bcmul((string) $c->contract_value, $factor, 8), '0.005', 2);
            try {
                $money = $this->quote($orgId, $value, $c->currency);
            } catch (ValidationException) { // no rate on file: keep the contract's own rate
                $money = ['proposed_value' => $value, 'proposed_currency' => $c->currency, 'proposed_fx_rate' => (string) $c->fx_rate,
                    'proposed_fx_rate_type' => $c->fx_rate_type, 'proposed_fx_rate_date' => $c->fx_rate_date?->toDateString(),
                    'proposed_base_value' => bcadd(bcmul($value, (string) $c->fx_rate, 8), '0.005', 2)];
            }
            $r = Renewal::withoutGlobalScopes()->create($money + $this->upliftSet($c, $money['proposed_value'], $money['proposed_currency'], $money['proposed_base_value'], (int) $c->term_months) + [
                'organization_id' => $orgId, 'renewal_code' => Renewal::generateNextCode($orgId), 'contract_id' => $c->id,
                'opened_on' => $today->toDateString(), 'recommendation' => 'renew_as_is', 'justification' => "Auto-renewed under the contract's renewal terms",
                'proposed_term_months' => $c->term_months,
                'status' => 'executed', 'is_auto' => true, 'executed_at' => now(),
            ]);
            $start = CarbonImmutable::parse($c->ends_on->toDateString())->addDay();
            $new = $this->successor($c, [
                'currency' => $money['proposed_currency'], 'contract_value' => $money['proposed_value'], 'fx_rate' => $money['proposed_fx_rate'],
                'fx_rate_type' => $money['proposed_fx_rate_type'], 'fx_rate_date' => $money['proposed_fx_rate_date'], 'base_contract_value' => $money['proposed_base_value'],
            ], $start, (int) $c->term_months, null, true, $today);
            $c->forceFill(['status' => 'renewed', 'renewed_to_id' => $new->id, 'decision' => 'renew_as_is', 'decision_at' => now()])->save();
            $new->forceFill(['renewed_from_id' => $c->id])->save();
            $r->forceFill(['new_contract_id' => $new->id])->save();
            $this->contracts->event($c, 'renewed', ['renewal' => $r->renewal_code, 'outcome' => 'auto_renewed', 'successor' => $new->contract_code]);
            $this->contracts->event($new, 'renewed', ['renewal' => $r->renewal_code, 'from' => $c->contract_code, 'auto' => true]);
            $this->audit($r, null, 'auto_renewed', "Contract {$c->contract_code} auto-renewed as {$new->contract_code}", ['renewal' => $r->renewal_code]);
            $this->contracts->syncVendorSummary($c->vendor_id);

            return [$r, $c, $new];
        });
        if (! $result) {
            return null;
        }
        [$r, $c, $new] = $result;
        ContractRenewed::dispatch($r, $c->refresh(), $new, true);

        return $new;
    }

    /**
     * Builds the successor term from $old: same parties, cost centre, budget line, billing, licence, DPA, notice and
     * offsets; team and links are copied. Active successors are derived against $today.
     */
    private function successor(Contract $old, array $overrides, CarbonImmutable $start, int $term, ?User $by, bool $activate, ?CarbonInterface $today = null): Contract
    {
        $attrs = $old->only(self::CLONED);
        $new = new Contract($overrides + $attrs);
        $new->forceFill([
            'organization_id' => $old->organization_id, 'created_by_id' => $by?->id ?? $old->created_by_id,
            'contract_code' => Contract::generateNextCode((int) $old->organization_id), 'status' => 'draft',
            'commenced_on' => $start->toDateString(), 'term_months' => $term, 'ends_on' => $start->addMonthsNoOverflow($term)->subDay()->toDateString(),
            'amount_per_billing' => null,
        ]);
        $this->contracts->computeDates($new);
        $new->save();
        $this->contracts->event($new, 'created', ['code' => $new->contract_code, 'renewed_from' => $old->contract_code], $by);
        $this->audit($new, $by, 'created', "Contract {$new->contract_code} created as the successor of {$old->contract_code}");

        foreach (ContractTeamMember::withoutGlobalScopes()->where('contract_id', $old->id)->get() as $m) {
            ContractTeamMember::withoutGlobalScopes()->create(['organization_id' => $old->organization_id, 'created_by_id' => $by?->id, 'contract_id' => $new->id, 'user_id' => $m->user_id, 'role' => $m->role]);
        }
        foreach (ContractLink::withoutGlobalScopes()->where('contract_id', $old->id)->get() as $l) {
            ContractLink::withoutGlobalScopes()->create(['organization_id' => $old->organization_id, 'created_by_id' => $by?->id, 'contract_id' => $new->id,
                'linkable_type' => $l->linkable_type, 'linkable_id' => $l->linkable_id, 'note' => $l->note]);
        }
        if ($activate) {
            $new->forceFill(['status' => 'active'])->save();
            $this->contracts->event($new, 'status_changed', ['from' => 'draft', 'to' => 'active'], $by);
            $this->deriver->apply($new, $today ?? FinanceClock::today(), $by);
        }

        return $new->refresh();
    }

    private function close(Renewal $r, ?User $by): void
    {
        $wasSubmitted = $r->status === 'submitted';
        $r->forceFill(['status' => 'withdrawn'])->save();
        if ($wasSubmitted) {
            $by ? $this->approvals->withdraw(self::PROCESS, $r, $by) : $this->approvals->cancelQuietly(self::PROCESS, $r, 'Withdrawn: the contract was closed');
        }
        $this->contracts->event($this->contract($r), 'renewal_withdrawn', ['renewal' => $r->renewal_code], $by);
        $this->audit($r, $by, 'withdrawn', "Renewal {$r->renewal_code} withdrawn");
    }

    /** @return array<string,mixed> */
    private function quote(int $orgId, string $value, string $currency): array
    {
        (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $value)) && $this->fail('The proposed value must be a positive amount with up to 2 decimals.', 'proposed_value');
        $conv = $this->fx->convert($orgId, $value, $currency, FinanceClock::today(), 'spot');

        return [
            'proposed_value' => $conv['amount'], 'proposed_currency' => $currency, 'proposed_fx_rate' => $conv['fx_rate'],
            'proposed_fx_rate_type' => $conv['rate_type'], 'proposed_fx_rate_date' => $conv['rate_date'], 'proposed_base_value' => $conv['base_amount'],
        ];
    }

    /**
     * Like-for-like uplift and its FX effect (plan M6). Same currency as the contract: the change is struck in the
     * contract currency, with no base conversion, so a rate move never shows as a price rise. Different currency:
     * the proposal is re-valued at the rate on the contract's own rate date to strip FX out of `uplift_pct`;
     * `uplift_base_pct` is always the plain change in base currency and `fx_effect_pct` is the difference.
     *
     * @return array{uplift_pct:?string,uplift_base_pct:?string,fx_effect_pct:?string}
     */
    private function upliftSet(Contract $c, ?string $proposedValue, ?string $proposedCurrency, ?string $proposedBase, int $term): array
    {
        $none = ['uplift_pct' => null, 'uplift_base_pct' => null, 'fx_effect_pct' => null];
        if ($proposedValue === null || $proposedBase === null) {
            return $none;
        }
        $total = $this->pct((string) $c->annualised_base_value, $this->annual($proposedBase, $term));
        if ($proposedCurrency === $c->currency) {
            $own = $this->pct($this->annual((string) $c->contract_value, (int) $c->term_months), $this->annual($proposedValue, $term));

            return ['uplift_pct' => $own, 'uplift_base_pct' => $total, 'fx_effect_pct' => $own !== null && $total !== null ? bcsub($total, $own, 2) : null];
        }
        $like = $total;
        try {
            $asOf = $c->fx_rate_date ?? $c->commenced_on;
            $constant = $this->fx->convert((int) $c->organization_id, $proposedValue, (string) $proposedCurrency, CarbonImmutable::parse($asOf->toDateString()), (string) ($c->fx_rate_type ?: 'spot'));
            $like = $this->pct((string) $c->annualised_base_value, $this->annual($constant['base_amount'], $term));
        } catch (ValidationException) {
            // no rate on file for the contract's rate date: the base change stands as the like-for-like figure
        }

        return ['uplift_pct' => $like, 'uplift_base_pct' => $total, 'fx_effect_pct' => $like !== null && $total !== null ? bcsub($total, $like, 2) : null];
    }

    private function annual(string $amount, int $term): string
    {
        return bcmul(bcdiv($amount, (string) max($term, 1), 8), '12', 8);
    }

    /** Percentage change from $current to $proposed, 2 dp half-up; null when the current figure is nil. */
    private function pct(string $current, string $proposed): ?string
    {
        if (bccomp($current, '0', 2) <= 0) {
            return null;
        }
        $pct = bcmul(bcdiv(bcsub($proposed, $current, 8), $current, 8), '100', 8);

        return bcadd($pct, bccomp($pct, '0', 8) < 0 ? '-0.005' : '0.005', 2);
    }

    /** @return array<string,mixed> */
    private function sameMoney(Contract $c): array
    {
        return ['currency' => $c->currency, 'contract_value' => $c->contract_value, 'fx_rate' => $c->fx_rate, 'fx_rate_type' => $c->fx_rate_type,
            'fx_rate_date' => $c->fx_rate_date?->toDateString(), 'base_contract_value' => $c->base_contract_value];
    }

    private function contract(Renewal $r): Contract
    {
        return $r->relationLoaded('contract') && $r->contract ? $r->contract : tap(Contract::withoutGlobalScopes()->findOrFail($r->contract_id), fn ($c) => $r->setRelation('contract', $c));
    }

    private function text(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return $v === '' ? null : $v;
    }

    private function assertTransition(Renewal $r, string $action): void
    {
        if (! in_array($r->status, self::TRANSITIONS[$action]['from'], true)) {
            $this->fail("A {$r->status} renewal cannot be ".['recommend' => 'edited', 'submit' => 'submitted', 'withdraw' => 'withdrawn', 'approve' => 'approved', 'reject' => 'rejected', 'execute' => 'executed'][$action].'.');
        }
    }

    private function assertApprover(Renewal $r, User $by): void
    {
        (int) $by->organization_id === (int) $r->organization_id || $this->fail('This renewal belongs to another bank.', 'approval');
        $by->can(self::PERMISSION) || $this->fail('You need the "'.self::PERMISSION.'" permission to decide a renewal.', 'approval');
    }
}

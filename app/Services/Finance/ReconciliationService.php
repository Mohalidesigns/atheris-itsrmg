<?php

namespace App\Services\Finance;

use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Invoice;
use App\Models\Finance\MatchRule;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matches posted actuals to budget lines (FIN-03.05, plan P3-12): saved rules in priority order first, then the cascade contract,
 * vendor + GL, GL, category inside the cost centre's current baseline lines. An ambiguous or empty result leaves the row in the
 * unmatched queue with a reason.
 */
class ReconciliationService
{
    use LogsFinanceEvents;

    public function __construct(private readonly BudgetService $budgets) {}

    /** @return array{line:?BudgetLine,reason:string,rule_id:?int} */
    public function autoMatch(Actual $a): array
    {
        $orgId = (int) $a->organization_id;
        $lines = $this->baselineLines($orgId, (int) $a->fiscal_year_id, [(int) $a->cost_centre_id]);

        foreach (MatchRule::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $rule) {
            if ($rule->cost_centre_id && (int) $rule->cost_centre_id !== (int) $a->cost_centre_id) {
                continue;
            }
            if ($rule->gl_account_id && (int) $rule->gl_account_id !== (int) $a->gl_account_id) {
                continue;
            }
            if ($rule->vendor_id && (int) $rule->vendor_id !== (int) $a->vendor_id) {
                continue;
            }
            if ($rule->contract_id && (int) $rule->contract_id !== (int) $a->contract_id) {
                continue;
            }
            if ($rule->description_contains && ! str_contains(mb_strtolower((string) $a->description), mb_strtolower($rule->description_contains))) {
                continue;
            }
            $target = BudgetLine::withoutGlobalScopes()->find($rule->budget_line_id);
            $target = $target ? $this->budgets->resolveBaselineLine($target) : null;
            if ($target && (int) Budget::withoutGlobalScopes()->whereKey($target->budget_id)->value('fiscal_year_id') === (int) $a->fiscal_year_id) {
                return ['line' => $target, 'reason' => 'rule', 'rule_id' => (int) $rule->id];
            }
        }

        if ($lines->isEmpty()) {
            return ['line' => null, 'reason' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($a->cost_centre_id)->exists() ? 'no_line' : 'unknown_cost_centre', 'rule_id' => null];
        }
        $steps = [
            'contract' => fn () => $a->contract_id ? $this->contractLines($lines, (int) $a->contract_id) : collect(),
            'vendor_gl' => fn () => $a->vendor_id && $a->gl_account_id ? $lines->filter(fn ($l) => (int) $l->vendor_id === (int) $a->vendor_id && (int) $l->gl_account_id === (int) $a->gl_account_id) : collect(),
            'gl' => fn () => $a->gl_account_id ? $lines->filter(fn ($l) => (int) $l->gl_account_id === (int) $a->gl_account_id) : collect(),
            'category' => fn () => $a->category_id ? $lines->filter(fn ($l) => (int) $l->category_id === (int) $a->category_id) : collect(),
        ];
        foreach ($steps as $reason => $find) {
            $hit = $find()->values();
            if ($hit->count() === 1) {
                return ['line' => $hit->first(), 'reason' => $reason, 'rule_id' => null];
            }
            if ($hit->count() > 1) {
                return ['line' => null, 'reason' => 'ambiguous', 'rule_id' => null];
            }
        }

        return ['line' => null, 'reason' => 'no_line', 'rule_id' => null];
    }

    /** Re-runs auto-match over the unmatched queue of GL and manual rows; returns how many found a line. */
    public function runQueue(int $orgId, User $by): int
    {
        $n = 0;
        $open = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'open')->pluck('id')->all();
        foreach ($this->queue($orgId, [])->whereIn('source', ['gl_import', 'manual'])->whereIn('period_id', $open ?: [0])->get() as $a) {
            $r = $this->autoMatch($a);
            if ($r['line']) {
                $a->forceFill(['budget_line_id' => $r['line']->id, 'match_status' => 'matched', 'match_reason' => $r['reason'], 'match_rule_id' => $r['rule_id'], 'matched_by_id' => $by->id, 'matched_at' => now()])->save();
                $r['rule_id'] && MatchRule::withoutGlobalScopes()->whereKey($r['rule_id'])->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);
                $n++;
            } elseif ($a->match_reason !== $r['reason']) {
                $a->forceFill(['match_reason' => $r['reason']])->save();
            }
        }
        $n && activity('it-finance')->causedBy($by)->event('auto_matched')->withProperties(['count' => $n])->log("{$n} actuals auto-matched");

        return $n;
    }

    /** @return list<array{line_id:int,label:string,budget_code:string,line_no:int,current_base_amount:string,score:int,why:list<string>}> */
    public function candidates(Actual $a, int $limit = 10): array
    {
        $orgId = (int) $a->organization_id;
        $tree = $this->tree($orgId, (int) $a->cost_centre_id);
        $out = [];
        foreach ($this->baselineLines($orgId, (int) $a->fiscal_year_id, $tree) as $l) {
            $score = 0;
            $why = [];
            (int) $l->cost_centre_id === (int) $a->cost_centre_id && ($score += 2) && ($why[] = 'same cost centre');
            $a->gl_account_id && (int) $l->gl_account_id === (int) $a->gl_account_id && ($score += 3) && ($why[] = 'same GL account');
            $a->vendor_id && (int) $l->vendor_id === (int) $a->vendor_id && ($score += 2) && ($why[] = 'same vendor');
            $a->category_id && (int) $l->category_id === (int) $a->category_id && ($score += 1) && ($why[] = 'same category');
            $a->contract_id && Contract::withoutGlobalScopes()->whereKey($a->contract_id)->value('budget_line_id') && $this->lineage($l, (int) Contract::withoutGlobalScopes()->whereKey($a->contract_id)->value('budget_line_id')) && ($score += 3) && ($why[] = 'contract line');
            $budget = Budget::withoutGlobalScopes()->find($l->budget_id);
            $out[] = ['line_id' => (int) $l->id, 'label' => "#{$l->line_no} {$l->description}", 'budget_code' => (string) $budget?->budget_code, 'line_no' => (int) $l->line_no,
                'current_base_amount' => (string) $l->current_base_amount, 'score' => $score, 'why' => $why];
        }
        usort($out, fn ($x, $y) => [$y['score'], $x['line_no']] <=> [$x['score'], $y['line_no']]);

        return array_slice($out, 0, $limit);
    }

    /**
     * The invoice actual a GL row duplicates (plan P3-12, P7-9): the key is (reference, vendor when mapped, base amount, posted date)
     * against approved invoice postings. Without an amount and date only the reference is compared (legacy callers).
     */
    public function duplicateOf(int $orgId, ?string $reference, ?string $baseAmount = null, ?string $postedOn = null, ?int $vendorId = null): ?Actual
    {
        foreach ($this->referenceMatches($orgId, $reference) as $a) {
            if ($baseAmount === null && $postedOn === null) {
                return $a;
            }
            $sameAmount = $baseAmount === null || bccomp((string) $a->base_amount, $baseAmount, 2) === 0;
            $sameDate = $postedOn === null || $a->posted_on->toDateString() === $postedOn;
            $sameVendor = ! $vendorId || ! $a->vendor_id || (int) $a->vendor_id === (int) $vendorId;
            if ($sameAmount && $sameDate && $sameVendor) {
                return $a;
            }
        }

        return null;
    }

    /** A reference that matches an approved invoice posting but not its amount or date: flagged, never excluded (P7-9). */
    public function possibleDuplicateOf(int $orgId, ?string $reference): ?Actual
    {
        return $this->referenceMatches($orgId, $reference)->first();
    }

    /** @return Collection<int, Actual> */
    private function referenceMatches(int $orgId, ?string $reference): Collection
    {
        $ref = trim((string) $reference);
        if ($ref === '') {
            return collect();
        }
        $invoiceIds = Invoice::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['approved', 'scheduled', 'paid'])
            ->where(fn ($q) => $q->where('invoice_code', $ref)->orWhere('vendor_invoice_no', $ref)
                ->orWhereIn('po_id', PurchaseOrder::withoutGlobalScopes()->where('organization_id', $orgId)->where('po_code', $ref)->select('id')))->pluck('id');

        return $invoiceIds->isEmpty() ? collect() : Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('source', 'invoice')->whereIn('invoice_id', $invoiceIds)->orderBy('id')->get();
    }

    public function saveRule(array $data, User $by, ?MatchRule $rule = null): MatchRule
    {
        $orgId = (int) $by->organization_id;
        $name = trim((string) ($data['name'] ?? ''));
        $name === '' && $this->fail('Name the rule.', 'name');
        $target = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) ($data['budget_line_id'] ?? 0)) ?? $this->fail('Pick the budget line the rule sends rows to.', 'budget_line_id');
        foreach (['cost_centre_id' => CostCentre::class, 'gl_account_id' => GlAccount::class, 'vendor_id' => Vendor::class, 'contract_id' => Contract::class] as $col => $class) {
            ! empty($data[$col]) && ! $class::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey((int) $data[$col])->exists() && $this->fail('That selection does not belong to this bank.', $col);
        }
        $cond = array_filter([$data['cost_centre_id'] ?? null, $data['gl_account_id'] ?? null, $data['vendor_id'] ?? null, $data['contract_id'] ?? null, trim((string) ($data['description_contains'] ?? ''))]);
        $cond || $this->fail('A rule needs at least one condition.', 'gl_account_id');
        $attrs = [
            'name' => mb_substr($name, 0, 120), 'priority' => (int) ($data['priority'] ?? 100), 'cost_centre_id' => $data['cost_centre_id'] ?? null, 'gl_account_id' => $data['gl_account_id'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null, 'contract_id' => $data['contract_id'] ?? null,
            'description_contains' => ($d = trim((string) ($data['description_contains'] ?? ''))) === '' ? null : mb_substr($d, 0, 120), 'budget_line_id' => $target->id,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
        $rule ??= new MatchRule(['organization_id' => $orgId, 'created_by_id' => $by->id]);
        $rule->forceFill($attrs + ['organization_id' => $orgId])->save();
        $this->audit($rule, $by, 'saved', "Match rule {$rule->name} saved");

        return $rule->refresh();
    }

    public function deleteRule(MatchRule $r, User $by): void
    {
        $this->audit($r, $by, 'deleted', "Match rule {$r->name} deleted");
        $r->delete();
    }

    /** Unmatched actuals of the bank, newest first. */
    public function queue(int $orgId, array $filters): Builder
    {
        return Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('match_status', 'unmatched')
            ->when($filters['fiscal_year_id'] ?? null, fn ($q, $v) => $q->where('fiscal_year_id', $v))
            ->when($filters['period_id'] ?? null, fn ($q, $v) => $q->where('period_id', $v))
            ->when($filters['batch_id'] ?? null, fn ($q, $v) => $q->where('import_batch_id', $v))
            ->orderByDesc('posted_on')->orderByDesc('id');
    }

    /** Current baseline lines of a fiscal year in the given cost centres. @return Collection<int, BudgetLine> */
    public function baselineLines(int $orgId, int $fiscalYearId, array $costCentreIds): Collection
    {
        $budgetIds = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'baseline')->where('fiscal_year_id', $fiscalYearId)->pluck('id');

        return BudgetLine::withoutGlobalScopes()->whereIn('budget_id', $budgetIds)->whereIn('cost_centre_id', $costCentreIds ?: [0])->orderBy('budget_id')->orderBy('line_no')->get();
    }

    /** The cost centre, its ancestors and its descendants. @return list<int> */
    public function tree(int $orgId, int $costCentreId): array
    {
        $all = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'parent_id']);
        $ids = [$costCentreId];
        for ($cur = $all->firstWhere('id', $costCentreId); $cur && $cur->parent_id && count($ids) < 50; $cur = $all->firstWhere('id', $cur->parent_id)) {
            $ids[] = (int) $cur->parent_id;
        }
        $queue = [$costCentreId];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($all->where('parent_id', $id) as $child) {
                if (! in_array((int) $child->id, $ids, true)) {
                    $ids[] = (int) $child->id;
                    $queue[] = (int) $child->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** Lines of $lines that are, or descend from, the contract's budget line. */
    private function contractLines(Collection $lines, int $contractId): Collection
    {
        $lineId = Contract::withoutGlobalScopes()->whereKey($contractId)->value('budget_line_id');
        if (! $lineId) {
            return collect();
        }

        return $lines->filter(fn ($l) => $this->lineage($l, (int) $lineId));
    }

    /** Does $line equal $ancestorId or descend from it through source_line_id? */
    private function lineage(BudgetLine $line, int $ancestorId): bool
    {
        return in_array($ancestorId, app(BudgetLedger::class)->lineageIds($line), true);
    }
}

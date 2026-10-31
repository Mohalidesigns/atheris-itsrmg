<?php

namespace App\Services\Finance;

use App\Models\Finance\Allocation;
use App\Models\Finance\AllocationResult;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Facades\DB;

/**
 * The chargeback journal of a closed period (plan P6-10): one debit per target and cost pool on the receiving ERP cost centre, one credit per
 * source cost centre and cost pool on the IT cost centre, balanced, in base currency. The exported CSV is kept and re-downloadable.
 */
class ChargebackService
{
    use LogsFinanceEvents;

    public const TRANSITIONS = ['void' => ['from' => ['exported'], 'to' => 'voided']];

    public function __construct(private readonly AllocationService $allocations, private readonly FinanceSettings $settings) {}

    /**
     * @return array{lines:list<array<string,mixed>>,total_debit:string,total_credit:string,blocked_reason:?string}
     */
    public function preview(int $orgId, int $periodId, string $targetType): array
    {
        $empty = fn (?string $why) => ['lines' => [], 'total_debit' => '0.00', 'total_credit' => '0.00', 'blocked_reason' => $why];
        $period = Period::withoutGlobalScopes()->where('organization_id', $orgId)->find($periodId);
        if (! $period) {
            return $empty('Pick a period of this bank.');
        }
        if (! $this->settings->for($orgId)->showback_enabled) {
            return $empty('Showback is switched off for this bank (Settings, General).');
        }
        if (! in_array($targetType, Allocation::TARGET_TYPES, true)) {
            return $empty('Pick a showback lens.');
        }
        if ($period->status === 'open') {
            return $empty("{$period->name} is still open; a chargeback journal is exported after the period closes.");
        }
        // M3: a pending re-open request means the closed figures may be about to change.
        if (PeriodClose::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $periodId)->where('reopen_status', 'submitted')->exists()) {
            return $empty("A re-open of {$period->name} is awaiting approval; a chargeback journal cannot be previewed or exported until it is decided.");
        }
        if (ChargebackRun::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $periodId)->where('target_type', $targetType)->where('status', 'exported')->exists()) {
            return $empty('A chargeback run is already exported for this period and lens; void it first.');
        }
        $run = AllocationRun::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $periodId)->where('target_type', $targetType)->where('amount_type', 'actual')->first();
        if (! $run) {
            return $empty('No actual allocation results exist for this period and lens.');
        }

        $rules = Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', AllocationResult::withoutGlobalScopes()->where('run_id', $run->id)->select('allocation_id'))->get()->keyBy('id');
        $results = AllocationResult::withoutGlobalScopes()->where('run_id', $run->id)->orderBy('id')->get()->filter(fn ($r) => bccomp((string) $r->base_amount, '0', 2) !== 0);
        $gl = $this->settings->chargebackGl($orgId);
        $units = collect($this->settings->showbackUnits($orgId))->keyBy('code');
        $ccCodes = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('code', 'id');
        $pools = CostPools::map($orgId)['pools'];
        $labelOf = fn ($pool) => $pool ? ($pools[$pool]['code'] ?? 'pool') : 'unclassified';

        // Debits: per target, receiving cost centre and cost pool.
        $debits = [];
        foreach ($results as $r) {
            $rule = $rules[$r->allocation_id] ?? null;
            if (! $rule) {
                continue;
            }
            $code = $rule->journal_cost_centre_code ?: match ($rule->target_type) {
                'business_unit' => $units[$rule->target_ref]['erp_cost_centre_code'] ?? null,
                'cost_centre' => $ccCodes[$rule->target_id] ?? null,
                default => null,
            };
            if (! $code) {
                return $empty("Rule {$rule->allocation_code} ({$rule->target_label}) needs a journal cost-centre code: set one on the rule.");
            }
            $targetKey = $rule->target_type === 'business_unit' ? 'business_unit:'.$rule->target_ref : $rule->target_type.':'.$rule->target_id;
            $k = $targetKey.'|'.$code.'|'.($r->cost_pool_id ?? 0);
            $debits[$k] ??= ['target_ref' => $targetKey, 'cost_centre_code' => $code, 'pool' => $r->cost_pool_id, 'amount' => '0.00', 'rules' => [], 'label' => $rule->target_label];
            $debits[$k]['amount'] = bcadd($debits[$k]['amount'], (string) $r->base_amount, 2);
            $debits[$k]['rules'][$rule->allocation_code] = true;
        }

        // Credits: each source group and cost pool is credited to its cost centres in proportion to the spend they contributed.
        $evaluated = $this->allocations->pools($orgId, $targetType, $period, 'actual', null);
        $bySource = [];
        foreach ($evaluated['sources'] as $src) {
            $bySource[$src['source_type'].':'.$src['source_id']] = $src;
        }
        $credits = [];
        $perGroupPool = [];
        foreach ($results as $r) {
            $rule = $rules[$r->allocation_id] ?? null;
            $rule && $perGroupPool[$rule->source_type.':'.$rule->source_id][(int) ($r->cost_pool_id ?? 0)] = bcadd($perGroupPool[$rule->source_type.':'.$rule->source_id][(int) ($r->cost_pool_id ?? 0)] ?? '0.00', (string) $r->base_amount, 2);
        }
        foreach ($perGroupPool as $group => $poolAmounts) {
            foreach ($poolAmounts as $poolKey => $total) {
                $weights = $bySource[$group]['by_pool_cc'][(string) $poolKey] ?? [];
                if (! $weights) {
                    return $empty("Cannot trace the source cost centre of {$group}; recompute the allocation results.");
                }
                foreach ($this->allocations->splitByWeights($total, $weights) as $cc => $amt) {
                    $code = $ccCodes[(int) $cc] ?? 'IT';
                    $k = $code.'|'.$poolKey;
                    $credits[$k] ??= ['cost_centre_code' => $code, 'pool' => $poolKey ?: null, 'amount' => '0.00'];
                    $credits[$k]['amount'] = bcadd($credits[$k]['amount'], $amt, 2);
                }
            }
        }

        $lines = [];
        $dr = '0.00';
        $cr = '0.00';
        $n = 0;
        $emit = function (string $gl, string $code, string $amount, string $description, ?string $ref, ?string $rule, bool $debitSide) use (&$lines, &$dr, &$cr, &$n) {
            if (bccomp($amount, '0', 2) === 0) {
                return;
            }
            $asDebit = $debitSide === (bccomp($amount, '0', 2) > 0); // a negative debit is a credit
            $abs = ltrim($amount, '-');
            $lines[] = ['line_no' => ++$n, 'gl_code' => $gl, 'cost_centre_code' => $code, 'debit' => $asDebit ? $abs : '0.00', 'credit' => $asDebit ? '0.00' : $abs,
                'description' => $description, 'target_ref' => $ref, 'rule_code' => $rule];
            $asDebit ? $dr = bcadd($dr, $abs, 2) : $cr = bcadd($cr, $abs, 2);
        };
        foreach ($debits as $d) {
            $emit($gl['debit'], $d['cost_centre_code'], $d['amount'], "IT showback {$period->name}: {$d['label']} / ".$labelOf($d['pool']), $d['target_ref'], implode(',', array_keys($d['rules'])), true);
        }
        foreach ($credits as $c) {
            $emit($gl['credit'], $c['cost_centre_code'], $c['amount'], "IT recharge {$period->name}: ".$labelOf($c['pool']), null, null, false);
        }
        if (! $lines) {
            return $empty('There is nothing to charge back in this period.');
        }

        return ['lines' => $lines, 'total_debit' => $dr, 'total_credit' => $cr, 'blocked_reason' => bccomp($dr, $cr, 2) === 0 ? null : 'The journal does not balance; review the allocation results of this period.'];
    }

    public function export(int $orgId, int $periodId, string $targetType, User $by): ChargebackRun
    {
        (int) $by->organization_id === $orgId || $this->fail('That period belongs to another bank.');

        return DB::transaction(function () use ($orgId, $periodId, $targetType, $by) {
            $period = Period::withoutGlobalScopes()->where('organization_id', $orgId)->lockForUpdate()->find($periodId);
            $p = $this->preview($orgId, $periodId, $targetType);
            $p['blocked_reason'] === null || $this->fail($p['blocked_reason'], 'period_id');
            $currency = $this->settings->baseCurrency($orgId);
            $code = ChargebackRun::generateNextCode($orgId);
            $csv = self::csv($p['lines'], $code, $period->ends_on->toDateString(), $currency);
            $run = ChargebackRun::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'run_code' => $code, 'period_id' => $period->id, 'target_type' => $targetType, 'status' => 'exported',
                'line_count' => count($p['lines']), 'total_debit' => $p['total_debit'], 'total_credit' => $p['total_credit'], 'currency' => $currency,
                'file_sha256' => hash('sha256', $csv), 'content' => $csv, 'exported_by_id' => $by->id, 'exported_at' => now(),
            ]);
            $this->audit($run, $by, 'exported', "Chargeback journal {$run->run_code} exported for {$period->name} ({$targetType})", ['lines' => $run->line_count, 'sha256' => $run->file_sha256]);

            return $run->refresh();
        });
    }

    public function void(ChargebackRun $r, string $reason, User $by): ChargebackRun
    {
        (int) $r->organization_id === (int) $by->organization_id || $this->fail('That run belongs to another bank.');
        (int) $r->exported_by_id !== (int) $by->id || $this->fail('The person who exported a chargeback run cannot void it; ask another period closer.');
        $r->status === 'exported' || $this->fail("A {$r->status} chargeback run cannot be voided.");
        mb_strlen(trim($reason)) >= (int) config('finance.showback.void_reason_min', 10) || $this->fail('Give the reason for voiding (at least '.config('finance.showback.void_reason_min', 10).' characters).', 'reason');
        $r->forceFill(['status' => 'voided', 'voided_by_id' => $by->id, 'voided_at' => now(), 'void_reason' => trim($reason)])->save();
        $this->audit($r, $by, 'voided', "Chargeback journal {$r->run_code} voided", ['reason' => trim($reason)]);

        return $r->refresh();
    }

    /**
     * The journal as CSV. Text cells starting with =, +, - or @ get a leading apostrophe so a spreadsheet cannot run them as formulas.
     *
     * @param  list<array<string,mixed>>  $lines
     */
    public static function csv(array $lines, string $journalRef, string $postingDate, string $currency): string
    {
        $safe = fn ($v) => $v === null ? '' : (preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : (string) $v);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['journal_ref', 'posting_date', 'line_no', 'gl_code', 'cost_centre_code', 'debit', 'credit', 'currency', 'description', 'target_type', 'target_ref', 'rule_code'], ',', '"', '');
        foreach ($lines as $l) {
            $ref = (string) ($l['target_ref'] ?? '');
            [$type, $rest] = str_contains($ref, ':') ? explode(':', $ref, 2) : [$ref === '' ? '' : $ref, ''];
            fputcsv($fh, [
                $safe($journalRef), $postingDate, $l['line_no'], $safe($l['gl_code']), $safe($l['cost_centre_code']), $l['debit'], $l['credit'], $currency,
                $safe($l['description']), $safe($type), $safe($rest), $safe($l['rule_code'] ?? null),
            ], ',', '"', '');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return $out;
    }
}

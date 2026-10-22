<?php

namespace Database\Seeders;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Models\Approvals\ApprovalRequest;
use App\Models\BoardPackTemplate;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Benefit;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\Organization;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\BusinessCaseService;
use App\Services\Finance\ContractSweeper;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\InvestmentChangeService;
use App\Services\Finance\InvestmentFinancials;
use App\Services\Finance\InvestmentScoring;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\StageGateService;
use App\Services\Finance\ValueReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IT Financial Management P5 demo data (ATH-FIN-001 §13, plan P5 §5.1): the IT investment portfolio of each bank, built through the
 * services so the Studio history, gates, scores, ranks, EVM and value reviews are real.
 *
 * - Kano Heritage Bank (10 investments) and First Bank of Nigeria (12): every stage including cancelled, regulatory and transform work,
 *   one gate waiting at the CFO stage and one over-tolerance change waiting at Head of IT per bank, an on-hold investment, a rejected
 *   first business case followed by an approved one, a value review due within 14 days and (First Bank) one overdue.
 * - Managers are cost-centre owners or the CISO and sponsors are business heads holding the Investment Sponsor role. The stage deciders
 *   (headofit@, cfo@, cio@, md@) are never manager or sponsor of an investment: segregation of duties would leave a stage with nobody.
 *   Each bank also gets steerco@ (IT Steering Committee) so the default stage 3 of finance.business_case_approval always has a decider.
 * - Past value-review sends are logged so the first real sweep only notifies the review that is genuinely due.
 * - Budget lines are tagged through InvestmentService::linkLines, so actual cost (AC) comes from the P3 actuals.
 *
 * Guarded per tenant on any Investment. Failures are logged as warnings, never swallowed, and each bank ends with a self-check.
 */
class FinanceInvestmentDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => [
            'prefix' => 'KHB', 'domain' => 'kanoheritage.ng', 'steerco' => 'Hajiya Zainab Bello',
            'who' => ['finacle' => 'finacle', 'digital' => 'digital', 'treasury' => 'treasury.it', 'itops' => 'itops', 'ciso' => 'admin', 'soc' => 'soc.analyst',
                'sysadmin' => 'sysadmin', 'network' => 'network', 'cro' => 'cro', 'dpco' => 'dpco', 'compliance' => 'compliance'],
        ],
        'first-bank-nigeria' => [
            'prefix' => 'FBN', 'domain' => 'firstbanknigeria.ng', 'steerco' => 'Chidi Okonkwo',
            'who' => ['finacle' => 'finacle.app', 'digital' => 'digital', 'treasury' => 'treasury.it', 'itops' => 'itops', 'ciso' => 'ciso', 'soc' => 'soc',
                'sysadmin' => 'sysadmin', 'network' => 'network', 'cro' => 'cro', 'dpco' => 'dpco', 'compliance' => 'compliance'],
        ],
    ];

    private const QUIET = [FinanceApprovalRequested::class, FinanceApprovalDecided::class];

    private const SPONSOR_KEYS = ['itops', 'digital', 'treasury', 'ciso', 'cro', 'dpco', 'compliance'];

    private const NON_FINANCIAL = [
        ['label' => 'Customer experience', 'score' => 4, 'note' => 'Fewer failed transactions and faster resolution.'],
        ['label' => 'Regulatory standing', 'score' => 4, 'note' => 'Closes the open supervisory findings.'],
        ['label' => 'Staff capability', 'score' => 3, 'note' => 'Skills transfer from the vendor.'],
    ];

    private int $orgId;

    private string $prefix;

    private string $domain;

    private CarbonImmutable $today;

    private int $fyId;

    private int $nextFyId;

    /** @var array<string,User> */
    private array $who = [];

    /** @var array<string,User> deciders: headofit, cfo, cio, md, steerco */
    private array $deciders = [];

    /** @var array<string,Investment> */
    private array $made = [];

    /** @var list<array<string,mixed>> */
    private array $specs = [];

    private int $failed = 0;

    private int $serial = 0;

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || ! CostCentre::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }
            $this->orgId = $org->id;
            $this->prefix = $bank['prefix'];
            $this->domain = $bank['domain'];
            $this->made = [];
            $this->failed = 0;
            $this->serial = 0;
            mt_srand(5000 + $this->orgId);
            if (Investment::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                continue;
            }
            try {
                $this->people($bank);
            } catch (Throwable $e) {
                $this->command?->warn("  [{$this->prefix}] finance investment seed skipped: ".$e->getMessage());

                continue;
            }
            Auth::setUser($this->who['finance']);
            Event::fakeFor(fn () => $this->seedBank(), self::QUIET);
        }
        mt_srand();
        Auth::forgetUser();
    }

    /* ------------------------------------------------------------------ helpers */

    private function warn(string $m): void
    {
        $this->failed++;
        $this->command?->warn("  [{$this->prefix}] finance investment seed: {$m}");
    }

    /** Runs one step; a failure is logged (never swallowed) and tells the caller to stop building that investment. */
    private function step(string $label, callable $fn): bool
    {
        try {
            $fn();

            return true;
        } catch (Throwable $e) {
            $msg = $e instanceof ValidationException ? json_encode($e->errors()) : $e->getMessage();
            $this->warn("skipped {$label}: ".mb_substr((string) $msg, 0, 260));

            return false;
        }
    }

    /** Periods of a locked or closed fiscal year refuse some writes; open them for one write and put them back exactly as they were. */
    private function whilePeriodsOpen(callable $fn): mixed
    {
        $closed = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->whereIn('status', ['closed', 'locked'])->pluck('status', 'id');
        Period::withoutGlobalScopes()->whereIn('id', $closed->keys())->update(['status' => 'open']);
        try {
            return $fn();
        } finally {
            foreach ($closed as $id => $status) {
                Period::withoutGlobalScopes()->whereKey($id)->update(['status' => $status]);
            }
        }
    }

    private function people(array $bank): void
    {
        foreach (['finance', 'cfo', 'cio', 'md', 'headofit'] as $key) {
            $user = User::where('email', "{$key}@{$this->domain}")->firstOrFail();
            $key === 'finance' ? $this->who['finance'] = $user : $this->deciders[$key] = $user;
        }
        $steerco = User::updateOrCreate(['email' => "steerco@{$this->domain}"], [
            'name' => $bank['steerco'], 'organization_id' => $this->orgId, 'job_title' => 'Chair, IT Steering Committee', 'department' => 'Executive',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $steerco->forceFill(['email_verified_at' => now()])->save();
        $steerco->syncRoles(['Finance Approver', 'IT Steering Committee']);
        $this->deciders['steerco'] = $steerco;

        foreach ($bank['who'] as $key => $local) {
            $user = User::where('email', "{$local}@{$this->domain}")->firstOrFail();
            if (in_array($key, self::SPONSOR_KEYS, true) && ! $user->hasRole('Investment Sponsor')) {
                $user->assignRole('Investment Sponsor');
            }
            $this->who[$key] = $user;
        }
    }

    /* ------------------------------------------------------------------ main */

    private function seedBank(): void
    {
        $this->today = FinanceClock::today();
        FinancePolicySeeder::ensureDefaults($this->orgId); // the finance.business_case_approval stages, for a bank seeded before P5
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_current', true)->first();
        $next = $fy ? FiscalYear::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('starts_on', '>', $fy->starts_on)->orderBy('starts_on')->first() : null;
        if (! $fy || ! $next) {
            $this->warn('skipped: no current and next fiscal year.');

            return;
        }
        $this->fyId = (int) $fy->id;
        $this->nextFyId = (int) $next->id;
        $this->specs = $this->specsFor($this->prefix);

        foreach ($this->specs as $spec) {
            $this->build($spec);
        }
        $this->step('value-review and benefit status derivation', function () {
            app(ValueReviewService::class)->derive($this->orgId, $this->today);
            app(BenefitsService::class)->deriveAll($this->orgId, $this->today);
        });
        $this->step('review notification history', fn () => $this->notificationHistory());
        $this->step('board pack template', fn () => $this->boardPack());
        $this->selfCheck();
    }

    /**
     * @param  array<string,mixed>  $s
     */
    private function build(array $s): void
    {
        $key = $s['key'];
        $inv = null;
        $svc = app(InvestmentService::class);
        $gates = app(StageGateService::class);
        $fin = app(InvestmentFinancials::class);
        $order = ['idea' => 0, 'business_case' => 1, 'approved' => 2, 'in_delivery' => 3, 'delivered' => 4, 'benefits_tracking' => 5, 'closed' => 6];
        $target = $order[$s['cancel_from'] ?? $s['stage']];
        $bcMode = $s['bc'] ?? null; // draft: stays a draft case; pending: waits at the CFO stage
        $by = $this->who['finance'];
        $this->serial++;

        if (! $this->step("{$key} create", function () use ($s, $svc, &$inv, $by) {
            $inv = $svc->store([
                'title' => $s['title'], 'type' => $s['type'], 'rgt' => $s['rgt'], 'driver' => $s['driver'], 'description' => $s['desc'],
                'sponsor_id' => $this->who[$s['spn']]->id, 'manager_id' => $this->who[$s['mgr']]->id,
                'cost_centre_id' => CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', $s['cc'])->value('id'),
                'fiscal_year_id' => ($s['fy'] ?? 'cur') === 'cur' ? $this->fyId : $this->nextFyId,
                'starts_on' => $this->today->addMonthsNoOverflow($s['start'])->toDateString(),
                'planned_ends_on' => $this->today->addMonthsNoOverflow($s['end'])->toDateString(),
                'is_regulatory' => ! empty($s['reg']), 'regulatory_reference' => $s['reg'] ?? null,
            ], $by);
            $this->recode($inv, 'investment_code');
            $this->made[$s['key']] = $inv;
        })) {
            return;
        }

        // Budget lines of the baseline budgets tagged through the service, so AC comes from the P3 actuals.
        $lineIds = [];
        if (! empty($s['kw']) && ! $this->step("{$key} tag budget lines", function () use ($s, $svc, $inv, $by, &$lineIds) {
            foreach ($s['kw'] as $kw) {
                $id = $this->findLine($kw);
                $id ? ($lineIds[] = $id) : $this->warn("{$s['key']}: no free baseline line matches \"{$kw}\".");
            }
            $lineIds && $svc->linkLines($inv->refresh(), $lineIds, $by);
        })) {
            return;
        }
        $allocated = $lineIds ? (float) $fin->allocated($lineIds) : 0.0;
        $cost = $s['cost'] ?? max(5000000, (int) (round($allocated * ($s['f'] ?? 1.2) / 1000000) * 1000000));

        if (isset($s['scores']) && ! $this->step("{$key} score", fn () => app(InvestmentScoring::class)->score($inv->refresh(), array_combine(Investment::CRITERIA, $s['scores']), $by))) {
            return;
        }
        if ($target < 1) {
            $this->finish($inv, $s);

            return;
        }

        // Business case stage and the business case itself.
        $ok = $this->step("{$key} business case", function () use ($inv, $gates, $s, $cost, $by) {
            $gates->advance($inv->refresh(), 'business_case', ['problem_agreed'], [], 'Problem statement and options agreed with the sponsor.', $by);
            $bcs = app(BusinessCaseService::class);
            $bc = $bcs->createVersion($inv->refresh(), $by);
            $bcs->update($bc, $this->caseData($s, $cost, ! empty($s['rejected_first']) ? 0.45 : 1.0), $by);
        });
        if (! $ok) {
            return;
        }
        if (($s['cancel_from'] ?? null) === 'business_case') {
            $this->step("{$key} cancel", fn () => $svc->cancel($inv->refresh(), $s['cancel'], $by));

            return;
        }
        if ($bcMode === 'draft') {
            $this->finish($inv, $s);

            return;
        }

        // The approval gate: waiting at the CFO, rejected once and approved on the second case, or approved by the full chain.
        if (! empty($s['rejected_first'])) {
            $ok = $this->step("{$key} first business case rejected", function () use ($inv, $gates, $s, $cost, $by) {
                $gate = $gates->advance($inv->refresh(), 'approved', ['options_considered'], [], 'Business case v1 for approval.', $by);
                $this->decide($gate, 1);
                $gates->reject($gate->refresh(), $this->deciders['cfo'], 'Benefits are not evidenced; resubmit with owners and a measurement method for each benefit line.');
                $bcs = app(BusinessCaseService::class);
                $bcs->update($bcs->createVersion($inv->refresh(), $by), $this->caseData($s, $cost, 1.0), $by);
            });
            if (! $ok) {
                return;
            }
        }
        $ok = $this->step("{$key} gate approved", function () use ($inv, $gates, $bcMode, $by) {
            $gate = $gates->advance($inv->refresh(), 'approved', ['options_considered'], [], 'Business case for approval.', $by);
            $this->decide($gate, $bcMode === 'pending' ? 1 : null);
        });
        if (! $ok || $bcMode === 'pending') {
            $this->finish($inv, $s);

            return;
        }
        if (($s['cancel_from'] ?? null) === 'approved') {
            $this->step("{$key} cancel", fn () => $svc->cancel($inv->refresh(), $s['cancel'], $by));

            return;
        }
        if ($target < 3) {
            $this->finish($inv, $s);

            return;
        }

        // Delivery.
        if (! $this->step("{$key} gate in_delivery", fn () => $gates->advance($inv->refresh(), 'in_delivery', ['delivery_plan'], [], 'Delivery plan and governance agreed.', $by))) {
            return;
        }
        if (isset($s['small_change'])) {
            $this->step("{$key} change within tolerance", function () use ($inv, $s) {
                $i = $inv->refresh();
                $this->recode(app(InvestmentChangeService::class)->request($i, [
                    'description' => $s['small_change'], 'reason' => 'Vendor rate revision agreed with procurement; absorbed within the programme tolerance.',
                    'cost_delta' => (string) (int) (round((float) $i->bac * 0.03 / 1000000) * 1000000), 'schedule_delta_days' => 10,
                ], $this->who[$s['mgr']]), 'change_code');
            });
        }
        if ($target === 3) {
            $this->step("{$key} progress", fn () => $this->reportProgress($inv->refresh(), $s['pct'] ?? 50, $s['cpi'] ?? null));
        }
        if (isset($s['big_change'])) {
            $this->step("{$key} change over tolerance", function () use ($inv, $s) {
                $i = $inv->refresh();
                $this->recode(app(InvestmentChangeService::class)->request($i, [
                    'description' => $s['big_change'], 'reason' => 'Scope grew after the vendor design workshop and the regulator widened the requirement; needs re-baselining.',
                    'cost_delta' => (string) (int) (round((float) $i->bac * 0.18 / 1000000) * 1000000), 'schedule_delta_days' => 45,
                ], $this->who[$s['mgr']]), 'change_code');
            });
        }
        if ($target < 4) {
            $this->finish($inv, $s);

            return;
        }

        // Delivered and beyond.
        $end = $this->today->subMonthsNoOverflow($s['end_ago'][0])->addDays($s['end_ago'][1]);
        $ok = $this->step("{$key} gate delivered", function () use ($inv, $gates, $end, $by) {
            $gate = $gates->advance($inv->refresh(), 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => $end->toDateString()], 'Delivered and accepted by the business.', $by);
            $this->decide($gate, null);
        });
        if (! $ok) {
            return;
        }
        $this->step("{$key} progress at delivery", fn () => $svc->progress($inv->refresh(), ['percent_complete' => '100', 'as_of' => $end->toDateString()], $by));
        $benefits = [];
        $ok = $this->step("{$key} benefits", function () use ($inv, $s, $by, &$benefits) {
            foreach ($s['benefits'] as $b) {
                $benefit = app(BenefitsService::class)->store($inv->refresh(), [
                    'title' => $b['title'], 'type' => $b['type'], 'unit' => $b['unit'], 'aggregation' => $b['agg'], 'owner_id' => $this->who[$b['owner']]->id,
                    'measurement_method' => $b['method'], 'baseline_value' => (string) $b['base'], 'target_value' => (string) $b['target'],
                    'due_on' => $this->today->addMonthsNoOverflow($b['due'])->toDateString(),
                ], $by);
                $this->recode($benefit, 'benefit_code');
                $benefits[] = [$benefit->refresh(), $b];
            }
        });
        if (! $ok) {
            return;
        }
        $this->step("{$key} benefit readings", fn () => $this->whilePeriodsOpen(fn () => $this->readings($benefits, $end)));

        if ($target >= 5) {
            if (! $this->step("{$key} gate benefits_tracking", fn () => $gates->advance($inv->refresh(), 'benefits_tracking', [], [], 'Benefit owners and value reviews are in place.', $by))) {
                return;
            }
            $this->reviews($inv, $s);
        }
        if ($target >= 6) {
            $this->step("{$key} gate closed", function () use ($inv, $gates, $by) {
                app(BenefitsService::class)->deriveAll($this->orgId, $this->today);
                $gates->advance($inv->refresh(), 'closed', ['lessons'], [], 'Closed after the final value review.', $by);
            });
        }
        $this->finish($inv, $s);
    }

    /** Hold and links, the last touches per investment. */
    private function finish(Investment $inv, array $s): void
    {
        if (! empty($s['hold'])) {
            $this->step("{$s['key']} hold", fn () => app(InvestmentService::class)->hold($inv->refresh(), $s['hold'], $this->who['finance']));
        }
        if (! empty($s['links'])) {
            $this->step("{$s['key']} links", function () use ($inv, $s) {
                foreach ($s['links'] as $like) {
                    $app = EaApplication::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $this->orgId)->orWhereNull('organization_id'))
                        ->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($like).'%'])->first();
                    $app && app(InvestmentService::class)->addLink($inv->refresh(), 'ea_application', (int) $app->id, 'Application changed by this investment.', $this->who['finance']);
                }
            });
        }
    }

    /* ------------------------------------------------------------------ business cases, progress, benefits, reviews */

    /** @return array<string,mixed> */
    private function caseData(array $s, int $cost, float $benefitFactor): array
    {
        $r = fn (float $v) => number_format(round($v / 100000) * 100000, 2, '.', '');
        $costs = [
            ['year' => 0, 'capex' => $r($cost * 0.55), 'opex' => $r($cost * 0.05), 'run' => '0'],
            ['year' => 1, 'capex' => $r($cost * 0.30), 'opex' => $r($cost * 0.10), 'run' => $r($cost * 0.06)],
        ];
        foreach (range(2, 5) as $y) {
            $costs[] = ['year' => $y, 'capex' => '0', 'opex' => '0', 'run' => $r($cost * 0.06)];
        }
        $benefits = [];
        foreach ([1 => 0.4, 2 => 0.8, 3 => 1, 4 => 1, 5 => 1] as $y => $ramp) {
            $benefits[] = ['year' => $y, 'amount' => $r($cost * ($s['ben'] ?? 0.3) * $ramp * $benefitFactor)];
        }

        return [
            'horizon_years' => 5, 'discount_rate_pct' => '15', 'costs' => $costs, 'benefits' => $benefits,
            'option_summary' => "Option A: do nothing. Option B: {$s['title']} with the incumbent vendor. Option C: phased delivery over two fiscal years.",
            'recommended_option' => 'Option B', 'assumptions' => 'Naira prices at the FY budget rate; benefits ramp over the first three years; run cost is 6 percent of the one-off cost.',
            'risks_summary' => $s['risks'] ?? 'Vendor delivery slippage, change fatigue in the business and dependency on the core banking release calendar.',
            'non_financial_benefits' => self::NON_FINANCIAL,
        ];
    }

    /** The first code of a kind gets the bank prefix ({KHB}-IVT-0001); the generator continues the pattern from the last code. */
    private function recode(Model $m, string $column): void
    {
        $code = (string) $m->{$column};
        if (! str_starts_with($code, $this->prefix.'-')) {
            $m->forceFill([$column => $this->prefix.'-'.$code])->save();
        }
    }

    private function findLine(string $kw): ?int
    {
        $budgets = Budget::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('status', 'baseline')->pluck('id');

        return BudgetLine::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereIn('budget_id', $budgets)->whereNull('investment_id')
            ->whereRaw('LOWER(description) LIKE ?', ['%'.strtolower($kw).'%'])->orderBy('id')->value('id');
    }

    /** Sets percent complete so that CPI lands near the target (EV / AC), falling back to the plain percentage when no cost is booked yet. */
    private function reportProgress(Investment $inv, int|float $pct, ?float $cpi): void
    {
        if ($cpi !== null) {
            $evm = app(InvestmentFinancials::class)->evm($inv);
            if ($evm['bac'] !== null && (float) $evm['bac'] > 0 && (float) $evm['ac'] > 0) {
                $pct = max(3, min(97, $cpi * (float) $evm['ac'] / (float) $evm['bac'] * 100));
            }
        }
        app(InvestmentService::class)->progress($inv, ['percent_complete' => number_format((float) $pct, 2, '.', ''), 'as_of' => $this->today->subDays(3)->toDateString()], $this->who['finance']);
    }

    /**
     * @param  list<array{0:Benefit,1:array<string,mixed>}>  $benefits
     */
    private function readings(array $benefits, CarbonImmutable $deliveredOn): void
    {
        $periods = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)
            ->whereDate('ends_on', '<', $this->today->toDateString())->whereDate('ends_on', '>', $deliveredOn->toDateString())->orderBy('sequence')->get();
        $svc = app(BenefitsService::class);
        foreach ($benefits as [$b, $spec]) {
            if ($periods->isEmpty() || ($spec['pace'] ?? null) === null) {
                continue;
            }
            $n = $periods->count();
            $total = max(1, $n + max(0, (int) $spec['due']));
            foreach ($periods as $k => $p) {
                $jitter = 1 + mt_rand(-8, 8) / 100;
                if ($spec['agg'] === 'sum') {
                    $value = $spec['target'] / $total * $spec['pace'] * $jitter;
                } else {
                    $value = $spec['base'] + ($spec['target'] - $spec['base']) * $spec['pace'] * (($k + 1) / $total) * $jitter;
                }
                $svc->record($b->refresh(), $p, number_format(max(0, $value), 2, '.', ''), null, $k === $n - 1 ? 'Month-end reading from the benefit owner.' : null, $this->who['finance']);
            }
        }
    }

    /** Completes the pir_3m review of the delivered investments that carry one in the past (summary, lessons, cost outcome). */
    private function reviews(Investment $inv, array $s): void
    {
        $svc = app(ValueReviewService::class);
        foreach (ValueReview::withoutGlobalScopes()->where('investment_id', $inv->id)->orderBy('due_on')->get() as $r) {
            $done = ($s['complete_reviews'] ?? 'none');
            $complete = $done === 'all' || ($done === 'first' && $r->review_type === 'pir_3m');
            if (! $complete) {
                continue;
            }
            // The sponsor completes it: never the manager, and never Finance, who requested the delivery gate (PIR independence).
            $sponsor = User::find($inv->sponsor_id) ?? $this->who['finance'];
            $this->step("{$s['key']} complete review {$r->review_type}", function () use ($svc, $r, $sponsor) {
                $out = $svc->complete($r, [
                    'summary' => 'Benefits are tracking against the business case: the first readings are in, owners confirmed the measurement method and the cost outcome is within the approved budget.',
                    'lessons' => 'Name benefit owners before go-live and agree the baseline with Finance in the first month; hand-over to operations took longer than planned.',
                ], $sponsor);
                $when = $out->due_on->addDays(3)->toDateString();
                ValueReview::withoutGlobalScopes()->whereKey($out->id)->update(['completed_on' => $when]);
            });
        }
    }

    /**
     * Approves a gate with the people who can decide each stage in turn (headofit, then the steering stage by cio or steerco, the CFO, the MD).
     * $limit stops after that many approvals, leaving the gate waiting at the next stage.
     */
    private function decide(StageGate $gate, ?int $limit): void
    {
        $engine = app(ApprovalEngine::class);
        $gates = app(StageGateService::class);
        $order = $this->serial % 2 === 0 ? ['headofit', 'cio', 'steerco', 'cfo', 'md'] : ['headofit', 'steerco', 'cio', 'cfo', 'md'];
        $done = 0;
        for ($guard = 0; $guard < 8; $guard++) {
            $gate = $gate->refresh();
            if ($gate->status !== 'submitted' || ($limit !== null && $done >= $limit)) {
                return;
            }
            $request = $engine->current(StageGateService::PROCESS, $gate);
            $step = $request?->currentStep();
            $actor = null;
            foreach ($order as $k) {
                if (! $step || $engine->canDecide($step, $request, $gate, $this->deciders[$k])) {
                    $actor = $this->deciders[$k];
                    break;
                }
            }
            $actor || throw new \RuntimeException("Nobody can decide the {$gate->gate} gate of investment {$gate->investment_id} at \"{$step?->name}\".");
            $gates->approve($gate, $actor, 'Reviewed and supported.');
            $done++;
        }
    }

    /* ------------------------------------------------------------------ history, board pack, self-check */

    private function notificationHistory(): void
    {
        $engine = app(ContractSweeper::class);
        $lead = (int) config('finance.investments.value_review_lead_days');
        $offsets = (array) config('finance.investments.value_review_overdue_offsets');
        foreach (ValueReview::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereIn('status', ['completed', 'overdue'])->get() as $r) {
            $inv = Investment::withoutGlobalScopes()->find($r->investment_id);
            $users = collect([User::find($r->reviewer_id), User::find($inv?->sponsor_id)])->filter()->unique('id')->values();
            $engine->claim($r, 'value_review_due:'.$r->due_on->format('Y-m-d'), $lead, $users);
            if ($r->status === 'overdue') {
                $late = -(int) FinanceClock::daysUntil($r->due_on, $this->today);
                foreach ($offsets as $o) {
                    (int) $o <= $late && $engine->claim($r, 'value_review_overdue:'.$r->due_on->format('Y-m-d'), (int) $o, $users);
                }
            }
        }
    }

    private function boardPack(): void
    {
        $bac = BoardPackTemplate::where('name', 'like', 'BAC%')->first();
        if ($bac && ! in_array('it_investment_portfolio', (array) $bac->sections, true)) {
            $bac->update(['sections' => array_merge((array) $bac->sections, ['it_investment_portfolio'])]);
        } elseif (! $bac) {
            $this->warn('no BAC board pack template to extend.');
        }
    }

    private function selfCheck(): void
    {
        $engine = app(ApprovalEngine::class);
        $inv = Investment::withoutGlobalScopes()->where('organization_id', $this->orgId)->get();
        $expect = count($this->specs);
        $inv->count() === $expect || $this->warn("expected {$expect} investments, found {$inv->count()}.");
        foreach ($this->specs as $s) {
            $row = $inv->firstWhere('title', $s['title']);
            if (! $row) {
                $this->warn("{$s['key']} missing.");

                continue;
            }
            $want = $s['cancel_from'] ?? null ? 'cancelled' : $s['stage'];
            $row->stage === $want || $this->warn("{$s['key']} is at {$row->stage}, expected {$want}.");
            ! empty($s['hold']) && $row->status !== 'on_hold' && $this->warn("{$s['key']} is not on hold.");
            if (in_array($row->manager_id, array_map(fn ($k) => $this->deciders[$k]->id, ['headofit', 'cfo', 'cio', 'md']), true)
                || in_array($row->sponsor_id, array_map(fn ($k) => $this->deciders[$k]->id, ['headofit', 'cfo', 'cio', 'md']), true)) {
                $this->warn("{$s['key']} is managed or sponsored by a stage decider.");
            }
        }
        $has = fn (callable $f, string $what) => $f() ?: $this->warn("missing: {$what}.");
        $has(fn () => $inv->where('stage', 'cancelled')->isNotEmpty(), 'a cancelled investment');
        $has(fn () => $inv->where('is_regulatory', true)->isNotEmpty(), 'a regulatory investment');
        $has(fn () => $inv->where('rgt', 'transform')->isNotEmpty(), 'a transform investment');
        $has(fn () => $inv->where('status', 'on_hold')->isNotEmpty(), 'an on-hold investment');
        $has(fn () => BusinessCase::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('status', 'rejected')->exists()
            && BusinessCase::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('status', 'approved')->exists(), 'a rejected and an approved business case');
        $has(fn () => $inv->where('status', 'off_track')->isNotEmpty(), 'an off-track investment (CPI under 0.85)');
        $has(fn () => Benefit::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereNotNull('realised_pct')->exists(), 'benefit readings');
        $due = ValueReview::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('status', 'due')->get()
            ->filter(fn ($r) => FinanceClock::daysUntil($r->due_on, $this->today) <= 14);
        $due->isEmpty() && $this->warn('missing: a value review due within 14 days.');
        foreach ($due as $r) {
            $recipients = collect([User::find($r->reviewer_id), User::find(Investment::withoutGlobalScopes()->find($r->investment_id)?->sponsor_id)])->filter()->filter(fn ($u) => $u->can('view it-finance'));
            $recipients->isEmpty() && $this->warn("the due review {$r->id} has nobody who can be notified.");
        }
        $this->prefix === 'FBN' && ! ValueReview::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('status', 'overdue')->exists() && $this->warn('missing: an overdue value review.');

        // Every pending stage must have someone who can decide it (plan §6.5).
        $pending = StageGate::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('status', 'submitted')->get();
        foreach (['approved', 'change'] as $gateName) {
            $g = $pending->firstWhere('gate', $gateName);
            if (! $g) {
                $this->warn("missing: a pending {$gateName} gate.");

                continue;
            }
            $request = $engine->current(StageGateService::PROCESS, $g);
            $step = $request?->currentStep();
            $who = $gateName === 'approved' ? 'cfo' : 'headofit';
            if (! $step || ! $engine->canDecide($step, $request, $g, $this->deciders[$who])) {
                $this->warn("the pending {$gateName} gate cannot be decided by {$who}@ (step \"{$step?->name}\").");
            }
        }
        foreach ($pending as $g) {
            $request = ApprovalRequest::withoutGlobalScopes()->where('process', StageGateService::PROCESS)->where('subject_type', StageGate::class)->where('subject_id', $g->id)->latest('id')->first();
            $step = $request?->currentStep();
            $step && $engine->eligibleUsers($step, $request)->filter(fn ($u) => $engine->canDecide($step, $request, $g, $u) && $u->can(StageGateService::PERMISSION))->isEmpty()
                && $this->warn("nobody can decide \"{$step->name}\" of gate {$g->id}.");
        }
        $this->command?->info("  [{$this->prefix}] finance investments: {$inv->count()} investments, ".($this->failed === 0 ? 'no warnings' : "{$this->failed} warning(s)").'.');
    }

    /* ------------------------------------------------------------------ specs */

    /** @return list<array<string,mixed>> */
    private function specsFor(string $prefix): array
    {
        return $prefix === 'KHB' ? $this->khb() : $this->fbn();
    }

    private function khb(): array
    {
        $cbn = 'CBN Risk-Based Cybersecurity Framework and Guidelines for Deposit Money Banks and Payment Service Banks';

        return [
            ['key' => 'K1', 'title' => 'Finacle 11 core banking upgrade', 'type' => 'programme', 'rgt' => 'transform', 'driver' => 'strategic', 'mgr' => 'finacle', 'spn' => 'itops',
                'cc' => 'KHB-IT-APP', 'desc' => 'Upgrade of Finacle 10.2 to Finacle 11 with the new payments and limits modules, retiring custom code.', 'kw' => ['finacle 11 upgrade'], 'f' => 1.25,
                'stage' => 'in_delivery', 'start' => -9, 'end' => 5, 'scores' => [5, 3, 3, 4, 4], 'ben' => 0.28, 'cpi' => 0.80, 'small_change' => 'Additional performance-test cycle requested by the vendor', 'links' => ['finacle']],
            ['key' => 'K2', 'title' => 'CBN Risk-Based Cybersecurity Framework programme: SIEM/SOAR and PAM', 'type' => 'programme', 'rgt' => 'grow', 'driver' => 'regulatory', 'mgr' => 'ciso', 'spn' => 'cro',
                'cc' => 'KHB-SEC', 'desc' => 'Closes the CBN framework gaps with SOAR automation and privileged access management across core banking and the channels.', 'kw' => ['soar automation', 'privileged access'],
                'reg' => $cbn, 'stage' => 'delivered', 'start' => -15, 'end' => -3, 'end_ago' => [3, 9], 'scores' => [4, 5, 5, 2, 3], 'ben' => 0.12, 'f' => 0.7, 'pct' => 100,
                'benefits' => [
                    ['title' => 'Mean time to detect an incident (hours)', 'type' => 'risk_reduction', 'unit' => 'hours', 'agg' => 'latest', 'owner' => 'soc', 'base' => 72, 'target' => 8, 'due' => 6, 'pace' => 0.55, 'method' => 'SIEM incident timestamps, monthly median.'],
                    ['title' => 'Avoided regulatory sanction exposure', 'type' => 'cost_avoidance', 'unit' => 'currency', 'agg' => 'sum', 'owner' => 'ciso', 'base' => 0, 'target' => 60000000, 'due' => 9, 'pace' => 0.4, 'method' => 'Compliance register of closed CBN findings valued at the published penalty scale.'],
                    ['title' => 'Alerts auto-triaged by SOAR (percent)', 'type' => 'productivity', 'unit' => 'pct', 'agg' => 'latest', 'owner' => 'soc', 'base' => 0, 'target' => 60, 'due' => 1, 'pace' => 0.45, 'method' => 'SOAR playbook run statistics.'],
                ]],
            ['key' => 'K3', 'title' => 'PCI DSS v4.0 remediation', 'type' => 'project', 'rgt' => 'run', 'driver' => 'regulatory', 'mgr' => 'soc', 'spn' => 'ciso',
                'cc' => 'KHB-SEC-SOC', 'desc' => 'Remediates the PCI DSS v4.0 future-dated requirements for the card environment ahead of the next QSA assessment.', 'kw' => ['penetration test'],
                'reg' => 'PCI DSS v4.0 (requirements effective 31 March 2025)', 'stage' => 'benefits_tracking', 'start' => -16, 'end' => -5, 'end_ago' => [5, 0], 'scores' => [3, 5, 4, 2, 2], 'ben' => 0.1, 'f' => 0.75,
                'rejected_first' => true, 'pct' => 100, 'complete_reviews' => 'first',
                'benefits' => [
                    ['title' => 'Systems in cardholder data scope', 'type' => 'risk_reduction', 'unit' => 'count', 'agg' => 'latest', 'owner' => 'soc', 'base' => 140, 'target' => 60, 'due' => -1, 'pace' => 0.55, 'method' => 'QSA scoping workbook, quarterly.'],
                    ['title' => 'Annual QSA audit cost saving', 'type' => 'cost_saving', 'unit' => 'currency', 'agg' => 'sum', 'owner' => 'ciso', 'base' => 0, 'target' => 18000000, 'due' => 4, 'pace' => 0.35, 'method' => 'Difference between the prior and current QSA fee notes.'],
                    ['title' => 'PCI compliance score', 'type' => 'compliance', 'unit' => 'score', 'agg' => 'latest', 'owner' => 'ciso', 'base' => 62, 'target' => 95, 'due' => 8, 'pace' => 0.9, 'method' => 'Internal PCI readiness scorecard.'],
                ]],
            ['key' => 'K4', 'title' => 'Mobile banking app re-platform', 'type' => 'project', 'rgt' => 'grow', 'driver' => 'growth', 'mgr' => 'digital', 'spn' => 'itops',
                'cc' => 'KHB-IT-DIG', 'fy' => 'next', 'desc' => 'Rebuilds the mobile app on a single cross-platform stack with biometric onboarding and a modular feature store.', 'cost' => 220000000,
                'stage' => 'business_case', 'bc' => 'pending', 'start' => 2, 'end' => 14, 'scores' => [5, 2, 2, 4, 3], 'ben' => 0.38],
            ['key' => 'K5', 'title' => 'Tier III disaster-recovery site', 'type' => 'project', 'rgt' => 'transform', 'driver' => 'risk_reduction', 'mgr' => 'sysadmin', 'spn' => 'ciso',
                'cc' => 'KHB-IT-DC', 'desc' => 'Moves the DR site to a Tier III facility with dual power and cooling paths and automated failover.', 'cost' => 320000000, 'kw' => ['dr site connectivity', 'precision cooling'],
                'stage' => 'approved', 'start' => 1, 'end' => 12, 'scores' => [4, 3, 5, 2, 3], 'ben' => 0.15, 'hold' => 'Land title for the Abuja site is still with the state ministry; hold until it is released.'],
            ['key' => 'K6', 'title' => 'Agency banking platform', 'type' => 'initiative', 'rgt' => 'grow', 'driver' => 'growth', 'mgr' => 'digital', 'spn' => 'treasury',
                'cc' => 'KHB-IT-DIG', 'fy' => 'next', 'desc' => 'Idea to extend the bank through agent networks in the northern states.', 'stage' => 'idea', 'start' => 4, 'end' => 16],
            ['key' => 'K7', 'title' => 'Robotic process automation in operations', 'type' => 'initiative', 'rgt' => 'run', 'driver' => 'efficiency', 'mgr' => 'itops', 'spn' => 'treasury',
                'cc' => 'KHB-IT-INF', 'fy' => 'next', 'desc' => 'Automates reconciliations, account maintenance and regulatory report assembly in back-office operations.', 'cost' => 95000000,
                'stage' => 'business_case', 'bc' => 'draft', 'start' => 3, 'end' => 12, 'scores' => [3, 1, 2, 4, 2], 'ben' => 0.45],
            ['key' => 'K8', 'title' => 'NDPA privacy tooling and data-subject request automation', 'type' => 'project', 'rgt' => 'run', 'driver' => 'regulatory', 'mgr' => 'ciso', 'spn' => 'dpco',
                'cc' => 'KHB-SEC', 'desc' => 'Consent management, data discovery and automated data-subject request handling for the Nigeria Data Protection Act.', 'kw' => ['regtech'], 'f' => 1.6,
                'reg' => 'Nigeria Data Protection Act 2023 and the NDPC General Application and Implementation Directive', 'stage' => 'in_delivery', 'start' => -8, 'end' => -1, 'scores' => [3, 5, 4, 2, 3], 'ben' => 0.1, 'cpi' => 0.97, 'big_change' => 'Extend discovery to the card and treasury data stores'],
            ['key' => 'K9', 'title' => 'ATM switch replacement', 'type' => 'project', 'rgt' => 'run', 'driver' => 'obsolescence', 'mgr' => 'finacle', 'spn' => 'treasury',
                'cc' => 'KHB-IT-APP', 'desc' => 'Replaced the end-of-life ATM and POS switch with a PCI-certified platform.', 'kw' => ['nibss nip'], 'stage' => 'closed', 'start' => -30, 'end' => -16, 'end_ago' => [16, 0],
                'scores' => [3, 3, 4, 3, 2], 'ben' => 0.32, 'f' => 0.72, 'pct' => 100, 'complete_reviews' => 'all',
                'benefits' => [
                    ['title' => 'ATM uptime (percent)', 'type' => 'customer', 'unit' => 'pct', 'agg' => 'latest', 'owner' => 'finacle', 'base' => 96.5, 'target' => 99.5, 'due' => -4, 'pace' => 1.05, 'method' => 'Switch availability report.'],
                    ['title' => 'Switch licence cost saving', 'type' => 'cost_saving', 'unit' => 'currency', 'agg' => 'sum', 'owner' => 'treasury', 'base' => 0, 'target' => 42000000, 'due' => -2, 'pace' => 1.1, 'method' => 'Licence invoices before and after.'],
                    ['title' => 'Chargeback disputes per month', 'type' => 'customer', 'unit' => 'count', 'agg' => 'latest', 'owner' => 'treasury', 'base' => 380, 'target' => 150, 'due' => -3, 'pace' => 0.7, 'method' => 'Dispute register.'],
                ]],
            ['key' => 'K10', 'title' => 'Contact-centre AI assistant', 'type' => 'initiative', 'rgt' => 'grow', 'driver' => 'efficiency', 'mgr' => 'digital', 'spn' => 'itops',
                'cc' => 'KHB-IT-DIG', 'fy' => 'next', 'desc' => 'Conversational AI to deflect routine contact-centre calls.', 'cost' => 60000000, 'stage' => 'business_case', 'cancel_from' => 'business_case',
                'cancel' => 'The vendor could not meet the data-residency requirement; the board deferred the idea until a local-hosting option exists.', 'start' => 3, 'end' => 12, 'scores' => [3, 1, 1, 3, 4], 'ben' => 0.3],
        ];
    }

    private function fbn(): array
    {
        $cbn = 'CBN Risk-Based Cybersecurity Framework and Guidelines for Deposit Money Banks and Payment Service Banks';

        return [
            ['key' => 'F1', 'title' => 'Finacle upgrade and decoupling programme', 'type' => 'programme', 'rgt' => 'transform', 'driver' => 'strategic', 'mgr' => 'finacle', 'spn' => 'itops',
                'cc' => 'FBN-GT-CBS', 'desc' => 'Upgrades Finacle and decouples payments, limits and customer data into services on the integration platform.', 'kw' => ['finacle upgrade and decoupling'], 'f' => 1.2,
                'stage' => 'in_delivery', 'start' => -10, 'end' => 8, 'scores' => [5, 3, 3, 4, 5], 'ben' => 0.26, 'cpi' => 0.80, 'small_change' => 'Extra regression suite for the Ghana and UK interfaces', 'links' => ['finacle']],
            ['key' => 'F2', 'title' => 'ISO 20022 and NIBSS NIP migration', 'type' => 'project', 'rgt' => 'run', 'driver' => 'regulatory', 'mgr' => 'treasury', 'spn' => 'compliance',
                'cc' => 'FBN-GT-PAY', 'desc' => 'Moves SWIFT and domestic instant-payment flows to ISO 20022 message formats before the cut-over deadlines.', 'kw' => ['nibss nip', 'swift alliance'], 'f' => 1.15,
                'reg' => 'ISO 20022 migration for CBN and NIBSS instant payments (cut-over dates per the clearing house notices)', 'stage' => 'in_delivery', 'start' => -7, 'end' => 4,
                'scores' => [4, 5, 3, 2, 3], 'ben' => 0.1, 'cpi' => 0.93, 'pct' => 60],
            ['key' => 'F3', 'title' => 'Open banking API platform', 'type' => 'project', 'rgt' => 'grow', 'driver' => 'regulatory', 'mgr' => 'digital', 'spn' => 'itops',
                'cc' => 'FBN-GT-DIG', 'desc' => 'Partner-facing API platform with a developer portal, consent management and sandbox, as required by the CBN open banking framework.', 'kw' => ['open banking api'],
                'reg' => 'CBN Regulatory Framework for Open Banking in Nigeria', 'stage' => 'benefits_tracking', 'start' => -15, 'end' => -6, 'end_ago' => [6, 10], 'scores' => [5, 4, 2, 4, 3], 'ben' => 0.35, 'f' => 0.85,
                'rejected_first' => true, 'pct' => 100, 'complete_reviews' => 'first',
                'benefits' => [
                    ['title' => 'API partner revenue', 'type' => 'revenue', 'unit' => 'currency', 'agg' => 'sum', 'owner' => 'digital', 'base' => 0, 'target' => 240000000, 'due' => 6, 'pace' => 0.55, 'method' => 'General ledger fee income of the partner product codes.'],
                    ['title' => 'Partner onboarding cycle time (days)', 'type' => 'productivity', 'unit' => 'count', 'agg' => 'latest', 'owner' => 'digital', 'base' => 45, 'target' => 10, 'due' => 2, 'pace' => 0.6, 'method' => 'Developer portal onboarding timestamps.'],
                    ['title' => 'Fintech partners live', 'type' => 'revenue', 'unit' => 'count', 'agg' => 'latest', 'owner' => 'itops', 'base' => 0, 'target' => 12, 'due' => 5, 'pace' => 0.5, 'method' => 'Partner register, production keys issued.'],
                ]],
            ['key' => 'F4', 'title' => 'Cyber Defence Centre: SOAR and detection engineering', 'type' => 'project', 'rgt' => 'grow', 'driver' => 'regulatory', 'mgr' => 'soc', 'spn' => 'ciso',
                'cc' => 'FBN-GIS-SOC', 'desc' => 'Detection engineering and SOAR playbooks for the Cyber Defence Centre, aligned to the CBN cybersecurity framework.', 'kw' => ['soar and detection'], 'reg' => $cbn,
                'stage' => 'delivered', 'start' => -14, 'end' => -4, 'end_ago' => [4, 0], 'scores' => [4, 5, 5, 2, 3], 'ben' => 0.12, 'f' => 0.9, 'pct' => 100,
                'benefits' => [
                    ['title' => 'Mean time to respond (hours)', 'type' => 'risk_reduction', 'unit' => 'hours', 'agg' => 'latest', 'owner' => 'soc', 'base' => 24, 'target' => 4, 'due' => 3, 'pace' => 0.5, 'method' => 'SOAR case timestamps, monthly median.'],
                    ['title' => 'Analyst hours saved', 'type' => 'productivity', 'unit' => 'hours', 'agg' => 'sum', 'owner' => 'soc', 'base' => 0, 'target' => 1800, 'due' => 1, 'pace' => 0.3, 'method' => 'SOAR automation run log multiplied by the manual effort per playbook.'],
                ]],
            ['key' => 'F5', 'title' => 'Cloud landing zone', 'type' => 'project', 'rgt' => 'transform', 'driver' => 'efficiency', 'mgr' => 'sysadmin', 'spn' => 'itops',
                'cc' => 'FBN-GT-DC', 'desc' => 'Governed multi-account cloud landing zone with network, identity and logging guardrails.', 'cost' => 280000000,
                'stage' => 'approved', 'start' => 1, 'end' => 11, 'scores' => [4, 2, 3, 3, 3], 'ben' => 0.22, 'hold' => 'Waiting for the board decision on the cloud-hosting policy and the CBN cloud notification.'],
            ['key' => 'F6', 'title' => 'Zero-trust network access', 'type' => 'project', 'rgt' => 'transform', 'driver' => 'risk_reduction', 'mgr' => 'network', 'spn' => 'ciso',
                'cc' => 'FBN-GT-NET', 'fy' => 'next', 'desc' => 'Replaces branch VPN with identity-aware access and micro-segmentation of the data centres.', 'cost' => 150000000,
                'stage' => 'business_case', 'bc' => 'draft', 'start' => 4, 'end' => 15, 'scores' => [4, 3, 5, 2, 4], 'ben' => 0.2],
            ['key' => 'F7', 'title' => 'Temenos payments hub', 'type' => 'programme', 'rgt' => 'transform', 'driver' => 'strategic', 'mgr' => 'treasury', 'spn' => 'digital',
                'cc' => 'FBN-GT-PAY', 'fy' => 'next', 'desc' => 'Single payments hub for domestic, cross-border and mobile-money flows on Temenos.', 'cost' => 410000000,
                'stage' => 'business_case', 'bc' => 'pending', 'start' => 3, 'end' => 20, 'scores' => [5, 3, 3, 4, 4], 'ben' => 0.3],
            ['key' => 'F8', 'title' => 'Enterprise data lake and analytics platform', 'type' => 'initiative', 'rgt' => 'grow', 'driver' => 'growth', 'mgr' => 'itops', 'spn' => 'digital',
                'cc' => 'FBN-GT-DATA', 'fy' => 'next', 'desc' => 'Idea for a governed data lake feeding risk, finance and customer analytics.', 'stage' => 'idea', 'start' => 5, 'end' => 18, 'scores' => [4, 2, 2, 3, 3]],
            ['key' => 'F9', 'title' => 'Branch SD-WAN rollout (750 branches)', 'type' => 'project', 'rgt' => 'run', 'driver' => 'efficiency', 'mgr' => 'network', 'spn' => 'itops',
                'cc' => 'FBN-GT-NET', 'desc' => 'Replaced branch MPLS with SD-WAN and local internet breakout.', 'kw' => ['sd-wan'], 'f' => 0.7, 'stage' => 'closed', 'start' => -34, 'end' => -15, 'end_ago' => [15, 0],
                'scores' => [3, 2, 3, 4, 3], 'ben' => 0.3, 'pct' => 100, 'complete_reviews' => 'all',
                'benefits' => [
                    ['title' => 'Branch WAN cost saving', 'type' => 'cost_saving', 'unit' => 'currency', 'agg' => 'sum', 'owner' => 'network', 'base' => 0, 'target' => 96000000, 'due' => -3, 'pace' => 0.9, 'method' => 'Carrier invoices before and after.'],
                    ['title' => 'Branch link availability (percent)', 'type' => 'customer', 'unit' => 'pct', 'agg' => 'latest', 'owner' => 'network', 'base' => 97, 'target' => 99.7, 'due' => -4, 'pace' => 1.02, 'method' => 'Network monitoring availability report.'],
                ]],
            ['key' => 'F10', 'title' => 'Ghana core banking consolidation', 'type' => 'project', 'rgt' => 'transform', 'driver' => 'efficiency', 'mgr' => 'itops', 'spn' => 'digital',
                'cc' => 'FBN-GT-GH', 'desc' => 'Consolidates the Ghana subsidiary onto the group Finacle instance.', 'cost' => 130000000, 'stage' => 'approved', 'cancel_from' => 'approved',
                'cancel' => 'The Bank of Ghana asked for local data residency, which removes the consolidation benefit; the board cancelled the project.', 'start' => 1, 'end' => 14, 'scores' => [3, 2, 2, 3, 4], 'ben' => 0.25],
            ['key' => 'F11', 'title' => 'Data loss prevention rollout (NDPA)', 'type' => 'project', 'rgt' => 'run', 'driver' => 'regulatory', 'mgr' => 'ciso', 'spn' => 'dpco',
                'cc' => 'FBN-GIS', 'desc' => 'Endpoint, email and cloud data loss prevention with policy tuning for personal data under the NDPA.', 'kw' => ['data loss prevention'], 'f' => 1.4,
                'reg' => 'Nigeria Data Protection Act 2023 and the NDPC General Application and Implementation Directive', 'stage' => 'in_delivery', 'start' => -6, 'end' => 3,
                'scores' => [3, 5, 4, 2, 3], 'ben' => 0.1, 'cpi' => 1.02, 'big_change' => 'Add the Ghana and UK subsidiaries to the DLP scope'],
            ['key' => 'F12', 'title' => 'Customer 360 master data', 'type' => 'initiative', 'rgt' => 'grow', 'driver' => 'growth', 'mgr' => 'itops', 'spn' => 'treasury',
                'cc' => 'FBN-GT-DATA', 'fy' => 'next', 'desc' => 'Idea for a single customer master across retail, SME and the subsidiaries.', 'stage' => 'idea', 'start' => 6, 'end' => 20],
        ];
    }
}

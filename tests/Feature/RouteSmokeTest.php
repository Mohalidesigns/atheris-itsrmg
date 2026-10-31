<?php

namespace Tests\Feature;

use App\Http\Controllers\Finance\BudgetController;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Asset;
use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\Allocation;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\Benefit;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetComment;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetScenario;
use App\Models\Finance\BudgetTarget;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\CloudCostLine;
use App\Models\Finance\CloudTagRule;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractDocument;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\ContractLink;
use App\Models\Finance\CostCentre;
use App\Models\Finance\DoaBandVersion;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Forecast;
use App\Models\Finance\FxRate;
use App\Models\Finance\GoodsReceiptLine;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Investment;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\RecurringExpense;
use App\Models\Finance\Renewal;
use App\Models\Finance\Setting;
use App\Models\Finance\StageGate;
use App\Models\Finance\SupplementaryRequest;
use App\Models\Finance\Termination;
use App\Models\Finance\ValueReview;
use App\Models\Finance\VarianceNote;
use App\Models\Issue;
use App\Models\Kri;
use App\Models\KriReading;
use App\Models\Risk;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetService;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\FinanceCalendarService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\Reports\FinanceReports;
use App\Services\Finance\ShowbackService;
use App\Services\Finance\StageGateService;
use Carbon\Carbon;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Full-application smoke test: hits every parameterless authenticated GET
 * route as a seeded Organization Admin and fails on any 5xx response.
 *
 * Requires a SEEDED database (php artisan migrate:fresh --seed), so it is
 * skipped unless explicitly enabled:
 *
 *   SMOKE=1 php artisan test --filter RouteSmokeTest
 *
 * (Point DB_DATABASE at your seeded database when running it.)
 */
class RouteSmokeTest extends TestCase
{
    public function test_all_parameterless_get_routes_do_not_500(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $admin = User::where('email', 'admin@kanoheritage.ng')->first();
        $this->assertNotNull($admin, 'Seeded database required — run php artisan migrate:fresh --seed first.');

        $failures = [];
        $tested = 0;

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods())) {
                continue;
            }
            $uri = $route->uri();
            if (str_contains($uri, '{')) {
                continue; // skip parameterised routes
            }
            if (preg_match('#^(api|_|sanctum|up$|storage)#', $uri)) {
                continue;
            }
            $middleware = implode(',', $route->gatherMiddleware());
            if (! str_contains($middleware, 'auth')) {
                continue; // only authenticated app routes
            }

            $response = $this->actingAs($admin)->get('/'.ltrim($uri, '/'));
            $tested++;
            if ($response->getStatusCode() >= 500) {
                $failures[] = $uri.' => '.$response->getStatusCode();
            }
        }

        fwrite(STDERR, "\nTested {$tested} GET routes\n");
        $this->assertSame([], $failures, 'Routes returning 5xx: '.implode('; ', $failures));
    }

    /**
     * IT Financial Management (plan §5): every parameterless finance.* GET route, and each Settings
     * tab, must render 200 for every demo tenant, with the seeded estate the spec promises.
     */
    public function test_finance_pages_render_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $urls = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.') && ! in_array($r->getName(), ['finance.calendar.ics', 'finance.expenses.availability', 'finance.monitor.tco.detail', 'finance.monitor.showback.export'], true) && in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'))
            ->map(fn (RouteDefinition $r) => route($r->getName()))->values()->all();
        $this->assertNotEmpty($urls);

        $tabs = ['general', 'calendar', 'cost-centres', 'categories', 'gl-accounts', 'currencies', 'taxes', 'drivers', 'doa', 'variance', 'renewal'];
        $expect = [
            'admin@kanoheritage.ng' => fn (Assert $p) => $p->has('costCentres', 10)->where('fiscalYears', fn ($y) => count($y) >= 2)
                ->where('costCentres', fn ($c) => collect($c)->contains(fn ($x) => $x['owner_id'] === null)),
            'admin@firstbanknigeria.ng' => fn (Assert $p) => $p->has('costCentres', 12)
                ->where('costCentres', fn ($c) => collect($c)->contains(fn ($x) => $x['legal_entity'] !== null) && collect($c)->contains(fn ($x) => $x['owner_id'] === null)),
            'admin@acme.ng' => fn (Assert $p) => $p->has('costCentres', 0)->has('fiscalYears', 0)->has('categories', 49),
        ];

        foreach ($expect as $email => $assertSeeded) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "{$email} missing: seed the database first.");

            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
            foreach ($tabs as $tab) {
                $this->actingAs($user)->get(route('finance.settings', ['tab' => $tab]))->assertOk()
                    ->assertInertia(fn (Assert $p) => $p->component('Finance/Settings')->where('tab', $tab));
            }
            $this->actingAs($user)->get(route('finance.settings'))->assertOk()->assertInertia($assertSeeded);
        }

        $finance = User::where('email', 'finance@kanoheritage.ng')->first();
        $this->assertNotNull($finance);
        $this->assertTrue($finance->hasRole('IT Finance Controller'));

        // Segregation of duties in the demo estate: no budget owner also approves (ATH-FIN-001 §6).
        $conflicts = User::withoutGlobalScopes()->role('Finance Approver')->get()
            ->filter(fn (User $u) => $u->hasRole('IT Budget Owner'))->pluck('email')->all();
        $this->assertSame([], $conflicts);
    }

    /**
     * IT Financial Management P1 (plan §5.3): the seeded budgeting estate of each demo bank renders on every
     * page and tab, carries every lifecycle state, and has no approval stranded without someone able to decide it.
     */
    public function test_finance_budgeting_estate_is_seeded_and_renders_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $banks = ['kano-heritage-bank' => 'admin@kanoheritage.ng', 'first-bank-nigeria' => 'admin@firstbanknigeria.ng'];
        $activeBands = [];

        foreach ($banks as $slug => $email) {
            $admin = User::where('email', $email)->firstOrFail();
            $org = $admin->organization_id;
            $this->actingAs($admin);
            $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $org)->where('is_current', true)->firstOrFail();
            $next = FiscalYear::withoutGlobalScopes()->where('organization_id', $org)->where('starts_on', '>', $fy->starts_on->toDateString())->orderBy('starts_on')->firstOrFail();
            $budgets = Budget::withoutGlobalScopes()->where('organization_id', $org)->get();

            // parameterised pages: every budget, every tab, the workbook page and the CSV export
            foreach ($budgets as $b) {
                foreach (BudgetController::TABS as $tab) {
                    $this->get(route('finance.budgets.show', [$b, 'tab' => $tab]))->assertOk();
                }
                $this->get(route('finance.budgets.edit', $b))->assertOk();
                $this->get(route('finance.budgets.workbook', $b))->assertOk();
            }
            $this->get(route('finance.budgets.workbook', [$budgets->first(), 'format' => 'xlsx']))->assertSessionHasErrors('format');
            $this->get(route('finance.budgets.index', ['all_versions' => 1]))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Budgets/Index')->has('budgets', $budgets->count()));
            foreach (BudgetCycle::withoutGlobalScopes()->where('organization_id', $org)->pluck('id') as $cycleId) {
                $this->get(route('finance.targets.index', ['cycle_id' => $cycleId]))->assertOk();
            }

            // FY baselines: several bands, 50+ lines bank-wide, and one mid-year revision with a working diff
            $baselines = $budgets->where('fiscal_year_id', $fy->id)->where('status', 'baseline');
            $this->assertGreaterThanOrEqual(8, $baselines->count(), "{$slug} baselines");
            $lines = BudgetLine::withoutGlobalScopes()->whereIn('budget_id', $budgets->where('fiscal_year_id', $fy->id)->pluck('id'))->count();
            $this->assertGreaterThanOrEqual(50, $lines, "{$slug} FY baseline lines");
            $this->assertGreaterThanOrEqual(3, $baselines->map->amount_band->unique()->count(), "{$slug} spreads across DoA bands");
            $v1 = $budgets->where('fiscal_year_id', $fy->id)->firstWhere('status', 'revised');
            $this->assertNotNull($v1, "{$slug} mid-year revision");
            $v2 = $budgets->firstWhere('id', $v1->superseded_by_id);
            $this->assertSame('baseline', $v2->status);
            $this->get(route('finance.budgets.diff', [$v2, 'against' => $v1->id]))->assertOk()
                ->assertJsonPath('a.version_no', 1)->assertJsonPath('b.version_no', 2)->assertJson(fn ($j) => $j->has('lines')->has('totals')->etc());
            $this->assertSame(3, collect($this->getJson(route('finance.budgets.diff', [$v2, 'against' => $v1->id]))->json('lines'))->where('change', 'changed')->count());

            // FY+1 budgets: every lifecycle state, a returned draft with line comments, an unowned draft, promoted and unpromoted scenarios
            $pipeline = $budgets->where('fiscal_year_id', $next->id);
            foreach (['draft', 'submitted', 'under_review', 'approved', 'rejected'] as $status) {
                $this->assertTrue($pipeline->contains('status', $status), "{$slug} has a {$status} FY+1 budget");
            }
            $this->assertTrue($pipeline->contains(fn ($b) => $b->status === 'draft' && $b->owner_id === null), "{$slug} has an unowned draft (deliberate gap)");
            $this->assertGreaterThanOrEqual(2, BudgetComment::withoutGlobalScopes()->where('organization_id', $org)->where('kind', 'return')->whereNotNull('budget_line_id')->count());
            $this->assertSame([1, 1], [BudgetScenario::withoutGlobalScopes()->where('organization_id', $org)->where('is_promoted', true)->count(),
                BudgetScenario::withoutGlobalScopes()->where('organization_id', $org)->where('is_promoted', false)->count()]);
            $this->assertTrue(BudgetLine::withoutGlobalScopes()->whereIn('budget_id', $pipeline->pluck('id'))->where('priority', 'discretionary')->whereNull('justification')->whereNull('vendor_id')->exists(), 'a discretionary line without justification (gap)');

            // exactly one ceiling is deliberately breached
            $over = $pipeline->filter(fn ($b) => app(BudgetService::class)->checkCeiling($b)['over']);
            $this->assertGreaterThanOrEqual(1, $over->count(), "{$slug} breached ceiling");
            $this->assertSame(1, BudgetTarget::withoutGlobalScopes()->where('organization_id', $org)->whereNull('category_id')->get()
                ->filter(fn ($t) => app(BudgetCycleService::class)->usageRow($t, app(BudgetCycleService::class)->cycleLines(BudgetCycle::withoutGlobalScopes()->find($t->cycle_id)))['over'])
                ->filter(fn ($t) => $t->cost_centre_id === CostCentre::withoutGlobalScopes()->where('organization_id', $org)->where('code', 'like', '%-DC')->value('id'))->count());

            // transfers and supplementary requests in every state; applied ones are net zero
            $transfers = BudgetTransfer::withoutGlobalScopes()->where('organization_id', $org)->get();
            foreach (['applied', 'submitted', 'approved', 'rejected', 'draft'] as $status) {
                $this->assertTrue($transfers->contains('status', $status), "{$slug} {$status} transfer");
            }
            foreach ($transfers->where('status', 'applied') as $t) {
                $this->assertSame('0.00', bcadd((string) BudgetAdjustment::withoutGlobalScopes()->where('source_type', 'transfer')->where('source_id', $t->id)->sum('base_amount'), '0', 2));
            }
            $this->assertTrue($transfers->contains(fn ($t) => $t->crosses_cost_centre === 'yes' && $t->status === 'applied'), 'a cross-cost-centre transfer was applied');
            $supp = SupplementaryRequest::withoutGlobalScopes()->where('organization_id', $org)->get();
            foreach (['applied', 'submitted', 'draft'] as $status) {
                $this->assertTrue($supp->contains('status', $status), "{$slug} {$status} supplementary request");
            }
            $applied = $supp->firstWhere('status', 'applied');
            $this->assertSame('supplementary', BudgetLine::withoutGlobalScopes()->find($applied->resulting_line_id)->origin);
            $this->get(route('finance.transfers.index', ['fiscal_year_id' => $fy->id]))->assertOk()->assertInertia(fn (Assert $p) => $p->has('transfers', $transfers->count()));
            $this->get(route('finance.supplementary.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', $supp->count()));

            // a pending request for each of the three finance processes, and someone who can actually decide each current step
            $engine = app(ApprovalEngine::class);
            foreach (['finance.budget_approval', 'finance.budget_transfer', 'finance.supplementary_budget'] as $process) {
                $pending = ApprovalRequest::withoutGlobalScopes()->where('organization_id', $org)->where('process', $process)->where('status', 'pending')->get();
                $this->assertGreaterThanOrEqual(1, $pending->count(), "{$slug} pending {$process}");
                foreach ($pending as $request) {
                    $step = $request->currentStep();
                    $subject = $request->subject_type::withoutGlobalScopes()->find($request->subject_id);
                    $deciders = $engine->eligibleUsers($step, $request)->filter(fn (User $u) => $u->can('approve it-finance') && $engine->canDecide($step, $request, $subject, $u));
                    $this->assertGreaterThan(0, $deciders->count(), "{$slug}: nobody can decide \"{$step->name}\" of {$request->subject_label}");
                }
            }
            $this->assertSame(10, ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $org)->where('process', 'like', 'finance.%')->count());

            // DoA versions: the bank's own bands are active and each step is audited
            $active = DoaBandVersion::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'active')->firstOrFail();
            $activeBands[$slug] = array_column($active->bands, 'key');
            $this->assertCount(5, $active->bands);
            $this->assertSame($active->bands, Setting::withoutGlobalScopes()->where('organization_id', $org)->first()->doa_bands);
            $this->assertSame(1, DoaBandVersion::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'superseded')->count());
            $this->assertSame($slug === 'kano-heritage-bank' ? 1 : 0, DoaBandVersion::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'pending')->count());
            $this->assertTrue(Activity::where('log_name', 'it-finance')->where('event', 'doa_bands.activated')->where('properties->new_bands', '!=', null)->exists());

            // the budget-rate and closing-rate rows exist per type
            $this->assertTrue(FxRate::withoutGlobalScopes()->where('organization_id', $org)->where('rate_type', 'budget')->where('from_currency', 'USD')->count() >= 2);
            $this->assertTrue(FxRate::withoutGlobalScopes()->where('organization_id', $org)->where('rate_type', 'closing')->exists());

            // the users the demo script relies on
            $domain = substr($email, strpos($email, '@') + 1);
            foreach (['finance', 'procurement', 'md', 'headofit', 'cfo', 'cio'] as $key) {
                $this->assertNotNull(User::where('email', "{$key}@{$domain}")->first(), "{$key}@{$domain}");
            }
            $this->assertTrue(User::where('email', "md@{$domain}")->first()->hasRole('Finance Approver'));
            $this->assertFalse(User::where('email', "headofit@{$domain}")->first()->hasRole('IT Budget Owner'), 'the Head of IT approver owns no cost centre');
            $this->assertSame(0, CostCentre::withoutGlobalScopes()->where('organization_id', $org)->where('owner_id', User::where('email', "headofit@{$domain}")->value('id'))->count());

            // a budget owner sees only their own cost centres
            $owner = User::where('email', 'itops@'.$domain)->first();
            $this->actingAs($owner)->get(route('finance.budgets.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('budgets', fn ($rows) => collect($rows)->every(
                fn ($r) => in_array($r['cost_centre']['id'], FinancePolicy::visibleCostCentreIds($owner), true))));
        }

        $this->assertNotSame($activeBands['kano-heritage-bank'], $activeBands['first-bank-nigeria'], 'each bank has its own DoA bands');

        // a bank with no finance data renders empty pages, not errors
        $acme = User::where('email', 'admin@acme.ng')->first();
        $this->actingAs($acme)->get(route('finance.budgets.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('budgets', 0));
        $this->get(route('finance.transfers.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('transfers', 0));
        $this->get(route('finance.budgets.create'))->assertOk();
    }

    /**
     * IT Financial Management P2 (plan §6.2): each demo bank's contract register is seeded with every status, a contract in
     * every calendar lane and renewals in every stage; every page and tab renders; no pending approval step is stranded.
     */
    public function test_finance_contracts_estate_is_seeded_and_renders_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $engine = app(ApprovalEngine::class);
        foreach (['kano-heritage-bank' => 'admin@kanoheritage.ng', 'first-bank-nigeria' => 'admin@firstbanknigeria.ng'] as $slug => $email) {
            $admin = User::where('email', $email)->firstOrFail();
            $org = $admin->organization_id;
            $this->actingAs($admin);
            $contracts = Contract::withoutGlobalScopes()->where('organization_id', $org)->get();

            // 25 to 40 contracts, every status, and the deliberate gaps
            $this->assertGreaterThanOrEqual(25, $contracts->count(), "{$slug} contracts");
            $this->assertLessThanOrEqual(40, $contracts->count(), "{$slug} contracts");
            foreach (Contract::STATUSES as $status) {
                $this->assertTrue($contracts->contains('status', $status), "{$slug} has a {$status} contract");
            }
            $this->assertTrue($contracts->contains(fn ($c) => $c->owner_id === null && ! $c->is_terminal), "{$slug} an unowned contract");
            $this->assertTrue($contracts->contains(fn ($c) => $c->licence_position === 'over'), "{$slug} an over-licensed contract");
            $this->assertTrue($contracts->contains(fn ($c) => $c->data_processing_role === 'processor' && $c->dpa_status === 'pending'), "{$slug} a pending DPA");
            $this->assertTrue($contracts->contains(fn ($c) => $c->is_auto && $c->status === 'active'), "{$slug} an auto-renewing contract");
            $this->assertTrue($contracts->contains(fn ($c) => $c->renewed_from_id && Renewal::withoutGlobalScopes()->where('new_contract_id', $c->id)->where('is_auto', true)->exists()), "{$slug} an auto-rolled contract");
            $this->assertTrue($contracts->contains(fn ($c) => $c->status === 'in_renewal_window' && ! Renewal::withoutGlobalScopes()->where('contract_id', $c->id)->whereIn('status', Renewal::ACTIVE)->exists()), "{$slug} a window contract with no renewal yet");
            $this->assertTrue($contracts->contains(fn ($c) => $c->status === 'in_renewal_window' && Renewal::withoutGlobalScopes()->where('contract_id', $c->id)->where('status', 'open')->whereNotNull('recommendation')->exists()), "{$slug} a window contract with a draft recommendation");
            $spread = $contracts->map(fn ($c) => Carbon::parse($c->ends_on)->diffInDays(today(), false))->all();
            $this->assertGreaterThanOrEqual(40, max($spread), 'ends in the past');
            $this->assertLessThanOrEqual(-400, min($spread), 'ends far ahead');

            // team of 2 to 4, links, documents with a hash and a stored file
            foreach ($contracts as $c) {
                $team = $c->team()->count();
                $this->assertTrue($team >= 2 && $team <= 4, "{$slug} {$c->contract_code} team of {$team}");
                $this->assertGreaterThanOrEqual(1, $c->links()->count(), "{$slug} {$c->contract_code} is linked");
            }
            $docs = ContractDocument::withoutGlobalScopes()->where('organization_id', $org)->get();
            $this->assertGreaterThanOrEqual(3, $docs->count());
            foreach ($docs as $d) {
                $this->assertSame(64, strlen((string) $d->sha256));
                $this->assertTrue(Storage::disk('local')->exists($d->evidence->file_path), "{$slug} document file {$d->evidence->file_path}");
            }
            if ($slug === 'first-bank-nigeria') {
                $this->assertTrue(ContractLink::withoutGlobalScopes()->where('organization_id', $org)->where('linkable_type', EaApplication::class)->exists(), 'First Bank links EA applications');
                $this->assertTrue(ContractLink::withoutGlobalScopes()->where('organization_id', $org)->where('linkable_type', BusinessService::class)->exists(), 'First Bank links business services');
                $this->assertEqualsCanonicalizing(['GBP', 'GHS', 'NGN', 'USD'], $contracts->pluck('currency')->unique()->sort()->values()->all());
            } else {
                $this->assertTrue(ContractLink::withoutGlobalScopes()->where('organization_id', $org)->where('linkable_type', Risk::class)->exists(), 'Kano links risks');
            }

            // every page and every tab renders
            $this->get(route('finance.contracts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Contracts/Index')->has('contracts', $contracts->count()));
            $this->get(route('finance.contracts.create'))->assertOk();
            foreach (Contract::STATUSES as $status) {
                $c = $contracts->firstWhere('status', $status);
                foreach (['overview', 'team', 'links', 'documents', 'renewal', 'timeline', 'spend'] as $tab) {
                    $this->get(route('finance.contracts.show', [$c, 'tab' => $tab]))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Contracts/Show')->where('tab', $tab));
                }
                $this->get(route('finance.contracts.edit', $c))->assertStatus(in_array($status, Contract::TERMINAL, true) ? 403 : 200); // renewed, terminated and replaced contracts are read-only
            }
            $renewals = Renewal::withoutGlobalScopes()->where('organization_id', $org)->get();
            foreach (['open', 'submitted', 'approved', 'rejected', 'executed'] as $status) {
                $this->assertTrue($renewals->contains('status', $status), "{$slug} a {$status} renewal");
            }
            $this->get(route('finance.renewals.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Renewals/Index')->has('renewals', $renewals->count()));
            foreach ($renewals as $r) {
                $this->get(route('finance.renewals.show', $r))->assertOk();
            }
            $doc = $docs->first();
            $this->get(route('finance.contracts.documents.download', [$doc->contract_id, $doc->id]))->assertOk();
            foreach (['month', 'quarter', 'year', 'timeline'] as $view) {
                $this->get(route('finance.calendar', ['view' => $view]))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Calendar')->where('view', $view));
            }

            // Passed: at least one notice and one end entry, and every contract in it has no decision
            $passed = collect($this->get(route('finance.calendar'))->viewData('page')['props']['passed']);
            $this->assertTrue($passed->contains('which', 'notice'), "{$slug} a passed notice deadline");
            $this->assertTrue($passed->contains('which', 'end'), "{$slug} a lapsed contract");
            $this->assertTrue($passed->every(fn ($p) => Contract::withoutGlobalScopes()->find($p['id'])->decision === null));

            // every pending renewal step has someone who can decide it
            $pending = ApprovalRequest::withoutGlobalScopes()->where('organization_id', $org)->where('process', 'finance.contract_renewal')->where('status', 'pending')->get();
            $this->assertGreaterThanOrEqual(2, $pending->count(), "{$slug} pending renewals");
            foreach ($pending as $request) {
                $step = $request->currentStep();
                $subject = $request->subject_type::withoutGlobalScopes()->find($request->subject_id);
                $deciders = $engine->eligibleUsers($step, $request)->filter(fn (User $u) => $u->can('approve it-finance-contracts') && $engine->canDecide($step, $request, $subject, $u));
                $this->assertGreaterThan(0, $deciders->count(), "{$slug}: nobody can decide \"{$step->name}\" of {$request->subject_label}");
            }
            $this->assertTrue($pending->contains(fn ($r) => $r->currentStep()->name === 'Head of IT review'), "{$slug} one waits at the Head of IT");
            $this->assertTrue($pending->contains(fn ($r) => str_starts_with($r->currentStep()->name, 'CFO approval')), "{$slug} one waits at the CFO");

            // T-minus history is seeded, and a sweep on a later day sends the next offset once
            $this->assertTrue(ContractEvent::withoutGlobalScopes()->where('organization_id', $org)->where('type', 'notified')->exists());
            $this->assertTrue(DB::table('fin_notification_logs')->where('organization_id', $org)->where('notification_key', 'like', 'renewal_due.end.%')->exists());
            $target = $contracts->first(fn ($c) => str_contains($c->title, 'Globacom') || str_contains($c->title, 'Rack Centre'));
            $day = Carbon::parse($target->ends_on)->subDays(90)->toDateString();
            $before = DB::table('fin_notification_logs')->where('notifiable_id', $target->id)->where('notification_key', 'like', 'renewal_due.end.%')->where('offset_days', 90)->count();
            Notification::fake();
            DB::beginTransaction();
            try {
                Artisan::call('fin:contracts-sweep', ['--date' => $day]);
                $after = DB::table('fin_notification_logs')->where('notifiable_id', $target->id)->where('notification_key', 'like', 'renewal_due.end.%')->where('offset_days', 90)->count();
            } finally {
                DB::rollBack();
            }
            $this->assertSame(0, $before);
            $this->assertGreaterThan(0, $after, "{$slug}: the sweep on {$day} logs a renewal_due notice");
        }

        // a bank with no contracts renders empty pages, not errors
        $acme = User::where('email', 'admin@acme.ng')->first();
        $this->actingAs($acme)->get(route('finance.contracts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('contracts', 0));
        $this->get(route('finance.renewals.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('renewals', 0));
        $this->get(route('finance.calendar'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 0)->has('passed', 0));
    }

    /**
     * IT Financial Management P3 (expenses): every status has a record, every detail page and tab renders, the overdue invoice, the
     * unbudgeted emergency and the September reconciliation queue exist, and every pending expense and invoice step (owner-type steps
     * included, so no permission filter) has a seeded person who can decide it.
     */
    public function test_finance_expense_estate_is_seeded_and_renders_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $engine = app(ApprovalEngine::class);
        foreach (['kano-heritage-bank' => 'admin@kanoheritage.ng', 'first-bank-nigeria' => 'admin@firstbanknigeria.ng'] as $slug => $email) {
            $admin = User::where('email', $email)->firstOrFail();
            $org = $admin->organization_id;
            $this->actingAs($admin);

            $expenses = ExpenseRequest::withoutGlobalScopes()->where('organization_id', $org)->get();
            foreach (ExpenseRequest::STATUSES as $status) {
                $this->assertTrue($expenses->contains('status', $status), "{$slug} has a {$status} expense request");
            }
            $emergency = $expenses->first(fn ($e) => $e->is_emergency && $e->budget_line_id === null);
            $this->assertNotNull($emergency, "{$slug} an unbudgeted emergency request");
            $this->assertSame('submitted', $emergency->status);
            $this->assertTrue($expenses->contains(fn ($e) => $e->renewal_id !== null && $e->status === 'draft'), "{$slug} a draft raised from a renewal");

            $pos = PurchaseOrder::withoutGlobalScopes()->where('organization_id', $org)->get();
            foreach (['open', 'partially_received', 'received', 'closed'] as $status) {
                $this->assertTrue($pos->contains('status', $status), "{$slug} has a {$status} purchase order");
            }
            $this->assertTrue(GoodsReceiptLine::withoutGlobalScopes()->where('organization_id', $org)->where('accepted', false)->exists(), "{$slug} a receipt with a rejected line");

            $invoices = Invoice::withoutGlobalScopes()->where('organization_id', $org)->get();
            foreach (Invoice::STATUSES as $status) {
                $this->assertTrue($invoices->contains('status', $status), "{$slug} has a {$status} invoice");
            }
            $today = Carbon::parse(now(config('finance.timezone'))->toDateString());
            $this->assertTrue($invoices->contains(fn ($i) => in_array($i->status, Invoice::UNPAID, true) && $i->due_on->lt($today)), "{$slug} an overdue invoice");
            $this->assertTrue($invoices->contains('match_status', 'variance'), "{$slug} a variance invoice");
            $this->assertTrue($invoices->contains('match_status', 'override'), "{$slug} an overridden invoice");
            $this->assertTrue($invoices->contains(fn ($i) => $i->is_lease), "{$slug} a lease invoice");
            $this->assertTrue($invoices->contains(fn ($i) => $i->prepayment_months !== null), "{$slug} a prepaid invoice");
            $this->assertTrue($invoices->contains(fn ($i) => $i->is_expected), "{$slug} an expected invoice from a recurring expense");
            $this->assertTrue($invoices->contains(fn ($i) => $i->po_id === null && $i->contract_id !== null), "{$slug} a contract invoice without a PO");

            // actuals: nine months of ledger, a September batch with rows to reconcile, a duplicate and an unbudgeted acknowledgement
            $actuals = Actual::withoutGlobalScopes()->where('organization_id', $org);
            $this->assertGreaterThan(300, (clone $actuals)->where('source', 'gl_import')->count());
            $this->assertGreaterThanOrEqual(9, (clone $actuals)->where('source', 'gl_import')->distinct()->count('period_id'), 'January to September');
            $sept = ImportBatch::withoutGlobalScopes()->where('organization_id', $org)->where('type', 'actuals')->where('file_name', 'GL extract Sep')->firstOrFail();
            $this->assertGreaterThanOrEqual(6, $sept->unmatched_count);
            $this->assertLessThanOrEqual(8, $sept->unmatched_count);
            $this->assertSame(2, (clone $actuals)->where('match_status', 'duplicate')->count());
            $this->assertSame(1, (clone $actuals)->where('match_status', 'unbudgeted')->where('source', 'gl_import')->count());
            $this->assertTrue(ImportBatch::withoutGlobalScopes()->where('organization_id', $org)->where('type', 'invoices')->where('rejected_count', '>', 0)->exists(), "{$slug} an invoices import with rejected rows");
            $this->assertTrue(Accrual::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'reversed')->exists());
            $this->assertTrue(Accrual::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'proposed')->exists());
            $this->assertSame(6, RecurringExpense::withoutGlobalScopes()->where('organization_id', $org)->count());
            $this->assertTrue(RecurringExpense::withoutGlobalScopes()->where('organization_id', $org)->where('is_active', false)->exists());
            $this->assertTrue(DB::table('fin_notification_logs')->where('organization_id', $org)->where('notifiable_type', Invoice::class)->where('offset_days', '<', 0)->exists(), "{$slug} overdue notification history");

            // every page renders: lists, one detail page per status, every tab
            foreach (['finance.expenses.index', 'finance.purchase-orders.index', 'finance.invoices.index', 'finance.recurring.index', 'finance.accruals.index', 'finance.imports.index'] as $name) {
                $this->get(route($name))->assertOk();
            }
            $this->get(route('finance.expenses.availability', ['budget_line_id' => $expenses->firstWhere('budget_line_id', '!=', null)->budget_line_id, 'amount' => '1000000']))->assertOk();
            $this->get(route('finance.invoices.index', ['overdue' => 1]))->assertOk();
            $this->get(route('finance.invoices.index', ['awaiting_me' => 1]))->assertOk();
            foreach ($expenses->unique('status') as $e) {
                $this->get(route('finance.expenses.show', $e))->assertOk();
                $e->status === 'draft' && $this->get(route('finance.expenses.edit', $e))->assertOk();
            }
            foreach ($pos->unique('status') as $po) {
                $this->get(route('finance.purchase-orders.show', $po))->assertOk();
            }
            foreach ($invoices->unique('status') as $i) {
                $this->get(route('finance.invoices.show', $i))->assertOk();
            }
            foreach (['posted', 'queue', 'rules', 'mappings'] as $tab) {
                $this->get(route('finance.actuals.index', ['tab' => $tab]))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Actuals/Index')->where('tab', $tab));
            }
            $this->get(route('finance.actuals.index', ['tab' => 'queue']))->assertInertia(fn (Assert $p) => $p->where('queue', fn ($q) => count($q) >= 6));
            foreach (ImportBatch::withoutGlobalScopes()->where('organization_id', $org)->get() as $b) {
                $this->get(route('finance.imports.show', $b))->assertOk()->assertInertia(fn (Assert $p) => $p->has('batch.report.rows')->has('batch.report.errors')->has('batch.report.sample'));
            }
            $queued = Actual::withoutGlobalScopes()->where('organization_id', $org)->where('match_status', 'unmatched')->first();
            $this->get(route('finance.actuals.candidates', $queued))->assertOk();

            // every pending expense and invoice step has someone who can decide it (an owner-type step needs no approve permission)
            foreach (['finance.expense_approval', 'finance.invoice_approval'] as $process) {
                $pending = ApprovalRequest::withoutGlobalScopes()->where('organization_id', $org)->where('process', $process)->where('status', 'pending')->get();
                $this->assertGreaterThanOrEqual(2, $pending->count(), "{$slug} pending {$process}");
                foreach ($pending as $request) {
                    $step = $request->currentStep();
                    $subject = $request->subject_type::withoutGlobalScopes()->find($request->subject_id);
                    $deciders = $engine->eligibleUsers($step, $request)->filter(fn (User $u) => $engine->canDecide($step, $request, $subject, $u));
                    $this->assertGreaterThan(0, $deciders->count(), "{$slug}: nobody can decide \"{$step->name}\" of {$request->subject_label}");
                }
            }
            // the high-value invoice shows its excluded PO approvers
            $high = $invoices->first(fn ($i) => $i->high_value === 'yes' && $i->status === 'submitted');
            $this->assertNotNull($high, "{$slug} a high-value invoice pending at the Finance Approver stage");
            $this->get(route('finance.invoices.show', $high))->assertInertia(fn (Assert $p) => $p->where('sod.applies', true)->has('sod.po_approvers'));
        }

        // a bank with no finance data renders empty expense pages, not errors
        $acme = User::where('email', 'admin@acme.ng')->first();
        $this->actingAs($acme);
        $this->get(route('finance.expenses.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Expenses/Index'));
        foreach (['finance.purchase-orders.index', 'finance.invoices.index', 'finance.actuals.index', 'finance.recurring.index', 'finance.accruals.index', 'finance.imports.index'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    /**
     * IT Financial Management P4 (plan §5.2): the seeded monitoring estate of each demo bank. FY variances of every severity above the
     * bank's floor, a reconciled BvA, forecasts, a re-opened September that cannot close again (Kano) and a re-open request the CFO can
     * decide (First Bank), the four finance KRIs with readings, the BAC finance section, every report in every format, and empty pages for Acme.
     */
    public function test_finance_monitoring_estate_is_seeded_and_renders_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $engine = app(ApprovalEngine::class);
        $banks = [
            'kano-heritage-bank' => ['admin' => 'admin@kanoheritage.ng', 'cfo' => 'cfo@kanoheritage.ng', 'finance' => 'finance@kanoheritage.ng', 'floor' => 1000000, 'reopen' => 'approved'],
            'first-bank-nigeria' => ['admin' => 'admin@firstbanknigeria.ng', 'cfo' => 'cfo@firstbanknigeria.ng', 'finance' => 'finance@firstbanknigeria.ng', 'floor' => 5000000, 'reopen' => 'submitted'],
        ];
        foreach ($banks as $slug => $b) {
            $admin = User::where('email', $b['admin'])->firstOrFail();
            $cfo = User::where('email', $b['cfo'])->firstOrFail();
            $finance = User::where('email', $b['finance'])->firstOrFail();
            $org = $admin->organization_id;
            $this->actingAs($admin);
            $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $org)->where('is_current', true)->firstOrFail();
            $asOf = app(MonitoringService::class)->asOfPeriod($org, $fy->id);
            $this->assertNotNull($asOf, "{$slug} has an ended period");
            $props = fn ($response) => $response->viewData('page')['props'];

            // variances: red overspends and underspends and amber ones, all above the bank's absolute floor
            $notes = VarianceNote::withoutGlobalScopes()->where('organization_id', $org)->where('fiscal_year_id', $fy->id);
            $latest = (clone $notes)->where('period_id', $asOf->id)->where('is_breaching', true);
            $this->assertGreaterThanOrEqual(3, (clone $latest)->where('severity', 'red')->where('direction', 'over')->count(), "{$slug} red overspends");
            $this->assertGreaterThanOrEqual(1, (clone $latest)->where('severity', 'red')->where('direction', 'under')->count(), "{$slug} red underspends");
            $this->assertGreaterThanOrEqual(3, (clone $latest)->where('severity', 'amber')->count(), "{$slug} amber variances");
            $this->assertSame(0, (clone $notes)->whereRaw('abs(variance_amount) < ?', [$b['floor']])->count(), "{$slug} every note clears the floor of {$b['floor']}");
            $this->assertSame(0, (clone $notes)->whereIn('status', ['open'])->whereHas('period', fn ($q) => $q->where('sequence', '<', $asOf->sequence))->count(), "{$slug} earlier periods are fully explained");
            $this->assertGreaterThanOrEqual(2, (clone $notes)->where('status', 'open')->where('severity', 'amber')->count(), "{$slug} two amber notes are left open");
            $this->assertGreaterThanOrEqual(2, (clone $notes)->whereHas('comments')->count(), "{$slug} two notes carry a comment thread");
            $this->assertGreaterThanOrEqual(1, Issue::withoutGlobalScopes()->where('organization_id', $org)->where('source_type', 'finance_variance')->count(), "{$slug} a variance issue");
            $this->assertSame((clone $notes)->count(), DB::table('fin_notification_logs')->where('organization_id', $org)->where('notifiable_type', VarianceNote::class)->count(), "{$slug} notification history is back-filled");

            // BvA reconciles to the cent for every grouping and renders every monitoring page
            foreach (MonitoringService::GROUP_BY as $group) {
                $matrix = $props($this->get(route('finance.monitor.bva', ['group_by' => $group, 'view' => 'fy']))->assertOk())['matrix'];
                $this->assertSame('0.00', $matrix['reconciliation']['difference'], "{$slug} BvA {$group}");
            }
            $this->get(route('finance.dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Dashboard')->where('metrics.variances.red', fn ($n) => $n >= 3));
            $this->get(route('finance.monitor.variances', ['severity' => 'red']))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Monitor/Variances'));
            $this->get(route('finance.monitor.variances', ['period_id' => $asOf->id, 'needs_explanation' => 1]))->assertOk();
            $forecast = $props($this->get(route('finance.monitor.forecast'))->assertOk());
            $this->assertSame(2, collect($forecast['lines'])->filter(fn ($l) => $l['manual'] !== null)->count(), "{$slug} two manual forecasts");
            $this->assertNotEmpty($forecast['history'], "{$slug} forecast history from the close snapshots");
            $this->get(route('finance.monitor.period-close'))->assertOk();
            $this->get(route('finance.reports.index', ['report' => 'bva']))->assertOk()->assertInertia(fn (Assert $p) => $p->has('reports', 14)->where('schedules', fn ($s) => count($s) >= 2));
            $first = Budget::withoutGlobalScopes()->where('organization_id', $org)->where('fiscal_year_id', $fy->id)->where('status', 'baseline')->first();
            $line = BudgetLine::withoutGlobalScopes()->where('budget_id', $first->id)->orderBy('line_no')->first();
            $this->getJson(route('finance.monitor.transactions', $line))->assertOk()->assertJsonStructure(['line', 'actuals', 'commitments', 'totals']);

            // close history: a record and a forecast snapshot for every closed period
            $this->assertGreaterThanOrEqual(9, PeriodClose::withoutGlobalScopes()->where('organization_id', $org)->count(), "{$slug} close records");
            $this->assertGreaterThanOrEqual(9, Forecast::withoutGlobalScopes()->where('organization_id', $org)->where('is_snapshot', true)->distinct('period_id')->count('period_id'), "{$slug} a forecast snapshot per closed period");

            // the latest closed period: re-opened and blocked (Kano) or waiting for the CFO (First Bank)
            $sep = Period::withoutGlobalScopes()->findOrFail($asOf->id);
            $checklist = $props($this->get(route('finance.monitor.period-close', ['fiscal_year_id' => $fy->id, 'period_id' => $sep->id]))->assertOk())['selected'];
            $history = collect($checklist['history']);
            if ($b['reopen'] === 'approved') {
                $this->assertSame('open', $sep->status, "{$slug} {$sep->name} is open again");
                $this->assertTrue($history->contains('reopen_status', 'approved'), "{$slug} the re-open was approved");
                $this->assertFalse($checklist['checklist']['can_close'], "{$slug} {$sep->name} cannot close");
                $status = collect($checklist['checklist']['items'])->pluck('status', 'key');
                $this->assertSame('fail', $status['accruals_done'], "{$slug} M9: a proposed accrual blocks the close");
                $this->assertSame('fail', $status['variances_explained'], "{$slug} an unexplained red variance blocks the close");
                $this->assertSame(1, Accrual::withoutGlobalScopes()->where('organization_id', $org)->where('period_id', $sep->id)->where('status', 'proposed')->count());
                $this->assertSame(1, (clone $notes)->where('period_id', $sep->id)->where('severity', 'red')->where('status', 'open')->count(), "{$slug} one unexplained red");
                $this->actingAs($finance)->post(route('finance.periods.close', $sep))->assertSessionHasErrors('checklist');
                $this->assertSame('open', Period::withoutGlobalScopes()->find($sep->id)->status);
            } else {
                $this->assertSame('closed', $sep->status);
                $pending = PeriodClose::withoutGlobalScopes()->where('organization_id', $org)->where('period_id', $sep->id)->where('reopen_status', 'submitted')->first();
                $this->assertNotNull($pending, "{$slug} a pending re-open request");
                $this->assertSame($finance->id, (int) $pending->reopen_requested_by_id);
                $request = ApprovalRequest::withoutGlobalScopes()->where('organization_id', $org)->where('process', 'finance.period_reopen')->where('status', 'pending')->firstOrFail();
                $step = $request->currentStep();
                $this->assertSame('CFO approval', $step->name);
                $deciders = $engine->eligibleUsers($step, $request)->filter(fn (User $u) => $u->can('approve it-finance') && $engine->canDecide($step, $request, $pending, $u));
                $this->assertTrue($deciders->contains('id', $cfo->id), "{$slug}: the CFO can decide the re-open");
                $this->assertFalse($deciders->contains('id', $finance->id), "{$slug}: the requester cannot");
                $this->actingAs($cfo)->get(route('finance.period-closes.show', $pending))->assertRedirect();
                $cfoView = $props($this->actingAs($cfo)->get(route('finance.monitor.period-close', ['fiscal_year_id' => $fy->id, 'period_id' => $sep->id]))->assertOk())['selected'];
                $this->assertTrue($cfoView['reopen']['can']['approve'], "{$slug}: the CFO's page offers the decision");
                $financeView = $props($this->actingAs($finance)->get(route('finance.monitor.period-close', ['fiscal_year_id' => $fy->id, 'period_id' => $sep->id]))->assertOk())['selected'];
                $this->assertFalse($financeView['reopen']['can']['approve']);
                $this->assertTrue($financeView['reopen']['can']['withdraw']);
            }
            $this->actingAs($admin);

            // the four finance KRIs have readings, and the BAC pack has the finance section
            foreach (['KRI-FIN-VAR', 'KRI-FIN-UNB', 'KRI-FIN-INV', 'KRI-FIN-NOT'] as $code) {
                $kri = Kri::withoutGlobalScopes()->where('organization_id', $org)->where('code', $code)->first();
                $this->assertNotNull($kri, "{$slug} {$code}");
                $this->assertSame($cfo->id, (int) $kri->owner_id, "{$slug} {$code} is owned by the CFO");
                $this->assertGreaterThan(0, KriReading::withoutGlobalScopes()->where('kri_id', $kri->id)->count(), "{$slug} {$code} has readings");
            }
            $bac = BoardPackRun::withoutGlobalScopes()->where('organization_id', $org)->get()->first(fn ($r) => collect($r->content)->contains('key', 'it_financial_position'));
            $this->assertNotNull($bac, "{$slug} a board pack with the IT financial position");
            $this->assertNotNull(collect($bac->content)->firstWhere('key', 'it_financial_position')['data'], "{$slug} the finance section carries data");

            // scheduled finance reports, each with a recorded run
            $schedules = ScheduledReport::withoutGlobalScopes()->where('organization_id', $org)->where('report_type', 'like', 'finance.%')->get();
            $this->assertSame(['finance.bva', 'finance.invoice_ageing'], $schedules->pluck('report_type')->sort()->values()->all(), "{$slug} finance schedules");
            foreach ($schedules as $s) {
                $this->assertSame(1, ScheduledReportRun::withoutGlobalScopes()->where('scheduled_report_id', $s->id)->count());
            }

            // every report downloads in every format
            foreach (array_keys(FinanceReports::DEFINITIONS) as $key) {
                foreach (['csv', 'xlsx', 'pdf'] as $format) {
                    $this->get(route('finance.reports.show', ['report' => $key, 'format' => $format]))->assertOk();
                }
            }
        }

        // a bank with no finance data renders empty monitoring pages, not errors
        $acme = User::where('email', 'admin@acme.ng')->firstOrFail();
        $this->actingAs($acme);
        $this->get(route('finance.dashboard'))->assertOk();
        $this->get(route('finance.monitor.bva'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('matrix.rows', []));
        $this->get(route('finance.monitor.forecast'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('lines', []));
        $this->get(route('finance.monitor.variances'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('notes.data', []));
        $this->get(route('finance.monitor.period-close'))->assertOk();
        $this->get(route('finance.reports.index'))->assertOk();
        $this->get(route('finance.monitor.bva.export'))->assertOk();
        foreach (array_keys(FinanceReports::DEFINITIONS) as $key) {
            $this->get(route('finance.reports.show', ['report' => $key, 'format' => 'xlsx']))->assertOk();
        }
    }

    /**
     * IT Financial Management P5 (plan §5.2): the seeded investment estate of each demo bank renders, every stage is represented, a review
     * is due, the pending gates have a decider, the calendar and the BAC template carry investments, and Acme renders empty pages.
     */
    public function test_finance_investment_estate_is_seeded_and_renders_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $engine = app(ApprovalEngine::class);
        $banks = ['kano-heritage-bank' => ['admin' => 'admin@kanoheritage.ng', 'min' => 10], 'first-bank-nigeria' => ['admin' => 'admin@firstbanknigeria.ng', 'min' => 12]];
        foreach ($banks as $slug => $b) {
            $admin = User::where('email', $b['admin'])->firstOrFail();
            $org = $admin->organization_id;
            $this->actingAs($admin);
            $domain = substr($b['admin'], strpos($b['admin'], '@') + 1);
            $inv = Investment::withoutGlobalScopes()->where('organization_id', $org)->get();
            $this->assertGreaterThanOrEqual(8, $inv->count(), "{$slug} investments");
            $this->assertSame($b['min'], $inv->count(), "{$slug} investment count");
            foreach (Investment::STAGES as $stage) {
                $this->assertTrue($inv->contains('stage', $stage), "{$slug} has a {$stage} investment");
            }
            $this->assertTrue($inv->contains('status', 'on_hold'), "{$slug} an on-hold investment");
            $this->assertTrue($inv->contains('is_regulatory', true), "{$slug} a regulatory investment");
            $this->assertTrue($inv->contains('rgt', 'transform'), "{$slug} a transform investment");
            $this->assertTrue(str_ends_with((string) $inv->sortBy('id')->first()->investment_code, 'IVT-0001'));
            $decidersKeys = User::whereIn('email', array_map(fn ($k) => "{$k}@{$domain}", ['headofit', 'cfo', 'cio', 'md']))->pluck('id')->all();
            $this->assertEmpty($inv->filter(fn ($i) => in_array($i->manager_id, $decidersKeys, true) || in_array($i->sponsor_id, $decidersKeys, true))->all(), "{$slug} no stage decider manages or sponsors");
            $this->assertTrue(User::where('email', "steerco@{$domain}")->firstOrFail()->hasRole('IT Steering Committee'));

            $due = ValueReview::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'due')->get();
            $this->assertTrue($due->contains(fn ($r) => FinanceClock::daysUntil($r->due_on) <= 14), "{$slug} a review due within 14 days");
            $slug === 'first-bank-nigeria' && $this->assertTrue(ValueReview::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'overdue')->exists());
            $this->assertTrue(Benefit::withoutGlobalScopes()->where('organization_id', $org)->whereNotNull('realised_pct')->exists());

            // the pending gate at the CFO and the pending change at Head of IT each have someone who can decide
            foreach (['approved' => 'cfo', 'change' => 'headofit'] as $gateName => $who) {
                $gate = StageGate::withoutGlobalScopes()->where('organization_id', $org)->where('gate', $gateName)->where('status', 'submitted')->first();
                $this->assertNotNull($gate, "{$slug} pending {$gateName} gate");
                $request = $engine->current(StageGateService::PROCESS, $gate);
                $step = $request->currentStep();
                $decider = User::where('email', "{$who}@{$domain}")->firstOrFail();
                $this->assertTrue($engine->canDecide($step, $request, $gate, $decider) && $decider->can('approve it-finance-investments'), "{$slug}: {$who} can decide \"{$step->name}\"");
            }

            $this->get(route('finance.investments.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Investments/Index'));
            $one = $inv->firstWhere('stage', 'benefits_tracking');
            foreach (['overview', 'business-case', 'scoring', 'gates', 'budget', 'benefits', 'reviews', 'changes', 'activity'] as $tab) {
                $this->get(route('finance.investments.show', [$one->id, 'tab' => $tab]))->assertOk();
            }
            $pf = $this->get(route('finance.portfolio'))->assertOk()->viewData('page')['props'];
            $this->assertTrue(collect($pf['ranking'])->contains('above_line', true), "{$slug} a fund line with investments above it");
            $events = collect(app(FinanceCalendarService::class)->events($org, Carbon::now()->subMonths(6), Carbon::now()->addMonths(6), ['sources' => ['investments']], $admin));
            $this->assertNotEmpty($events, "{$slug} investment events in the calendar");
            $bac = BoardPackTemplate::where('name', 'like', 'BAC%')->first();
            $this->assertContains('it_investment_portfolio', (array) $bac->sections);
            foreach (['investment_portfolio', 'benefits_register'] as $key) {
                foreach (['csv', 'xlsx', 'pdf'] as $format) {
                    $this->get(route('finance.reports.show', ['report' => $key, 'format' => $format]))->assertOk();
                }
            }
        }

        $acme = User::where('email', 'admin@acme.ng')->firstOrFail();
        $this->actingAs($acme);
        $this->get(route('finance.investments.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('investments.data', []));
        $this->get(route('finance.portfolio'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('ranking', []));
    }

    /**
     * IT Financial Management P6 (plan §5.2): the seeded FinOps, showback and capitalisation estate of each bank, and the pages that show it.
     */
    public function test_finance_finops_estate_is_seeded_and_renders_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $engine = app(ApprovalEngine::class);
        $banks = [
            'kano-heritage-bank' => ['admin' => 'admin@kanoheritage.ng', 'spike' => 2, 'subject' => 'business_service', 'prefix' => 'KHB'],
            'first-bank-nigeria' => ['admin' => 'admin@firstbanknigeria.ng', 'spike' => 3, 'subject' => 'ea_application', 'prefix' => 'FBN'],
        ];
        foreach ($banks as $slug => $b) {
            $admin = User::where('email', $b['admin'])->firstOrFail();
            $org = (int) $admin->organization_id;
            $domain = substr($b['admin'], strpos($b['admin'], '@') + 1);
            $finance = User::where('email', "finance@{$domain}")->firstOrFail();
            $this->actingAs($admin);
            $today = FinanceClock::today();
            $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $org)->where('is_current', true)->firstOrFail();

            // cloud: two accounts with about 90 days of cost, an untagged queue, rules, and a spike that was detected
            $accounts = CloudAccount::withoutGlobalScopes()->where('organization_id', $org)->get();
            $this->assertCount(2, $accounts, "{$slug} cloud accounts");
            foreach ($accounts as $a) {
                $days = CloudCostLine::withoutGlobalScopes()->where('cloud_account_id', $a->id)->distinct()->count(DB::raw('usage_date'));
                $this->assertGreaterThanOrEqual(85, $days, "{$slug} {$a->name} days of cost");
                $this->assertNotNull($a->owner_id, "{$slug} {$a->name} owner");
                $this->assertTrue(User::find($a->owner_id)->can('view it-finance'), "{$slug} {$a->name} owner can view it-finance");
            }
            $this->assertTrue(CloudCostLine::withoutGlobalScopes()->where('organization_id', $org)->where('is_untagged', true)->exists(), "{$slug} untagged queue");
            $this->assertGreaterThan(0, CloudTagRule::withoutGlobalScopes()->where('organization_id', $org)->count(), "{$slug} tag rules");
            $spike = CloudAnomaly::withoutGlobalScopes()->where('organization_id', $org)->whereIn('status', ['open', 'acknowledged'])->whereDate('detected_on', $today->subDays($b['spike'])->toDateString())->exists();
            $this->assertTrue($spike, "{$slug} an open anomaly on the spike day");
            $this->assertTrue(CloudAnomaly::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'resolved')->exists(), "{$slug} a resolved anomaly");
            $this->assertTrue(CloudAnomaly::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'false_positive')->exists(), "{$slug} a dismissed anomaly");
            $cloudBatch = ImportBatch::withoutGlobalScopes()->where('organization_id', $org)->where('type', 'cloud')->first();
            $this->assertNotNull($cloudBatch, "{$slug} a cloud import batch");
            $this->assertSame(2, (int) $cloudBatch->rejected_count, "{$slug} cloud batch rejected rows");

            // KRI-FIN-CLD: category finance, platform source, higher is worse, measured at least once to a value of 1 or more
            $kri = Kri::withoutGlobalScopes()->where('organization_id', $org)->where('code', 'KRI-FIN-CLD')->firstOrFail();
            $this->assertSame('finance', $kri->category);
            $this->assertSame('platform', $kri->source);
            $this->assertSame('higher_worse', $kri->direction);
            $this->assertGreaterThanOrEqual(1.0, (float) $kri->readings()->latest('id')->value('value'), "{$slug} KRI-FIN-CLD value");

            // allocation: at least 8 rules using all three methods, results for every closed period, a reconciling statement
            $rules = Allocation::withoutGlobalScopes()->where('organization_id', $org)->get();
            $this->assertGreaterThanOrEqual(8, $rules->count(), "{$slug} allocation rules");
            $this->assertEqualsCanonicalizing(['direct', 'driver', 'percentage'], $rules->pluck('method')->unique()->values()->all(), "{$slug} allocation methods");
            $closed = Period::withoutGlobalScopes()->where('organization_id', $org)->where('fiscal_year_id', $fy->id)->where('is_adjustment', false)->whereIn('status', ['closed', 'locked'])->orderBy('sequence')->get();
            foreach ($closed as $p) {
                $this->assertTrue(AllocationRun::withoutGlobalScopes()->where('organization_id', $org)->where('period_id', $p->id)->where('amount_type', 'actual')->where('is_frozen', true)->exists(), "{$slug} frozen results for {$p->name}");
            }
            $first = Period::withoutGlobalScopes()->where('organization_id', $org)->where('fiscal_year_id', $fy->id)->where('is_adjustment', false)->orderBy('sequence')->firstOrFail();
            $latestClosed = $closed->last();
            $statement = app(ShowbackService::class)->statement($org, 'business_unit', $fy->id, $first->id, $latestClosed->id, 'actual', null, $finance);
            $this->assertTrue($statement['totals']['reconciles'], "{$slug} showback statement reconciles ({$statement['totals']['difference']})");
            $this->assertGreaterThan(0, count($statement['rows']), "{$slug} showback rows");

            // chargeback: one exported run whose journal balances; First Bank also has a voided one
            $run = ChargebackRun::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'exported')->firstOrFail();
            $this->assertSame((string) $run->total_debit, (string) $run->total_credit, "{$slug} chargeback balances");
            $this->assertSame(hash('sha256', $run->content), $run->file_sha256);
            $slug === 'first-bank-nigeria' && $this->assertTrue(ChargebackRun::withoutGlobalScopes()->where('organization_id', $org)->where('status', 'voided')->exists());

            // capitalisations: capitalised with an asset and a schedule that sums, submitted with a decider, draft, rejected
            $caps = Capitalisation::withoutGlobalScopes()->where('organization_id', $org)->get();
            $done = $caps->where('status', 'capitalised');
            $this->assertGreaterThanOrEqual(2, $done->count(), "{$slug} capitalised");
            foreach ($done as $c) {
                $this->assertTrue(Asset::withoutGlobalScopes()->where('organization_id', $org)->whereKey($c->asset_id)->exists(), "{$slug} {$c->capitalisation_code} asset");
                $this->assertSame(bcsub((string) $c->amount, (string) $c->residual_value, 2), bcadd((string) $c->lines()->sum('amount'), '0', 2), "{$slug} {$c->capitalisation_code} schedule sums");
            }
            $this->assertTrue($done->contains(fn ($c) => $c->lines()->where('is_catch_up', true)->exists()), "{$slug} a capitalisation with catch-up lines");
            foreach (['draft', 'rejected'] as $status) {
                $this->assertTrue($caps->contains('status', $status), "{$slug} a {$status} capitalisation");
            }
            $pending = $caps->where('status', 'submitted');
            $this->assertGreaterThanOrEqual(1, $pending->count(), "{$slug} a pending capitalisation");
            foreach ($pending as $c) {
                $request = $engine->current(CapitalisationService::PROCESS, $c);
                $step = $request->currentStep();
                $this->assertTrue($engine->canDecide($step, $request, $c, $finance) && $finance->can('approve it-finance'), "{$slug}: finance@ can decide \"{$step->name}\" of {$c->capitalisation_code}");
            }

            // pages
            $props = fn ($response) => $response->viewData('page')['props'];
            $this->get(route('finance.cloud.accounts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Cloud/Accounts')->has('accounts', 2));
            $this->get(route('finance.cloud.costs', ['from' => $today->subDays(30)->toDateString(), 'to' => $today->subDay()->toDateString()]))->assertOk();
            $this->get(route('finance.cloud.costs', ['tab' => 'untagged']))->assertOk();
            $anom = $props($this->get(route('finance.cloud.anomalies.index'))->assertOk());
            $this->assertGreaterThanOrEqual(1, $anom['counts']['open']);
            $this->get(route('finance.allocations.index'))->assertOk();
            $this->get(route('finance.monitor.showback'))->assertOk();
            foreach (['csv', 'xlsx', 'pdf'] as $format) {
                $this->get(route('finance.monitor.showback.export', ['format' => $format]))->assertOk();
            }
            $this->get(route('finance.imports.show', $cloudBatch))->assertOk();
            $this->get(route('finance.capitalisations.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance/Capitalisations/Index'));
            $this->get(route('finance.capitalisations.show', $done->first()))->assertOk();
            $this->get(route('finance.capitalisations.show', $pending->first()))->assertOk();
            $tco = $props($this->get(route('finance.monitor.tco', ['subject' => $b['subject'], 'basis' => 'actual']))->assertOk());
            $this->assertGreaterThanOrEqual(5, collect($tco['rows'])->filter(fn ($r) => bccomp((string) $r['total'], '0', 2) > 0)->count(), "{$slug} TCO subjects with cost");
            $this->assertGreaterThan(0, (float) $tco['totals']['tco_total']);
            $this->get(route('finance.monitor.tco', ['subject' => $b['subject'], 'basis' => 'budget']))->assertOk();
        }

        // a bank with no finance data renders every P6 page empty, not broken
        $acme = User::where('email', 'admin@acme.ng')->firstOrFail();
        $this->actingAs($acme);
        $this->get(route('finance.cloud.accounts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('accounts', 0));
        $this->get(route('finance.cloud.costs'))->assertOk();
        $this->get(route('finance.cloud.anomalies.index'))->assertOk();
        $this->get(route('finance.allocations.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('rules', 0));
        $this->get(route('finance.monitor.showback'))->assertOk();
        $this->get(route('finance.monitor.tco'))->assertOk();
        $this->get(route('finance.capitalisations.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('capitalisations.data', []));
    }

    /**
     * P7 H5: the finance GET routes the per-phase estate tests do not reach (create forms, invoice edit, stage gates,
     * business cases, value reviews, terminations, close checklist, cycles, document and chargeback downloads) render for both demo banks.
     */
    public function test_finance_remaining_get_routes_render_for_every_demo_tenant(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $visited = [];
        $hit = function (string $name, mixed $params = [], int $expect = 200) use (&$visited) {
            $visited[] = $name;
            $response = $this->get(route($name, $params))->assertStatus($expect);
            $expect === 302 && $this->get($response->headers->get('Location'))->assertOk(); // redirect-only routes land on a rendering page
        };
        $first = fn (string $model, int $org, array $where = []) => $model::withoutGlobalScopes()->where('organization_id', $org)->where($where)->orderBy('id')->first();

        foreach (['admin@kanoheritage.ng', 'admin@firstbanknigeria.ng'] as $email) {
            $admin = User::where('email', $email)->firstOrFail();
            $org = (int) $admin->organization_id;
            $this->actingAs($admin);

            foreach (['finance.cycles.index', 'finance.expenses.create', 'finance.invoices.create', 'finance.investments.create', 'finance.calendar.export'] as $name) {
                $hit($name);
            }
            $period = $first(Period::class, $org);
            $this->assertNotNull($period, "{$email} period");
            $hit('finance.periods.close-checklist', $period);

            $invoice = $first(Invoice::class, $org, ['status' => 'draft']);
            $invoice && $hit('finance.invoices.edit', $invoice);

            $gate = $first(StageGate::class, $org);
            $this->assertNotNull($gate, "{$email} stage gate");
            $hit('finance.stage-gates.show', $gate, 302);

            $case = $first(BusinessCase::class, $org);
            $this->assertNotNull($case, "{$email} business case");
            $hit('finance.business-cases.show', $case, 302);

            $review = $first(ValueReview::class, $org);
            $this->assertNotNull($review, "{$email} value review");
            $hit('finance.value-reviews.show', $review, 302);

            // optional records: rendered where the demo estate holds one
            $termination = $first(Termination::class, $org);
            $termination && $hit('finance.terminations.show', $termination, 302);
            $doc = $first(FinanceDocument::class, $org);
            $doc && $this->get(route('finance.documents.download', $doc))->assertStatus(200);
            $run = $first(ChargebackRun::class, $org);
            $run && $this->get(route('finance.chargeback.download', $run))->assertStatus(200);
        }
        $this->assertGreaterThanOrEqual(10, count(array_unique($visited)));
    }

    /**
     * P7 H2/H3: on the seeded estate a user without `view it-finance-amounts` gets no currency-formatted amount in any
     * parameterless finance page, and every such page runs at most 60 queries (H3), except the allow-listed pages below.
     */
    public function test_finance_amounts_masked_and_query_budget_for_view_only_user(): void
    {
        if (env('SMOKE') !== '1') {
            $this->markTestSkipped('Set SMOKE=1 and use a seeded database to run the route smoke test.');
        }

        $skip = ['finance.calendar.ics', 'finance.calendar.export', 'finance.expenses.availability', 'finance.monitor.tco.detail', 'finance.monitor.showback.export', 'finance.monitor.bva.export', 'finance.reports.show'];
        $urls = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.') && in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{') && ! in_array($r->getName(), $skip, true))
            ->mapWithKeys(fn (RouteDefinition $r) => [$r->getName() => route($r->getName())])->all();
        $urls['finance.monitor.bva?fy'] = route('finance.monitor.bva', ['view' => 'fy']);

        // P7 H3: the per-page query budget. A page not listed here may not exceed 60 queries; a listed page may not exceed its ceiling
        // (the measured count on the seeded estate, worst bank). The dashboard is the one page left above 60: it is a deliberately
        // wide aggregate (budget, forecast, commitments, investments, cloud, contracts and 11 queries of pending-approval subjects) that
        // runs ~70 distinct, already-batched queries; every repeated or per-row query has been removed.
        $budget = 60;
        $allowList = ['finance.dashboard' => 80];

        foreach (['admin@kanoheritage.ng', 'admin@firstbanknigeria.ng'] as $email) {
            DB::beginTransaction();
            try {
                $admin = User::where('email', $email)->firstOrFail();
                $viewer = User::factory()->create(['organization_id' => $admin->organization_id]);
                $viewer->givePermissionTo(['view it-finance', 'close it-finance-periods']); // sees every cost centre, no amounts
                $this->actingAs($viewer);
                $leaks = [];
                $heavy = [];
                $queries = [];
                foreach ($urls as $name => $url) {
                    DB::flushQueryLog();
                    DB::enableQueryLog();
                    $response = $this->get($url);
                    $count = count(DB::getQueryLog());
                    DB::disableQueryLog();
                    $this->assertLessThan(500, $response->getStatusCode(), $name);
                    $queries[$name] = $count;
                    $count > ($allowList[$name] ?? $budget) && $heavy[] = "{$name}={$count} (limit ".($allowList[$name] ?? $budget).')';
                    if (preg_match('/(₦|NGN|USD|GBP)\s?[\d,]{4,}/u', html_entity_decode(json_encode(json_decode(html_entity_decode((string) $response->getContent()) ?: 'null', true) ?? (string) $response->getContent(), JSON_UNESCAPED_UNICODE)), $m)) {
                        $leaks[] = "{$name}: {$m[0]}";
                    }
                }
                fwrite(STDERR, "\n{$email} pages over their query budget (H3): ".($heavy ? implode(', ', $heavy) : 'none').'; busiest page: '.max($queries).' queries');
                $this->assertSame([], $heavy, "{$email} pages over the query budget");
                $this->assertSame([], $leaks, "{$email} amounts shown to a viewer without view it-finance-amounts");
            } finally {
                DB::rollBack();
            }
        }
    }
}

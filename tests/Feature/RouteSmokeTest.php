<?php

namespace Tests\Feature;

use App\Http\Controllers\Finance\BudgetController;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetComment;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetScenario;
use App\Models\Finance\BudgetTarget;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\CostCentre;
use App\Models\Finance\DoaBandVersion;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Finance\Setting;
use App\Models\Finance\SupplementaryRequest;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetService;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
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
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.') && in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'))
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
            $this->assertSame(3, ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $org)->where('process', 'like', 'finance.%')->count());

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
}

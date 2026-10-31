<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalDecision;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\SupplementaryRequest;
use App\Models\Kri;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Finance\FinanceKriSource;
use App\Services\Finance\FinanceMasking;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RequestMemo;
use App\Services\Finance\SupplementaryBudgetService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/** P7 review fix F2: trail masking (M1), finance KRI masking (M2), approval concurrency (M3), L1-L4. */
class P7F2SecurityHardeningTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
    }

    private function submitted(): SupplementaryRequest
    {
        $this->baselined($this->cc, '1200000.00');
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(SupplementaryBudgetService::class);
        $r = $svc->create(['fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc->id, 'cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-SEC'),
            'title' => 'Fraud monitoring', 'is_capex' => false, 'currency' => 'NGN', 'amount' => '5000', 'justification' => 'Directive'], $this->owner);

        return $svc->submit($r, $this->owner);
    }

    private function trailUrl(SupplementaryRequest $r): string
    {
        return route('workflows.trail', ['process' => 'finance.supplementary_budget', 'id' => $r->id]);
    }

    public function test_trail_requires_cost_centre_visibility_and_masks_band_conditions(): void // FIN-07.10 (P7 M1)
    {
        $r = $this->submitted();
        $scoped = User::factory()->create(['organization_id' => $this->org->id]);
        $scoped->givePermissionTo(['view it-finance']);
        $this->actingAs($scoped)->getJson($this->trailUrl($r))->assertForbidden();

        $blind = User::factory()->create(['organization_id' => $this->org->id]);
        $blind->givePermissionTo(['view it-finance', 'close it-finance-periods']);
        $body = $this->actingAs($blind)->getJson($this->trailUrl($r))->assertOk()->getContent();
        $this->assertStringNotContainsString('requires_cfo', $body);
        $this->assertStringNotContainsString('requires_board', $body);
        $this->assertStringNotContainsString('"condition":"amount_band', $body);

        $sighted = User::factory()->create(['organization_id' => $this->org->id]);
        $sighted->givePermissionTo(['view it-finance', 'close it-finance-periods', 'view it-finance-amounts']);
        $this->assertStringContainsString('"condition":"amount_band', $this->actingAs($sighted)->getJson($this->trailUrl($r))->assertOk()->getContent());
    }

    public function test_finance_kri_cannot_be_renamed_or_retuned_and_measure_flash_is_masked(): void // FIN-07.10 (P7 M2)
    {
        FinanceKriSource::ensureDefinitions($this->org->id);
        $kri = Kri::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', 'KRI-FIN-VAR')->firstOrFail();
        $editor = User::factory()->create(['organization_id' => $this->org->id]);
        $editor->givePermissionTo(['view risks', 'edit risks', 'create risks', 'view it-finance']);
        $payload = fn (array $o) => $o + ['code' => $kri->code, 'name' => $kri->name, 'category' => 'finance', 'unit' => $kri->unit, 'direction' => $kri->direction,
            'threshold_green' => $kri->threshold_green, 'threshold_amber' => $kri->threshold_amber, 'threshold_red' => $kri->threshold_red, 'frequency' => 'monthly'];

        $this->actingAs($editor)->put(route('kri.update', $kri), $payload(['code' => 'KRI-RENAMED']))->assertSessionHasErrors('code');
        $this->actingAs($editor)->put(route('kri.update', $kri), $payload(['threshold_red' => 99]))->assertSessionHasErrors('kri');
        $this->assertSame('KRI-FIN-VAR', $kri->refresh()->code);

        $text = app(FinanceMasking::class)->kriValueText($kri, 12.5, $editor);
        $this->assertStringNotContainsString('12.5', $text);
        $this->actingAs($editor)->post(route('kri.measure', $kri));
        $this->assertStringNotContainsString('%', (string) (session('success') ?? session('warning')));
    }

    public function test_approval_decisions_are_unique_per_step_and_user(): void // FIN-07.10 (P7 M3)
    {
        $r = $this->submitted();
        $step = ApprovalRequest::withoutGlobalScopes()->where('subject_id', $r->id)->firstOrFail()->steps()->first();
        $row = ['organization_id' => $this->org->id, 'step_id' => $step->id, 'user_id' => $this->head->id, 'decision' => 'approved', 'decided_at' => now()];
        ApprovalDecision::withoutGlobalScopes()->create($row);
        $this->expectException(QueryException::class);
        ApprovalDecision::withoutGlobalScopes()->create($row);
    }

    public function test_request_memo_does_not_outlive_the_request(): void // P7 L1
    {
        $this->actingAs($this->fin)->get(route('finance.dashboard'));
        $values = (new \ReflectionClass(RequestMemo::class))->getProperty('values');
        $this->assertSame([], $values->getValue());
    }

    public function test_finance_settings_is_scoped(): void // P7 L2
    {
        $this->assertContains(FinanceSettings::class, (new \ReflectionProperty(app(), 'scopedInstances'))->getValue(app()));
    }

    public function test_own_job_title_cannot_be_changed_and_changes_are_logged(): void // P7 L3
    {
        $admin = $this->userWith('Organization Admin', ['job_title' => 'Head of IT']);
        $other = $this->userWith('Viewer', ['job_title' => 'Analyst']);
        $body = fn (User $u, string $title) => ['name' => $u->name, 'email' => $u->email, 'job_title' => $title, 'roles' => $u->getRoleNames()->all()];

        $this->actingAs($admin)->put(route('admin.users.update', $admin->id), $body($admin, 'Chief Financial Officer'))->assertSessionHasErrors('job_title');
        $this->assertSame('Head of IT', $admin->refresh()->job_title);

        $this->actingAs($admin)->put(route('admin.users.update', $other->id), $body($other, 'Treasurer'))->assertSessionHasNoErrors();
        $this->assertSame('Treasurer', $other->refresh()->job_title);
        $this->assertDatabaseHas('activity_log', ['description' => 'job_title_changed', 'subject_id' => $other->id]);
    }

    public function test_finance_can_decide_uses_exact_job_title_match(): void // P7 L4
    {
        $r = $this->submitted();
        $request = ApprovalRequest::withoutGlobalScopes()->where('subject_id', $r->id)->with('steps')->firstOrFail();
        $step = $request->currentStep();
        $ops = $this->userWith('Finance Approver', ['job_title' => 'Head of IT Operations']);
        $head = $this->userWith('Finance Approver', ['job_title' => 'Head of IT']);
        $engine = app(ApprovalEngine::class);
        $this->assertFalse($engine->canDecide($step, $request, $r, $ops));
        $this->assertTrue($engine->canDecide($step, $request, $r, $head));
    }
}

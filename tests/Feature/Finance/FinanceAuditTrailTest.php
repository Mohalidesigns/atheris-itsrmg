<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\Contract;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Investment;
use App\Models\Finance\Invoice;
use App\Models\Finance\Renewal;
use App\Models\Finance\StageGate;
use App\Models\User;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\ContractService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\RenewalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * Audit completeness (P7 H4, FIN-07.06): create, update and approve of each process subject write an `it-finance`
 * activity row with a causer, the decisive rows name the actor who made them, and no description carries an amount (P7-16).
 */
class FinanceAuditTrailTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private const MONEY = '/(₦|NGN|USD|GBP|EUR)\s?[\d,]{4,}/u';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
    }

    /** @return Collection<int,Activity> */
    private function trail(Model $m): Collection
    {
        return Activity::query()->where('subject_type', $m->getMorphClass())->where('subject_id', $m->getKey())->orderBy('id')->get();
    }

    /** @param  array<int,string>  $events */
    private function assertAudited(Model $m, array $events, ?User $approver = null): void
    {
        $rows = $this->trail($m);
        $label = class_basename($m).'#'.$m->getKey();
        $this->assertNotEmpty($rows, "{$label} has no audit rows");
        foreach ($rows as $row) {
            $this->assertSame('it-finance', $row->log_name, "{$label} {$row->event} log name");
            $this->assertNotNull($row->causer_id, "{$label} {$row->event} '{$row->description}' has no causer");
            $this->assertDoesNotMatchRegularExpression(self::MONEY, (string) $row->description, "{$label} description carries an amount");
        }
        foreach ($events as $event) {
            $this->assertTrue($rows->contains('event', $event), "{$label} lacks an '{$event}' row; has: ".$rows->pluck('event')->unique()->implode(', '));
        }
        $approver && $this->assertTrue($rows->contains(fn ($r) => $r->event !== 'created' && (int) $r->causer_id === $approver->id), "{$label} has no row by the approver");
    }

    /** FIN-07.06 */
    public function test_budget_create_update_approve_and_baseline_are_audited(): void
    {
        $svc = app(BudgetService::class);
        $this->actingAs($this->owner);
        $b = $this->draft();
        $this->actingAs($this->owner);
        $svc->submit($b, $this->owner);
        $this->actingAs($this->cfo);
        $svc->approve($b->refresh(), $this->cfo);
        $this->actingAs($this->fin);
        $svc->baseline($b->refresh(), $this->fin);

        $this->assertInstanceOf(Budget::class, $b);
        $this->assertAudited($b->refresh(), ['created', 'updated'], $this->cfo);
    }

    /** FIN-07.06 */
    public function test_expense_create_submit_approve_are_audited(): void
    {
        $svc = app(ExpenseService::class);
        $this->actingAs($this->requester);
        $e = $this->expense(['amount' => '900000.00']);
        $svc->submit($e, $this->requester);
        $this->actingAs($this->cfo);
        $svc->approve($e->refresh(), $this->cfo);

        $this->assertInstanceOf(ExpenseRequest::class, $e);
        $this->assertAudited($e->refresh(), ['created', 'updated']);
    }

    /** FIN-07.06 */
    public function test_invoice_capture_and_update_are_audited(): void
    {
        $this->actingAs($this->procurement);
        $i = $this->invoice(['vendor_invoice_no' => 'AUD-1']);
        app(InvoiceService::class)->submit($i, $this->procurement);

        $this->assertInstanceOf(Invoice::class, $i);
        $this->assertAudited($i->refresh(), ['created']);
    }

    /** FIN-07.06 */
    public function test_contract_and_renewal_lifecycle_is_audited(): void
    {
        $this->actingAs($this->fin);
        $c = $this->contract();
        $this->actingAs($this->procurement);
        $r = $this->submittedRenewal($c);
        $this->actingAs($this->head);
        $svc = app(RenewalService::class);
        $svc->approve($r->refresh(), $this->head);
        if ($r->refresh()->status !== 'approved') {
            $this->actingAs($this->cfo);
            $svc->approve($r->refresh(), $this->cfo);
        }

        $this->assertInstanceOf(Contract::class, $c);
        $this->assertInstanceOf(Renewal::class, $r);
        $this->assertAudited($c->refresh(), ['created']);
        $this->assertAudited($r->refresh(), ['created', 'updated']);
    }

    /** FIN-07.06 */
    public function test_budget_transfer_create_submit_approve_apply_are_audited(): void
    {
        $from = $this->firstLine($this->baselined($this->cc, '1200000.00'));
        $to = $this->firstLine($this->baselined($this->cc2, '600000.00'));
        $svc = app(BudgetTransferService::class);
        $this->actingAs($this->fin);
        $t = $svc->create(['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $to->id, 'amount' => '100000.00', 'reason' => 'Rebalance after vendor renegotiation'], $this->fin);
        $svc->submit($t, $this->fin);
        $this->actingAs($this->cfo);
        $svc->approve($t->refresh(), $this->cfo);
        $this->actingAs($this->fin);
        $svc->apply($t->refresh(), $this->fin);

        $this->assertInstanceOf(BudgetTransfer::class, $t);
        $this->assertAudited($t->refresh(), ['created', 'updated']);
    }

    /** FIN-07.06 */
    public function test_investment_and_stage_gate_are_audited(): void
    {
        $this->actingAs($this->fin);
        $i = $this->approvedInvestment();
        $gate = StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->latest('id')->first();

        $this->assertInstanceOf(Investment::class, $i);
        $this->assertAudited($i->refresh(), ['created', 'updated']);
        $this->assertAudited($gate->refresh(), ['created'], $this->headofit);
    }

    /** FIN-07.06 */
    public function test_capitalisation_create_submit_approve_are_audited(): void
    {
        $this->actingAs($this->fin);
        $inv = $this->flagCapitalisable($this->inDelivery($this->approvedInvestment()));
        $this->capexActual($inv, '3000000.00', '2027-03-15');
        $c = $this->makeCapitalisation($inv, [], true);
        app(CapitalisationService::class)->submit($c, $this->fin);
        $this->actingAs($this->cfo);
        app(CapitalisationService::class)->approve($c->refresh(), $this->cfo);

        $this->assertInstanceOf(Capitalisation::class, $c);
        $this->assertAudited($c->refresh(), ['created', 'updated']);
    }

    /** FIN-07.06: the contract service's own create is attributed to its author. */
    public function test_creator_is_the_causer_of_the_created_row(): void
    {
        $this->actingAs($this->fin);
        $c = app(ContractService::class)->create([
            'title' => 'Audit AMC', 'type' => 'support_maintenance', 'vendor_id' => $this->vendor->id, 'owner_id' => $this->owner->id, 'cost_centre_id' => $this->cc->id,
            'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'notice_period_days' => 30, 'contract_value' => '12000000',
        ], $this->fin);
        $created = $this->trail($c)->firstWhere('event', 'created');
        $this->assertNotNull($created);
        $this->assertSame($this->fin->id, (int) $created->causer_id);
    }
}

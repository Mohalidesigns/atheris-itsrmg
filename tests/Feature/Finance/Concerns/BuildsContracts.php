<?php

namespace Tests\Feature\Finance\Concerns;

use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Finance\Contract;
use App\Models\Finance\Renewal;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ContractService;
use App\Services\Finance\RenewalService;
use App\Services\Finance\TerminationService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Shared fixtures for the P2 contract tests: the P1 bank (NGN, FY2027, USD spot 1500) plus a procurement officer,
 * a Head of IT, a CIO, an unrelated budget owner and a vendor. "Today" is 2027-03-01 09:00 UTC (10:00 in Lagos).
 */
trait BuildsContracts
{
    use BuildsBudgets;

    protected User $procurement;

    protected User $head;

    protected User $cio;

    protected User $stranger;

    protected Vendor $vendor;

    protected function bootContracts(string $now = '2027-03-01 09:00:00'): void
    {
        Carbon::setTestNow($now);
        $this->bootBudgets();
        $this->procurement = $this->userWith('Procurement Officer');
        $this->head = $this->userWith('Finance Approver', ['job_title' => 'Head of IT']);
        $this->cio = $this->userWith('Finance Approver', ['job_title' => 'Chief Information Officer']);
        $this->stranger = $this->userWith('IT Budget Owner');
        $this->vendor = Vendor::factory()->create(['organization_id' => $this->org->id, 'name' => 'Infosys Finacle']);
        $this->actingAs($this->fin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An active contract (create + activate) owned by $this->owner in $this->cc, procured by $this->procurement. */
    protected function contract(array $over = [], ?User $by = null): Contract
    {
        return app(ContractService::class)->create($over + [
            'title' => 'Finacle AMC', 'type' => 'support_maintenance', 'vendor_id' => $this->vendor->id, 'owner_id' => $this->owner->id, 'cost_centre_id' => $this->cc->id,
            'procurement_contact_id' => $this->procurement->id, 'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'notice_period_days' => 30,
            'contract_value' => '12000000', 'activate' => true,
        ], $by ?? $this->fin)->refresh(); // procurement officers only see their own cost centres (S-M5), so finance creates the fixture
    }

    /** Head of IT review, then CFO approval only when the renewal needs the CFO (the seeded default stages). */
    protected function configureRenewalStages(): ApprovalWorkflow
    {
        $wf = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'process' => RenewalService::PROCESS, 'is_active' => true]);
        foreach ([[10, 'Head of IT review', 'Head of IT', null, null], [20, 'CFO approval', 'Chief Financial Officer', 'requires_cfo', ['yes']]] as [$seq, $name, $title, $field, $values]) {
            $wf->stages()->create(['organization_id' => $this->org->id, 'sequence' => $seq, 'name' => $name, 'approver_type' => 'job_title', 'approver_ref' => $title,
                'sla_hours' => 72, 'min_approvals' => 1, 'is_active' => true, 'condition_field' => $field, 'condition_values' => $values]);
        }

        return $wf;
    }

    /** Opens, recommends and submits a renewal as procurement. */
    protected function submittedRenewal(Contract $c, string $value = '12000000', string $rec = 'renew_as_is', int $term = 12): Renewal
    {
        $svc = app(RenewalService::class);
        $r = $svc->recommend($svc->open($c, $this->procurement), [
            'recommendation' => $rec, 'proposed_term_months' => $term, 'proposed_value' => $value, 'proposed_currency' => 'NGN', 'justification' => 'Business-critical, no alternative.',
        ], $this->procurement);

        return $svc->submit($r, $this->procurement);
    }

    /** Requests an early termination as the CFO and approves it as the Head of IT (no workflow stages configured: one other approver). */
    protected function terminateViaWorkflow(Contract $c, string $reason = 'Vendor exit', ?string $on = null): Contract
    {
        $svc = app(TerminationService::class);
        $t = $svc->request($c, ['reason' => $reason, 'terminated_on' => $on], $this->cfo);
        $svc->approve($t, $this->head);

        return $c->refresh();
    }

    protected function refuses(callable $fn, string $key, string $needle = ''): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), 'Refused, but on '.json_encode($e->errors()));
            $needle !== '' && $this->assertStringContainsString($needle, implode(' ', $e->errors()[$key]));

            return;
        }
        $this->fail("Expected a refusal on {$key}.");
    }

    protected function otherBank(): Organization
    {
        return Organization::factory()->create(['currency' => 'NGN']);
    }
}

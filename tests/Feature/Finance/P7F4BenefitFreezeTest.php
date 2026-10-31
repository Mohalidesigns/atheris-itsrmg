<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\BenefitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/** P7 review F4 M4 / Low 4: aggregation, type and measurement method freeze with the targets; an unchanged null is not a change. */
class P7F4BenefitFreezeTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
    }

    public function test_aggregation_type_and_method_are_frozen_after_approval_but_unchanged_nulls_pass(): void // FIN-05 benefits, F4 M4
    {
        $inv = $this->inDelivery($this->approvedInvestment());
        $svc = app(BenefitsService::class);
        $b = $svc->store($inv, ['title' => 'Fees saved', 'type' => 'cost_saving', 'unit' => 'currency', 'target_value' => '1000', 'owner_id' => $this->manager->id, 'due_on' => '2027-12-31'], $this->fin);

        foreach ([['aggregation', 'latest'], ['type', 'revenue'], ['measurement_method', 'Monthly ledger pull']] as [$k, $v]) {
            $this->assertRefused(fn () => $svc->update($b->refresh(), [$k => $v], $this->fin), $k);
        }
        // unchanged values, including null baseline and null method, are accepted
        $out = $svc->update($b->refresh(), ['baseline_value' => $b->baseline_value, 'measurement_method' => null, 'type' => 'cost_saving', 'aggregation' => $b->aggregation, 'title' => 'Fees saved v2'], $this->fin);
        $this->assertSame('Fees saved v2', $out->title);
    }
}

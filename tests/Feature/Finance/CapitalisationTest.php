<?php

namespace Tests\Feature\Finance;

use App\Models\Asset;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Investment;
use App\Models\User;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\FinancePolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * FIN-03.13 (capitalisation and amortisation, IAS 38 and IAS 16), plan P6-11 to P6-14, on the P6 fixture bank. The investment manager prepares,
 * the Finance Controller or the CFO decides; the booking month is the month of "today" (2027-04-05).
 */
class CapitalisationTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    private CapitalisationService $svc;

    private Investment $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();
        $this->svc = app(CapitalisationService::class);
        $this->inv = $this->inDelivery($this->approvedInvestment());
        $this->flagCapitalisable($this->inv);
        $this->capexActual($this->inv, '900000.00', '2027-03-20');
    }

    /** A ready capitalisation of the investment prepared by its manager, who must be able to see it. */
    private function preparedBy(User $user, array $over = [], ?Investment $inv = null): Capitalisation
    {
        $inv ??= $this->inv;
        $afu = $over['available_for_use_on'] ?? '2027-03-31';
        $e = $this->svc->eligibility('investment', $inv->id, $afu, $this->org->id);
        $c = $this->svc->store($over + [
            'source_type' => 'investment', 'source_id' => $inv->id, 'title' => 'Release 1', 'standard' => 'ias_38', 'available_for_use_on' => $afu, 'amount' => $e['remaining'],
            'useful_life_months' => 60, 'method' => 'straight_line', 'create_asset' => true,
        ], $user);

        return $this->svc->update($c, ['criteria_checklist' => collect($c->criteria_checklist)->map(fn ($i) => ['key' => $i['key'], 'met' => true, 'note' => 'Evidence is on file for this criterion.'])->all()], $user);
    }

    public function test_eligibility_is_capex_up_to_the_available_for_use_date_less_what_is_already_capitalised(): void // FIN-03.13, P6-11
    {
        $this->capexActual($this->inv, '300000.00', '2027-04-02'); // after the March date
        $this->postActual($this->line, $this->periodSeq(3), '777000.00', ['investment_id' => $this->inv->id]); // operating spend does not qualify

        $march = $this->svc->eligibility('investment', $this->inv->id, '2027-03-31', $this->org->id);
        $this->assertSame('900000.00', $march['pool']);
        $this->assertSame('900000.00', $march['remaining']);
        $this->assertSame('1200000.00', $this->svc->eligibility('investment', $this->inv->id, '2027-04-05', $this->org->id)['pool']);

        $this->assertRefused(fn () => $this->svc->store([
            'source_type' => 'investment', 'source_id' => $this->inv->id, 'title' => 'Too much', 'standard' => 'ias_38', 'available_for_use_on' => '2027-03-31', 'amount' => '900000.01', 'useful_life_months' => 60,
        ], $this->manager), 'amount', 'exceeds');

        $first = $this->preparedBy($this->manager, ['amount' => '600000.00']);
        $this->svc->submit($first, $this->manager);
        $after = $this->svc->eligibility('investment', $this->inv->id, '2027-03-31', $this->org->id);
        $this->assertSame('600000.00', $after['already']); // submitted amounts are held
        $this->assertSame('300000.00', $after['remaining']);
        $this->assertRefused(fn () => $this->preparedBy($this->manager, ['amount' => '400000.00']), 'amount');
        $this->assertSame('900000.00', $this->svc->eligibility('investment', $this->inv->id, '2027-03-31', $this->org->id, $first->id)['remaining']); // not counting itself

        $candidates = $this->svc->candidates($this->org->id, null, $this->fin);
        $this->assertSame([$this->inv->id], array_column($candidates, 'id'));
        $this->assertSame('600000.00', $candidates[0]['remaining']); // today's pool 1,200,000 less the 600,000 submitted
    }

    public function test_the_ias_checklist_must_be_complete_with_notes_before_submit(): void // FIN-03.13, P6-12
    {
        $draft = $this->svc->store([
            'source_type' => 'investment', 'source_id' => $this->inv->id, 'title' => 'Release 1', 'standard' => 'ias_38', 'available_for_use_on' => '2027-03-31', 'amount' => '500000.00', 'useful_life_months' => 60,
        ], $this->manager);
        $this->assertCount(7, $draft->criteria_checklist);
        $this->assertRefused(fn () => $this->svc->submit($draft, $this->manager), 'criteria_checklist');

        $items = collect($draft->criteria_checklist)->map(fn ($i) => ['key' => $i['key'], 'met' => true, 'note' => 'Evidence is on file.'])->all();
        $items[3]['note'] = 'short'; // under ten characters
        $draft = $this->svc->update($draft, ['criteria_checklist' => $items], $this->manager);
        $this->assertRefused(fn () => $this->svc->submit($draft, $this->manager), 'criteria_checklist', $draft->criteria_checklist[3]['label']);
        $items[3]['note'] = 'Benefits case approved by the steering committee.';
        $items[5]['met'] = false;
        $draft = $this->svc->update($draft, ['criteria_checklist' => $items], $this->manager);
        $this->assertRefused(fn () => $this->svc->submit($draft, $this->manager), 'criteria_checklist');
        $items[5]['met'] = true;
        $draft = $this->svc->update($draft, ['criteria_checklist' => $items], $this->manager);
        $this->assertSame('submitted', $this->svc->submit($draft, $this->manager)->status);

        $hw = $this->capexExpense('3000000.00');
        ExpenseRequest::withoutGlobalScopes()->whereKey($hw->id)->update(['status' => 'approved']); // billed while approved, received afterwards
        $this->capexActual($hw, '3000000.00', '2027-03-25');
        ExpenseRequest::withoutGlobalScopes()->whereKey($hw->id)->update(['status' => 'received']);
        $c = $this->makeCapitalisation($hw);
        $this->assertCount(3, $c->criteria_checklist); // IAS 16
        $this->assertSame('ias_16', $c->standard);
    }

    public function test_approval_without_stages_needs_someone_other_than_the_preparer_and_the_manager(): void // FIN-03.13, SoD
    {
        $c = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '500000.00']), $this->manager);
        $this->manager->givePermissionTo('approve it-finance');
        $this->assertRefused(fn () => $this->svc->approve($c, $this->manager), 'approval'); // the preparer and investment manager
        $this->fin->refresh();
        $this->assertSame('submitted', $c->refresh()->status);
        $approver = $this->userWith('Finance Approver');
        $msg = $this->svc->approve($c, $approver);
        $this->assertStringContainsString('approved', $msg);
        $c->refresh();
        $this->assertSame('capitalised', $c->status);
        $this->assertSame($approver->id, $c->approved_by_id);
        $this->assertSame($this->periodSeq(4)->id, $c->booking_period_id);
        $this->assertRefused(fn () => $this->svc->approve($c, $this->cfo)); // already booked

        $this->assertRefused(fn () => $this->svc->approve($this->svc->submit($this->preparedBy($this->manager, ['amount' => '100000.00', 'title' => 'Second']), $this->manager), $this->userWith('IT Budget Owner')), 'approval'); // no approve permission
    }

    public function test_approval_through_configured_stages_routes_the_cfo_stage_by_value(): void // FIN-03.13, FIN-04.11
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $small = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '500000.00']), $this->manager);
        $this->assertRefused(fn () => $this->svc->approve($small, $this->cfo), 'approval'); // the Finance Controller stage first: the CFO does not hold that role
        $this->svc->approve($small, $this->fin);
        $this->assertSame('capitalised', $small->refresh()->status); // under the CFO band: one stage

        $this->capexActual($this->inv, '900000000.00', '2027-03-25');
        $big = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '800000000.00', 'title' => 'Release 2']), $this->manager);
        $message = $this->svc->approve($big, $this->fin);
        $this->assertSame('submitted', $big->refresh()->status, $message); // waits for the CFO
        $this->assertRefused(fn () => $this->svc->approve($big, $this->fin), 'approval'); // the same person cannot take the second stage
        $this->svc->approve($big, $this->cfo);
        $this->assertSame('capitalised', $big->refresh()->status);
    }

    public function test_the_booking_period_must_be_open_and_earlier_months_become_catch_up_lines(): void // FIN-03.13, P6-13
    {
        $this->capexActual($this->inv, '600000.00', '2027-01-10'); // January capex, so the pool exists at the January date
        $c = $this->svc->submit($this->preparedBy($this->manager, ['available_for_use_on' => '2027-01-15', 'amount' => '600000.00']), $this->manager);
        $this->closePeriodDirectly($this->periodSeq(4)); // April, the booking month
        $this->assertRefused(fn () => $this->svc->approve($c, $this->cfo), 'period_id');
        $this->assertSame('submitted', $c->refresh()->status);

        $this->closePeriodDirectly($this->periodSeq(4), 'open');
        $this->svc->approve($c, $this->cfo);
        $lines = $c->refresh()->lines;
        $this->assertCount(60, $lines);
        $this->assertSame([true, true, true, false], $lines->take(4)->pluck('is_catch_up')->map(fn ($v) => (bool) $v)->all()); // Jan, Feb, Mar against April
        $this->assertSame('2027-01-01', $lines[0]->month->toDateString());
        $this->assertSame($c->booking_period_id, $lines[0]->period_id); // catch-up lands in the booking period
        $this->assertSame($this->periodSeq(4)->id, $lines[3]->period_id);
        $this->assertSame('10000.00', (string) $lines[0]->amount);
    }

    public function test_straight_line_and_declining_schedules_persist_and_sum_exactly(): void // FIN-03.13, P6-13
    {
        $sl = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '400000.00', 'useful_life_months' => 7, 'residual_value' => '1.00']), $this->manager);
        $this->svc->approve($sl, $this->cfo);
        $this->assertSame('399999.00', bcadd((string) $sl->refresh()->lines()->sum('amount'), '0', 2)); // cost less residual, rounding absorbed by the last line
        $this->assertSame('1.00', (string) $sl->lines()->reorder('line_no', 'desc')->first()->closing_nbv);

        $dec = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '500000.00', 'title' => 'Declining', 'method' => 'declining', 'declining_rate_pct' => '40', 'useful_life_months' => 36, 'residual_value' => '50000.00']), $this->manager);
        $this->svc->approve($dec, $this->cfo);
        $this->assertSame('450000.00', bcadd((string) $dec->refresh()->lines()->sum('amount'), '0', 2));
        $first = $dec->lines()->first();
        $second = $dec->lines()->skip(1)->first();
        $this->assertGreaterThan((float) $second->amount, (float) $first->amount); // front-loaded
        $this->assertSame('50000.00', (string) $dec->lines()->reorder('line_no', 'desc')->first()->closing_nbv);
        $this->assertSame((string) $first->closing_nbv, (string) $second->opening_nbv);
    }

    public function test_a_new_asset_is_created_with_code_owner_and_end_of_life_or_an_existing_one_is_linked(): void // FIN-03.13, P6-14
    {
        $c = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '300000.00', 'asset_name' => 'Mobile app v2', 'asset_owner_id' => $this->owner->id, 'useful_life_months' => 48]), $this->manager);
        $this->svc->approve($c, $this->cfo);
        $asset = Asset::withoutGlobalScopes()->findOrFail($c->refresh()->asset_id);
        $this->assertSame($this->org->id, (int) $asset->organization_id);
        $this->assertSame('Mobile app v2', $asset->name);
        $this->assertSame($this->owner->id, (int) $asset->owner_id);
        $this->assertSame('software', $asset->asset_type);
        $this->assertSame('2031-03-31', $asset->end_of_life->toDateString()); // 48 months from the available-for-use date
        $this->assertNotEmpty($asset->asset_id_code);
        $this->assertContains($c->capitalisation_code, (array) $asset->tags);

        $existing = Asset::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'asset_id_code' => 'AST-EXISTING', 'name' => 'Core switch', 'asset_type' => 'hardware']);
        $linked = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '100000.00', 'title' => 'Linked', 'asset_id' => $existing->id, 'create_asset' => false]), $this->manager);
        $before = Asset::withoutGlobalScopes()->count();
        $this->svc->approve($linked, $this->cfo);
        $this->assertSame($existing->id, $linked->refresh()->asset_id);
        $this->assertSame($before, Asset::withoutGlobalScopes()->count()); // nothing new was created

        $foreign = Asset::withoutGlobalScopes()->create(['organization_id' => $this->otherBank()->id, 'asset_id_code' => 'AST-THEIRS', 'name' => 'Theirs', 'asset_type' => 'hardware']);
        $this->assertRefused(fn () => $this->preparedBy($this->manager, ['amount' => '10000.00', 'asset_id' => $foreign->id]), 'asset_id');
    }

    public function test_reject_reopen_resubmit_withdraw_and_cancel(): void // FIN-03.13
    {
        $c = $this->svc->submit($this->preparedBy($this->manager, ['amount' => '500000.00']), $this->manager);
        $this->assertRefused(fn () => $this->svc->reject($c, $this->cfo, ''), 'note');
        $this->assertSame('rejected', $this->svc->reject($c, $this->cfo, 'Research phase cost is included; split it out.')->status);
        $this->assertSame('900000.00', $this->svc->eligibility('investment', $this->inv->id, '2027-03-31', $this->org->id)['remaining']); // a rejection releases the hold

        $c = $this->svc->update($c->refresh(), ['excluded_amount' => '50000.00', 'excluded_reason' => 'Research-phase work, expensed (IAS 38.54).', 'amount' => '450000.00'], $this->manager); // editing a rejected one re-opens it
        $this->assertSame('draft', $c->status);
        $c = $this->svc->submit($c, $this->manager);
        $this->assertSame('submitted', $c->status);

        $this->assertRefused(fn () => $this->svc->withdraw($c, $this->userWith('IT Budget Owner')), null); // only the preparer
        $this->assertSame('draft', $this->svc->withdraw($c, $this->manager)->status);
        $this->assertRefused(fn () => $this->svc->cancel($c, 'short', $this->manager), 'reason');
        $c = $this->svc->cancel($c, 'The release was folded into Release 2.', $this->manager);
        $this->assertSame('cancelled', $c->status);
        $this->assertRefused(fn () => $this->svc->submit($c, $this->manager));
    }

    public function test_amounts_are_masked_and_visibility_follows_cost_centre_ownership(): void // FIN-07.10
    {
        $c = $this->preparedBy($this->manager, ['amount' => '500000.00']);
        $rows = fn ($user) => $this->actingAs($user)->get(route('finance.capitalisations.index'))->assertOk()->original->getData()['page']['props']['capitalisations']['data'];

        $this->assertCount(1, $rows($this->fin)); // the controller sees every cost centre
        $this->assertCount(1, $rows($this->owner)); // owns the investment's cost centre
        $this->assertCount(0, $rows($this->owner2)); // owns another cost centre only
        $this->actingAs($this->owner2)->get(route('finance.capitalisations.show', $c))->assertForbidden();

        $viewer = $this->userWith('IT Budget Owner');
        $viewer->roles()->detach();
        $viewer->givePermissionTo(['view it-finance']);
        $this->cc->forceFill(['owner_id' => $viewer->id])->save();
        $row = $rows($viewer)[0];
        $this->assertNull($row['amount']);
        $this->assertNull($this->actingAs($viewer)->get(route('finance.capitalisations.show', $c))->assertOk()->original->getData()['page']['props']['capitalisation']['amount']);
    }

    public function test_the_source_must_be_a_capitalisable_investment_in_a_late_enough_stage_or_a_received_capex_expense(): void // FIN-03.13, P6-11
    {
        $plain = $this->makeInvestment(['title' => 'Plain']);
        Investment::withoutGlobalScopes()->whereKey($plain->id)->update(['stage' => 'in_delivery']);
        $plain->refresh();
        $data = fn (Investment $i) => ['source_type' => 'investment', 'source_id' => $i->id, 'title' => 'X', 'standard' => 'ias_38', 'available_for_use_on' => '2027-03-31', 'amount' => '100000.00', 'useful_life_months' => 12];
        $this->assertRefused(fn () => $this->svc->store($data($plain), $this->manager), 'source_id', 'not flagged'); // not capitalisable

        $early = $this->makeInvestment(['title' => 'Early']);
        Investment::withoutGlobalScopes()->whereKey($early->id)->update(['stage' => 'approved']);
        $early = $this->flagCapitalisable($early->refresh());
        $this->assertRefused(fn () => $this->svc->store($data($early), $this->manager), 'source_id', 'cannot be capitalised yet'); // approved, not yet in delivery

        $opex = $this->expense(['title' => 'Support renewal', 'amount' => '500000.00']);
        $this->assertRefused(fn () => $this->svc->store(['source_type' => 'expense', 'source_id' => $opex->id] + $data($plain), $this->requester), 'source_id');
        $draftCapex = $this->capexExpense('800000.00');
        ExpenseRequest::withoutGlobalScopes()->whereKey($draftCapex->id)->update(['status' => 'approved']);
        $this->assertRefused(fn () => $this->svc->store(['source_type' => 'expense', 'source_id' => $draftCapex->id] + $data($plain), $this->requester), 'source_id', 'cannot be capitalised yet');

        $stranger = $this->userWith('IT Budget Owner'); // sees neither cost centre
        $this->assertRefused(fn () => $this->svc->store($data($this->inv), $stranger), 'source_id');
        $foreign = $this->userWith('IT Finance Controller', [], $this->otherBank()->id);
        $this->assertRefused(fn () => $this->svc->store($data($this->inv), $foreign), 'source_id');
    }
}

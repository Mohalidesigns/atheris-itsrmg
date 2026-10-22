<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\ContractRenewed;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Asset;
use App\Models\Evidence;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\ContractTeamMember;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FxRate;
use App\Models\Finance\Renewal;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ContractService;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RenewalService;
use App\Services\Finance\TerminationService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P2 backend slice smoke (FIN-06.01 to .10, FIN-07.01/.02/.10): register, dates, derivation, team/links/documents,
 * the renewal path run unconfigured, masking and visibility. The full acceptance suite is the QA work package.
 */
class ContractBackendSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $fin;

    private User $owner;

    private User $owner2;

    private User $procurement;

    private User $cfo;

    private Vendor $vendor;

    private CostCentre $cc;

    private CostCentre $cc2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2027-03-01 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create(['currency' => 'NGN']);
        $mk = fn (string $role, array $extra = []) => tap(User::factory()->create(['organization_id' => $this->org->id] + $extra), fn ($u) => $u->assignRole($role));
        $this->fin = $mk('IT Finance Controller');
        $this->owner = $mk('IT Budget Owner');
        $this->owner2 = $mk('IT Budget Owner');
        $this->procurement = $mk('Procurement Officer');
        $this->cfo = $mk('Finance Approver', ['job_title' => 'Chief Financial Officer']);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $this->actingAs($this->fin);
        $this->cc = CostCentre::create(['code' => 'CC-1', 'name' => 'Operations', 'owner_id' => $this->owner->id]);
        $this->cc2 = CostCentre::create(['code' => 'CC-2', 'name' => 'Security', 'owner_id' => $this->owner2->id]);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1500, 'rate_date' => '2027-01-01', 'rate_type' => 'spot']);
        $this->vendor = Vendor::factory()->create(['organization_id' => $this->org->id, 'name' => 'Infosys Finacle']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function make(array $over = [], ?User $by = null): Contract
    {
        return app(ContractService::class)->create($over + [
            'title' => 'Finacle AMC', 'type' => 'support_maintenance', 'vendor_id' => $this->vendor->id, 'owner_id' => $this->owner->id, 'cost_centre_id' => $this->cc->id,
            'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'notice_period_days' => 30, 'contract_value' => '12000000', 'activate' => true,
        ], $by ?? $this->fin);
    }

    private function refused(callable $fn, string $key, string $needle = ''): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            $needle !== '' && $this->assertStringContainsString($needle, implode(' ', $e->errors()[$key]));

            return;
        }
        $this->fail('Expected a refusal on '.$key);
    }

    /** FIN-06.01, FIN-06.03: create computes the code, term, FX and both dates. */
    public function test_create_computes_code_term_fx_and_dates(): void
    {
        $c = $this->make(['currency' => 'USD', 'contract_value' => '10000.00']);

        $this->assertSame('CON-0001', $c->contract_code);
        $this->assertSame(12, $c->term_months);
        $this->assertSame('2027-09-02', $c->renewal_window_opens_on->toDateString()); // notice deadline (1 Dec) minus 90 default lead days
        $this->assertSame('2027-12-01', $c->notice_deadline_on->toDateString());
        $this->assertSame('15000000.00', (string) $c->base_contract_value);
        $this->assertSame('1500.00000000', (string) $c->fx_rate);
        $this->assertSame('active', $c->status);
        $this->assertSame('CON-0002', $this->make()->contract_code);
        $this->assertSame('15000000.00', $c->annualised_base_value);
    }

    /** FIN-06.03: edits recompute the dates and re-derive the status, including back to active. */
    public function test_update_recomputes_dates_and_rederives_status(): void
    {
        $c = $this->make();
        $svc = app(ContractService::class);

        $c = $svc->update($c, ['ends_on' => '2027-04-15'], $this->fin);
        $this->assertSame('in_renewal_window', $c->status);
        $this->assertSame('2026-12-16', $c->renewal_window_opens_on->toDateString());

        $c = $svc->update($c, ['renewal_lead_days' => 10], $this->fin);
        $this->assertSame('2027-03-06', $c->renewal_window_opens_on->toDateString());
        $this->assertSame('active', $c->status);
        $this->assertTrue(ContractEvent::withoutGlobalScopes()->where('contract_id', $c->id)->where('type', 'window_opened')->exists());

        $c = $svc->update($c, ['ends_on' => '2027-02-10'], $this->fin);
        $this->assertSame('expired', $c->status);
    }

    public function test_activate_needs_owner_vendor_and_dates_and_drafts_can_be_deleted(): void
    {
        $svc = app(ContractService::class);
        $draft = $svc->create(['title' => 'Draft', 'type' => 'other', 'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->fin);
        $this->assertSame('draft', $draft->status);
        $this->refused(fn () => $svc->activate($draft, $this->fin), 'workflow', 'vendor');
        $draft = $svc->update($draft, ['vendor_id' => $this->vendor->id, 'owner_id' => $this->owner->id], $this->fin);
        $this->assertSame('active', $svc->activate($draft, $this->fin)->status);
        $this->refused(fn () => $svc->delete($draft->refresh(), $this->fin), 'workflow', 'draft');

        $other = $svc->create(['title' => 'Throwaway', 'type' => 'other', 'commenced_on' => '2027-01-01', 'ends_on' => '2027-06-30'], $this->fin);
        $svc->delete($other, $this->fin);
        $this->assertSoftDeleted('fin_contracts', ['id' => $other->id]);
    }

    /** FIN-06.01, FIN-06.08: team, links and documents, with the document hash and the vault record. */
    public function test_team_links_and_documents(): void
    {
        Storage::fake('local');
        $svc = app(ContractService::class);
        $c = $this->make();
        $asset = Asset::factory()->create(['organization_id' => $this->org->id]);
        $foreign = Asset::factory()->create();

        $svc->addTeamMember($c, $this->owner2->id, 'project_lead', $this->fin);
        $this->refused(fn () => $svc->addTeamMember($c, $this->owner2->id, 'member', $this->fin), 'user_id', 'already');
        $this->refused(fn () => $svc->addTeamMember($c, User::factory()->create()->id, 'member', $this->fin), 'user_id', 'bank');
        $this->assertSame([$this->owner->id, $this->owner2->id], $svc->recipients($c)->pluck('id')->sort()->values()->all());
        $svc->removeTeamMember(ContractTeamMember::where('contract_id', $c->id)->first(), $this->fin);
        $this->assertSame(1, $svc->recipients($c)->count());

        $link = $svc->addLink($c, 'asset', $asset->id, 'Core switch', $this->fin);
        $this->refused(fn () => $svc->addLink($c, 'asset', $asset->id, null, $this->fin), 'id', 'already');
        $this->refused(fn () => $svc->addLink($c, 'asset', $foreign->id, null, $this->fin), 'id', 'bank');
        $this->refused(fn () => $svc->addLink($c, 'vendor', 1, null, $this->fin), 'type');
        $this->assertSame('asset', $link->type_key);
        $svc->removeLink($link, $this->fin);
        $this->assertSame(0, $c->links()->count());

        $file = UploadedFile::fake()->createWithContent('contract.pdf', "%PDF-1.4\nsigned contract");
        $doc = $svc->addDocument($c, ['kind' => 'contract', 'title' => 'Signed contract', 'signed_on' => '2027-01-01'], $file, $this->fin);
        $this->assertSame(hash('sha256', "%PDF-1.4\nsigned contract"), $doc->sha256);
        $evidence = Evidence::withoutGlobalScopes()->findOrFail($doc->evidence_id);
        $this->assertSame(Contract::class, $evidence->evidenceable_type);
        $this->assertSame($c->id, (int) $evidence->evidenceable_id);
        Storage::disk('local')->assertExists($evidence->file_path);
        $this->assertStringStartsWith("evidence/{$this->org->id}/contracts/", $evidence->file_path);
        $this->actingAs($this->fin)->get(route('finance.contracts.documents.download', [$c, $doc]))->assertOk();
        $this->refused(fn () => $svc->addDocument($c, ['kind' => 'nope', 'title' => 'x'], null, $this->fin), 'kind');
        $this->assertTrue(ContractEvent::where('contract_id', $c->id)->where('type', 'document_added')->exists());
    }

    /** FIN-06.05, FIN-07.01/.02: the unconfigured renewal path, with segregation of duties and the successor term. */
    public function test_renewal_open_recommend_submit_approve_execute_unconfigured(): void
    {
        Event::fake([ContractRenewed::class]);
        $svc = app(RenewalService::class);
        $contracts = app(ContractService::class);
        $c = $this->make(['ends_on' => '2027-05-20', 'term_months' => 12, 'price_escalation_pct' => '5']);
        $contracts->addTeamMember($c, $this->owner2->id, 'member', $this->fin);
        $this->assertSame('in_renewal_window', $c->status);

        $r = $svc->open($c, $this->procurement);
        $this->assertSame('REN-0001', $r->renewal_code);
        $this->refused(fn () => $svc->open($c, $this->procurement), 'workflow', 'already');
        $this->refused(fn () => $svc->submit($r, $this->procurement), 'workflow', 'recommendation');
        $this->refused(fn () => $svc->recommend($r, ['recommendation' => 'extend_short_term', 'proposed_term_months' => 18, 'proposed_value' => '1', 'proposed_currency' => 'NGN'], $this->procurement), 'proposed_term_months');

        $r = $svc->recommend($r, [
            'recommendation' => 'renew_renegotiated', 'proposed_term_months' => 12, 'proposed_value' => '13200000', 'proposed_currency' => 'NGN',
            'justification' => 'Business-critical', 'benchmark_notes' => 'Market is flat',
        ], $this->procurement);
        $this->assertSame('10.00', (string) $r->uplift_pct);
        $this->assertSame('5-10', $r->uplift_band);
        $this->assertSame('yes', $r->requires_cfo); // 13.2m is above the 10m threshold

        $r = $svc->submit($r, $this->procurement);
        $this->assertSame('submitted', $r->status);
        $this->assertSame($this->procurement->id, $r->requested_by_id);

        $this->refused(fn () => $svc->approve($r, $this->procurement), 'approval', 'permission');           // not an approver at all
        $this->refused(fn () => $svc->approve($r, $this->owner), 'approval', 'permission');                  // contract owner holds no approval permission
        $this->owner->givePermissionTo('approve it-finance-contracts');
        $this->refused(fn () => $svc->approve($r, $this->owner), 'approval', 'own this record');             // owner may not approve
        $this->procurement->givePermissionTo('approve it-finance-contracts');
        $this->refused(fn () => $svc->approve($r, $this->procurement), 'approval', 'submitted this');        // requester may not approve
        $this->refused(fn () => $svc->execute($r->refresh(), $this->fin), 'workflow', 'submitted');

        $svc->approve($r, $this->cfo);
        $r->refresh();
        $this->assertSame('approved', $r->status);
        $this->assertSame('renew_renegotiated', $c->refresh()->decision);
        $this->assertTrue(ContractEvent::where('contract_id', $c->id)->where('type', 'decision_recorded')->exists());

        $new = $svc->execute($r, $this->fin);
        $this->assertSame('renewed', $c->refresh()->status);
        $this->assertSame($new->id, $c->renewed_to_id);
        $this->assertSame($c->id, $new->renewed_from_id);
        $this->assertSame('2027-05-21', $new->commenced_on->toDateString());
        $this->assertSame('2028-05-20', $new->ends_on->toDateString());
        $this->assertSame('13200000.00', (string) $new->contract_value);
        $this->assertSame($c->cost_centre_id, $new->cost_centre_id);
        $this->assertSame([$this->owner2->id], $new->team()->pluck('user_id')->all());
        $this->assertSame('executed', $r->refresh()->status);
        $this->assertSame($new->id, $r->new_contract_id);
        Event::assertDispatched(ContractRenewed::class, fn ($e) => $e->successor->id === $new->id && $e->auto === false);
        $this->refused(fn () => app(ContractService::class)->update($c, ['title' => 'x'], $this->fin), 'workflow', 'read-only');
    }

    /** C49/C50: configured stages are Head of IT, then the CFO only when the renewal needs the CFO; nobody decides twice. */
    public function test_renewal_runs_through_configured_workflow_stages(): void
    {
        Event::fake([ContractRenewed::class]);
        $wf = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'process' => RenewalService::PROCESS, 'is_active' => true]);
        foreach ([[10, 'Head of IT review', 'Head of IT', null, null], [20, 'CFO approval', 'Chief Financial Officer', 'requires_cfo', ['yes']]] as [$seq, $name, $title, $field, $values]) {
            $wf->stages()->create(['organization_id' => $this->org->id, 'sequence' => $seq, 'name' => $name, 'approver_type' => 'job_title', 'approver_ref' => $title,
                'sla_hours' => 72, 'min_approvals' => 1, 'is_active' => true, 'condition_field' => $field, 'condition_values' => $values]);
        }
        $head = User::factory()->create(['organization_id' => $this->org->id, 'job_title' => 'Head of IT Governance']);
        $head->assignRole('Finance Approver');
        $svc = app(RenewalService::class);
        $submit = function (string $value) use ($svc) {
            $c = $this->make(['title' => 'Contract '.$value, 'contract_value' => $value]);
            $r = $svc->recommend($svc->open($c, $this->procurement), ['recommendation' => 'renew_as_is', 'proposed_term_months' => 12, 'proposed_value' => $value, 'proposed_currency' => 'NGN', 'justification' => 'Needed'], $this->procurement);

            return $svc->submit($r, $this->procurement);
        };

        $big = $submit('20000000');
        $this->assertSame('yes', $big->requires_cfo);
        $this->assertStringContainsString('awaiting', strtolower($svc->approve($big, $head) ?: ''));
        $this->assertSame('submitted', $big->refresh()->status);
        $this->refused(fn () => $svc->approve($big, $head), 'approval');
        $svc->approve($big, $this->cfo);
        $this->assertSame('approved', $big->refresh()->status);

        $small = $submit('4000000');
        $this->assertSame('no', $small->requires_cfo);
        $svc->approve($small, $head);
        $this->assertSame('approved', $small->refresh()->status);
        $this->assertSame($head->id, $small->approved_by_id);
        $trail = app(FinanceApprovals::class)->trail(RenewalService::PROCESS, $big, $this->cfo);
        $this->assertSame('yes', $trail['context']['conditions']['requires_cfo']);
        $this->assertSame('20000000.00', $trail['context']['base_amount']);
    }

    public function test_replace_terminate_and_early_termination(): void
    {
        Event::fake([ContractRenewed::class]);
        $svc = app(RenewalService::class);
        $this->owner->givePermissionTo('approve it-finance-contracts');
        $run = function (Contract $c, string $rec) use ($svc) {
            $r = $svc->recommend($svc->open($c, $this->procurement), ['recommendation' => $rec, 'proposed_term_months' => 6, 'proposed_value' => '500000', 'proposed_currency' => 'NGN', 'justification' => 'Because'], $this->procurement);
            $svc->submit($r, $this->procurement);
            $svc->approve($r, $this->cfo);

            return $svc->execute($r->refresh(), $this->fin, ['terminated_on' => '2027-02-20']);
        };

        $a = $this->make(['title' => 'Old tool']);
        $new = $run($a, 'replace');
        $this->assertSame('replaced', $a->refresh()->status);
        $this->assertSame('draft', $new->status);
        $this->assertStringStartsWith('Replacement for', $new->title);

        $b = $this->make(['title' => 'Legacy gateway']);
        $this->assertNull($run($b, 'terminate'));
        $this->assertSame('terminated', $b->refresh()->status);
        $this->assertSame('2027-02-20', $b->terminated_on->toDateString());

        $c = $this->make(['title' => 'Cloud commitment']);
        $open = $svc->open($c, $this->procurement);
        $term = app(TerminationService::class);
        $approver = tap(User::factory()->create(['organization_id' => $this->org->id, 'job_title' => 'Head of IT']), fn ($u) => $u->assignRole('Finance Approver'));
        $term->approve($term->request($c, ['reason' => 'Vendor exit'], $this->cfo), $approver);
        $this->assertSame('terminated', $c->refresh()->status);
        $this->assertSame('withdrawn', $open->refresh()->status);
        $this->assertSame('terminate', $c->decision);
        $this->refused(fn () => $term->request($c, ['reason' => 'again'], $this->cfo), 'workflow');
    }

    /** FIN-06.02, FIN-06.09: an auto-renewing contract rolls into one new term, exactly once, with the escalation applied. */
    public function test_auto_renew_rolls_once_with_escalation(): void
    {
        Event::fake([ContractRenewed::class]);
        $c = $this->make(['ends_on' => '2027-02-28', 'term_months' => 12, 'renewal_type' => 'auto', 'auto_renew' => true, 'price_escalation_pct' => '10', 'title' => 'MTN link']);
        $svc = app(RenewalService::class);

        $new = $svc->autoRenew($c);
        $this->assertNotNull($new);
        $this->assertNull($svc->autoRenew($c->refresh()));
        $this->assertSame('renewed', $c->refresh()->status);
        $this->assertSame('13200000.00', (string) $new->contract_value);
        $this->assertSame('2027-03-01', $new->commenced_on->toDateString());
        $this->assertSame('active', $new->status);
        $r = Renewal::where('contract_id', $c->id)->first();
        $this->assertTrue($r->is_auto);
        $this->assertSame('executed', $r->status);
        Event::assertDispatchedTimes(ContractRenewed::class, 1);
    }

    /** FIN-06.10 and the vendor hand-off: the vendor's summary follows its largest live contract. */
    public function test_index_vendor_spend_and_vendor_summary_sync(): void
    {
        $this->make(['title' => 'Small', 'contract_value' => '1000000']);
        $big = $this->make(['title' => 'Big', 'contract_value' => '9000000', 'billing_frequency' => 'quarterly']);
        $vendor = Vendor::withoutGlobalScopes()->find($this->vendor->id);
        $this->assertSame('9000000.00', (string) $vendor->contract_value);
        $this->assertSame('2027-12-31', $vendor->contract_end->toDateString());

        $this->actingAs($this->fin)->get(route('finance.contracts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('contracts', 2)->where('summary.count', 2)->where('summary.value_base_total', '10000000.00')
            ->where('vendorSpend.0.vendor.name', 'Infosys Finacle')->where('vendorSpend.0.contracts', 2)->where('vendorSpend.0.base_contract_value', '10000000.00')
            ->where('can.viewAmounts', true));
        $this->actingAs($this->fin)->get(route('finance.contracts.show', ['contract' => $big, 'tab' => 'spend']))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('tab', 'spend')->where('spend.annualised_base_value', '9000000.00')->has('spend.billing', 3)->where('can.edit', true));
    }

    /** FIN-07.10: without `view it-finance-amounts` every amount leaves the server as null. */
    public function test_amounts_are_masked_without_the_amounts_permission(): void
    {
        $viewer = User::factory()->create(['organization_id' => $this->org->id]);
        $viewer->assignRole('Viewer');
        $c = $this->make(['owner_id' => $viewer->id, 'currency' => 'USD', 'contract_value' => '5000', 'price_escalation_pct' => '3']);
        $this->actingAs($viewer)->get(route('finance.contracts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('contracts', 1)->where('contracts.0.contract_value', null)->where('contracts.0.base_contract_value', null)->where('contracts.0.amount_band', null)
            ->where('contracts.0.annualised_base_value', null)->where('summary.value_base_total', null)->where('vendorSpend', [])->where('can.viewAmounts', false));
        $this->actingAs($viewer)->get(route('finance.contracts.show', $c))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('contract.contract_value', null)->where('contract.fx_rate', null)->where('contract.next_term_forecast', null)->where('spend.billing', [])
            ->where('spend.base_contract_value', null)->where('can.edit', false));
    }

    /** §8 / C70: a budget owner sees their cost centre and their own contracts; Procurement sees everything. */
    public function test_visibility_budget_owner_vs_procurement(): void
    {
        $mine = $this->make(['title' => 'In my cost centre', 'cost_centre_id' => $this->cc->id, 'owner_id' => $this->fin->id]);
        $theirs = $this->make(['title' => 'Security tool', 'cost_centre_id' => $this->cc2->id, 'owner_id' => $this->fin->id]);
        $named = $this->make(['title' => 'Owned by owner2 elsewhere', 'cost_centre_id' => $this->cc->id, 'owner_id' => $this->owner2->id]);
        $team = $this->make(['title' => 'Owner2 is on the team', 'cost_centre_id' => $this->cc->id, 'owner_id' => $this->fin->id]);
        app(ContractService::class)->addTeamMember($team, $this->owner2->id, 'member', $this->fin);

        $titles = fn (User $u) => collect($this->actingAs($u)->get(route('finance.contracts.index'))->assertOk()->viewData('page')['props']['contracts'])->pluck('title')->sort()->values()->all();
        $this->assertSame(['In my cost centre', 'Owned by owner2 elsewhere', 'Owner2 is on the team'], $titles($this->owner));
        $this->assertSame(['Owned by owner2 elsewhere', 'Owner2 is on the team', 'Security tool'], $titles($this->owner2));
        $this->assertCount(4, $titles($this->procurement));
        $this->assertCount(4, $titles($this->fin));

        $this->actingAs($this->owner)->get(route('finance.contracts.show', $theirs))->assertForbidden();
        $this->actingAs($this->owner2)->get(route('finance.contracts.show', $theirs))->assertOk();
        $this->actingAs($this->procurement)->get(route('finance.contracts.show', $mine))->assertOk();
    }

    /** Request validation: same-bank foreign keys, enums and money format on the HTTP path. */
    public function test_store_validates_foreign_keys_and_money(): void
    {
        $foreignVendor = Vendor::factory()->create();
        $payload = ['title' => 'X', 'type' => 'saas', 'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'contract_value' => '100'];
        $this->actingAs($this->procurement)->post(route('finance.contracts.store'), $payload + ['vendor_id' => $foreignVendor->id])->assertSessionHasErrors('vendor_id');
        $this->actingAs($this->procurement)->post(route('finance.contracts.store'), ['contract_value' => '12.345', 'type' => 'zzz'] + $payload)->assertSessionHasErrors(['type', 'contract_value']);
        $this->actingAs($this->procurement)->post(route('finance.contracts.store'), ['ends_on' => '2026-01-01'] + $payload)->assertSessionHasErrors('ends_on');
        $this->actingAs($this->owner2)->get(route('finance.contracts.create'))->assertOk();
        $this->actingAs($this->procurement)->post(route('finance.contracts.store'), $payload + ['vendor_id' => $this->vendor->id, 'owner_id' => $this->owner->id, 'activate' => true])
            ->assertRedirect();
        $this->assertSame('active', Contract::where('title', 'X')->first()->status);
        $this->actingAs(User::factory()->create(['organization_id' => $this->org->id]))->get(route('finance.contracts.index'))->assertForbidden();
    }
}

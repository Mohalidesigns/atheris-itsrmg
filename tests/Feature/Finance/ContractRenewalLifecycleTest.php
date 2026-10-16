<?php

namespace Tests\Feature\Finance;

use App\Events\Finance\ContractRenewed;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Asset;
use App\Models\Evidence;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\Renewal;
use App\Models\Finance\Setting;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\ContractService;
use App\Services\Finance\ContractStatusDeriver;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RenewalService;
use App\Services\Finance\TerminationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsContracts;
use Tests\TestCase;

/**
 * P2 acceptance (ATH-FIN-001 §12, FIN-06.01 to .10, FIN-07.01/.06/.10): the contract register, date computation,
 * status derivation, the renewal decision through Workflow Studio and the default path, segregation of duties,
 * documents, masking, visibility and the audit trail.
 */
class ContractRenewalLifecycleTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootContracts();
        Notification::fake();
    }

    /** FIN-06.01: the code series, term, FX conversion into the base currency and the refusals on bad input. */
    public function test_register_fields_codes_and_fx(): void
    {
        $c = $this->contract(['currency' => 'USD', 'contract_value' => '10000.00', 'title' => 'Oracle support']);

        $this->assertSame('CON-0001', $c->contract_code);
        $this->assertSame(12, $c->term_months);
        $this->assertSame('15000000.00', (string) $c->base_contract_value);
        $this->assertSame('1500.00000000', (string) $c->fx_rate);
        $this->assertSame('spot', $c->fx_rate_type);
        $this->assertSame('active', $c->status);
        $this->assertSame('CON-0002', $this->contract()->contract_code);
        $this->assertSame('10000.00', (string) Vendor::find($this->vendor->id)->contract_value, 'the vendor summary follows the largest live contract');

        $svc = app(ContractService::class);
        $other = $this->otherBank();
        $foreignVendor = Vendor::factory()->create(['organization_id' => $other->id]);
        $base = ['title' => 'X', 'type' => 'other', 'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31'];
        $this->refuses(fn () => $svc->create($base + ['vendor_id' => $foreignVendor->id], $this->procurement), 'vendor_id', 'bank');
        $this->refuses(fn () => $svc->create($base + ['owner_id' => User::factory()->create(['organization_id' => $other->id])->id], $this->procurement), 'owner_id', 'bank');
        $this->refuses(fn () => $svc->create(['ends_on' => '2026-12-31'] + $base, $this->procurement), 'ends_on', 'on or after');
        $this->refuses(fn () => $svc->create(['notice_period_days' => 500] + $base, $this->procurement), 'notice_period_days', 'longer');
        $this->refuses(fn () => $svc->create(['currency' => 'EUR', 'contract_value' => '10'] + $base, $this->procurement), 'currency');
        $this->refuses(fn () => $svc->create(['type' => 'bogus'] + $base, $this->procurement), 'type');
    }

    /** FIN-06.03: window and notice dates are computed on create and recomputed on edit; the lead-days override beats the settings default. */
    public function test_dates_computed_on_create_and_recomputed_on_edit(): void
    {
        $svc = app(ContractService::class);
        $plain = $this->contract(['title' => 'Default lead']);
        $own = $this->contract(['title' => 'Own lead', 'renewal_lead_days' => 30]);

        $this->assertSame('2027-09-02', $plain->renewal_window_opens_on->toDateString()); // notice deadline (1 Dec) minus the 90-day default
        $this->assertSame('2027-12-01', $plain->notice_deadline_on->toDateString());
        $this->assertSame('2027-11-01', $own->renewal_window_opens_on->toDateString());   // notice deadline minus its own 30

        Setting::withoutGlobalScopes()->where('organization_id', $this->org->id)->update(['renewal_lead_days' => 45]);
        app(FinanceSettings::class)->flush();
        $plain = $svc->update($plain, ['title' => 'Default lead, edited'], $this->procurement);
        $own = $svc->update($own, ['title' => 'Own lead, edited'], $this->procurement);
        $this->assertSame('2027-10-17', $plain->renewal_window_opens_on->toDateString(), 'the settings default now applies');
        $this->assertSame('2027-11-01', $own->renewal_window_opens_on->toDateString(), 'the override is untouched');

        $plain = $svc->update($plain, ['notice_period_days' => 60, 'ends_on' => '2027-09-30'], $this->procurement);
        $this->assertSame('2027-08-01', $plain->notice_deadline_on->toDateString());
        $this->assertSame('2027-06-17', $plain->renewal_window_opens_on->toDateString());
        $this->assertNull($svc->recompute($plain->refresh()), 'recompute is a no-op when nothing moved');
    }

    /** FIN-06.02: every derivation path, including draft and terminal states and moving back to active when dates move forward. */
    public function test_status_derivation_every_path(): void
    {
        $deriver = app(ContractStatusDeriver::class);
        $c = $this->contract(['commenced_on' => '2026-07-01', 'ends_on' => '2027-06-30']); // lead 90 before the notice deadline => window 2027-03-02, notice 2027-05-31
        $on = fn (string $d) => $deriver->derive($c, Carbon::parse($d));

        $this->assertSame('active', $on('2027-03-01'));
        $this->assertSame('in_renewal_window', $on('2027-03-02'));
        $this->assertSame('in_renewal_window', $on('2027-05-31'), 'the notice day itself is still inside the window');
        $this->assertSame('notice_deadline_passed', $on('2027-06-01'));
        $this->assertSame('notice_deadline_passed', $on('2027-06-30'), 'the end day itself is not yet expired');
        $this->assertSame('expired', $on('2027-07-01'));

        foreach (['draft', 'renewed', 'terminated', 'replaced'] as $status) {
            $c->status = $status;
            $this->assertSame($status, $on('2027-09-01'), "{$status} is never re-derived");
        }

        // dates moving forward bring a window contract back to active, with one event per transition
        Carbon::setTestNow('2027-04-15 09:00:00');
        $w = $this->contract(['title' => 'Window', 'commenced_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->assertSame('in_renewal_window', $w->status);
        $w = app(ContractService::class)->update($w, ['ends_on' => '2027-12-31'], $this->procurement);
        $this->assertSame('active', $w->status);
        $deriver->apply($w, Carbon::parse('2027-04-15'));
        $this->assertSame(1, ContractEvent::where('contract_id', $w->id)->where('type', 'window_opened')->count());
        $this->assertSame(3, ContractEvent::where('contract_id', $w->id)->where('type', 'status_changed')->count(), 'draft to active, active to window, and back to active');
    }

    /** FIN-06.02: a manual contract with no decision goes to notice-deadline-passed, then to expired, and is never rolled. */
    public function test_manual_no_decision_notice_passed_then_expired(): void
    {
        $c = $this->contract(['commenced_on' => '2026-04-01', 'ends_on' => '2027-03-31']); // notice deadline 2027-03-01
        $this->assertSame('in_renewal_window', $c->status);

        Artisan::call('fin:contracts-sweep', ['--date' => '2027-03-02']);
        $this->assertSame('notice_deadline_passed', $c->refresh()->status);
        $this->assertTrue(Contract::passed(Carbon::parse('2027-03-02'))->whereKey($c->id)->exists());

        Artisan::call('fin:contracts-sweep', ['--date' => '2027-04-01']);
        $this->assertSame('expired', $c->refresh()->status);
        $this->assertSame(0, Contract::where('renewed_from_id', $c->id)->count(), 'a manual contract is never rolled');
        $this->assertSame(['window_opened', 'notice_deadline_passed', 'expired'], ContractEvent::where('contract_id', $c->id)
            ->whereIn('type', ['window_opened', 'notice_deadline_passed', 'expired'])->orderBy('id')->pluck('type')->all());
    }

    /** FIN-06.02, FIN-06.09: an auto-renewing contract rolls exactly one new term even when the sweep runs twice; the escalation is applied. */
    public function test_auto_renew_rolls_one_new_term(): void
    {
        Event::fake([ContractRenewed::class]);
        $svc = app(ContractService::class);
        $c = $this->contract(['commenced_on' => '2026-03-01', 'ends_on' => '2027-02-28', 'renewal_type' => 'auto', 'auto_renew' => true, 'price_escalation_pct' => '10']);
        $svc->addTeamMember($c, $this->owner2->id, 'member', $this->fin);
        $asset = Asset::factory()->create(['organization_id' => $this->org->id]);
        $svc->addLink($c, 'asset', $asset->id, 'Core', $this->fin);

        Artisan::call('fin:contracts-sweep');
        Artisan::call('fin:contracts-sweep');

        $new = Contract::where('renewed_from_id', $c->id)->sole();
        $this->assertSame('renewed', $c->refresh()->status);
        $this->assertSame($new->id, $c->renewed_to_id);
        $this->assertSame('13200000.00', (string) $new->contract_value, 'the 10% escalation is applied to the new term');
        $this->assertSame('2027-03-01', $new->commenced_on->toDateString());
        $this->assertSame('2028-02-29', $new->ends_on->toDateString());
        $this->assertSame('active', $new->status);
        $this->assertSame([$this->owner2->id], $new->team()->pluck('user_id')->all());
        $this->assertSame(1, $new->links()->count());
        $r = Renewal::where('contract_id', $c->id)->sole();
        $this->assertTrue($r->is_auto);
        $this->assertSame('executed', $r->status);
        $this->assertSame($new->id, $r->new_contract_id);
        Event::assertDispatchedTimes(ContractRenewed::class, 1);
        Event::assertDispatched(ContractRenewed::class, fn ($e) => $e->auto && $e->successor->id === $new->id);

        Carbon::setTestNow('2028-03-02 09:00:00'); // the successor rolls again a year on: still one at a time
        Artisan::call('fin:contracts-sweep');
        $this->assertSame(1, Contract::where('renewed_from_id', $new->id)->count());
    }

    /** FIN-06.05, FIN-07.01: recommend, submit, approve through the configured stages (CFO only when needed), execute into a linked successor. */
    public function test_approved_renewal_executes_successor_linked_both_ways(): void
    {
        Event::fake([ContractRenewed::class]);
        $this->configureRenewalStages();
        $line = $this->baselined()->lines()->firstOrFail();
        $svc = app(RenewalService::class);
        $contracts = app(ContractService::class);
        $asset = Asset::factory()->create(['organization_id' => $this->org->id]);

        $c = $this->contract(['commenced_on' => '2026-05-21', 'ends_on' => '2027-05-20', 'budget_line_id' => $line->id]);
        $contracts->addTeamMember($c, $this->owner2->id, 'project_lead', $this->fin);
        $contracts->addLink($c, 'asset', $asset->id, null, $this->fin);
        $this->assertSame('in_renewal_window', $c->status);

        $r = $this->submittedRenewal($c, '13200000', 'renew_renegotiated');
        $this->assertSame('10.00', (string) $r->uplift_pct);
        $this->assertSame('yes', $r->requires_cfo);
        $this->assertStringContainsString('awaiting', strtolower($svc->approve($r, $this->head)));
        $this->assertSame('submitted', $r->refresh()->status, 'the CFO stage is still open');
        $svc->approve($r, $this->cfo);
        $this->assertSame('approved', $r->refresh()->status);
        $this->assertSame('renew_renegotiated', $c->refresh()->decision);
        $this->assertSame($this->cfo->id, $c->decision_by_id);

        $new = $svc->execute($r, $this->procurement);
        $this->assertSame('renewed', $c->refresh()->status);
        $this->assertSame($new->id, $c->renewed_to_id);
        $this->assertSame($c->id, $new->renewed_from_id);
        $this->assertSame($new->id, $r->refresh()->new_contract_id);
        $this->assertSame('executed', $r->status);
        $this->assertSame('2027-05-21', $new->commenced_on->toDateString());
        $this->assertSame('2028-05-20', $new->ends_on->toDateString());
        $this->assertSame('13200000.00', (string) $new->contract_value);
        $this->assertSame($c->cost_centre_id, $new->cost_centre_id);
        $this->assertSame($line->id, $new->budget_line_id);
        $this->assertSame([$this->owner2->id], $new->team()->pluck('user_id')->all());
        $this->assertSame([$asset->id], $new->links()->pluck('linkable_id')->all());
        Event::assertDispatched(ContractRenewed::class, fn ($e) => ! $e->auto && $e->successor->id === $new->id && $e->contract->budget_line_id === $line->id && $e->renewal->id === $r->id);

        // a renewal that does not need the CFO stops after the Head of IT
        $small = $this->contract(['title' => 'Small', 'contract_value' => '4000000']);
        $s = $this->submittedRenewal($small, '4100000');
        $this->assertSame('no', $s->requires_cfo);
        $svc->approve($s, $this->head);
        $this->assertSame('approved', $s->refresh()->status);
        $this->assertSame($this->head->id, $s->approved_by_id);
        $ctx = app(FinanceApprovals::class)->trail(RenewalService::PROCESS, $r, $this->cfo)['context'];
        $this->assertSame('yes', $ctx['conditions']['requires_cfo']);
        $this->assertSame('13200000.00', $ctx['base_amount']);
    }

    /** FIN-06.05: the replace and terminate outcomes, and early termination withdrawing an open renewal. */
    public function test_replace_and_terminate_outcomes(): void
    {
        Event::fake([ContractRenewed::class]);
        $svc = app(RenewalService::class);
        $run = function (Contract $c, string $rec) use ($svc) {
            $r = $svc->recommend($svc->open($c, $this->procurement), ['recommendation' => $rec, 'proposed_term_months' => 6, 'proposed_value' => '500000', 'proposed_currency' => 'NGN', 'justification' => 'Because'], $this->procurement);
            $svc->submit($r, $this->procurement);
            $svc->approve($r, $this->cfo);

            return $svc->execute($r->refresh(), $this->procurement, ['terminated_on' => '2027-02-20']);
        };

        $a = $this->contract(['title' => 'Old tool']);
        $new = $run($a, 'replace');
        $this->assertSame('replaced', $a->refresh()->status);
        $this->assertSame($new->id, $a->renewed_to_id);
        $this->assertSame('draft', $new->status);
        $this->assertStringStartsWith('Replacement for', $new->title);

        $b = $this->contract(['title' => 'Legacy gateway']);
        $this->assertNull($run($b, 'terminate'));
        $this->assertSame('terminated', $b->refresh()->status);
        $this->assertSame('2027-02-20', $b->terminated_on->toDateString());
        Event::assertDispatchedTimes(ContractRenewed::class, 1); // the replacement only; terminate dispatches nothing

        $c = $this->contract(['title' => 'Cloud commitment']);
        $open = $svc->open($c, $this->procurement);
        $this->terminateViaWorkflow($c);
        $this->assertSame('terminated', $c->refresh()->status);
        $this->assertSame('withdrawn', $open->refresh()->status);
        $this->refuses(fn () => app(TerminationService::class)->request($c, ['reason' => 'again'], $this->cfo), 'workflow');
        $this->refuses(fn () => app(ContractService::class)->update($c, ['title' => 'x'], $this->procurement), 'workflow', 'read-only');
    }

    /** FIN-07.01: the contract owner and the requester cannot approve, and nobody decides two stages. */
    public function test_renewal_sod_owner_initiator_and_nobody_decides_two_stages(): void
    {
        $this->configureRenewalStages();
        $svc = app(RenewalService::class);
        $this->owner->givePermissionTo('approve it-finance-contracts');
        $this->procurement->givePermissionTo('approve it-finance-contracts');
        $both = $this->userWith('Finance Approver', ['job_title' => 'Head of IT and Chief Financial Officer']);

        $r = $this->submittedRenewal($this->contract(), '20000000');
        $this->refuses(fn () => $svc->approve($r, $this->owner), 'approval');                  // the owner may not decide
        $this->refuses(fn () => $svc->approve($r, $this->procurement), 'approval');            // the requester may not decide
        $this->refuses(fn () => $svc->approve($r, $this->stranger), 'approval', 'permission'); // no permission at all
        $this->refuses(fn () => $svc->reject($r, $this->owner, 'no'), 'approval');

        $svc->approve($r, $both);
        $this->refuses(fn () => $svc->approve($r->refresh(), $both), 'approval', 'already decided'); // fits both stages, may decide only one
        $svc->approve($r, $this->cfo);
        $this->assertSame('approved', $r->refresh()->status);
        $this->assertSame(2, ApprovalRequest::withoutGlobalScopes()->where('subject_id', $r->id)->sole()->steps()->count());
    }

    /** FIN-07.01: with no stages configured the default is one approver holding the permission, who is neither requester nor owner. */
    public function test_unconfigured_default_needs_contracts_approver(): void
    {
        $svc = app(RenewalService::class);
        $this->owner->givePermissionTo('approve it-finance-contracts');
        $r = $this->submittedRenewal($this->contract());

        $this->refuses(fn () => $svc->approve($r, $this->stranger), 'approval', 'permission');
        $this->refuses(fn () => $svc->approve($r, $this->owner), 'approval', 'own this record');
        $this->refuses(fn () => $svc->approve($r, $this->procurement), 'approval');
        $svc->approve($r, $this->cio);
        $this->assertSame('approved', $r->refresh()->status);
        $this->assertSame($this->cio->id, $r->approved_by_id);

        // a configured workflow whose stage conditions all fail leaves no step: the default rule still protects the record
        ApprovalRequest::query()->delete();
        ApprovalWorkflow::withoutGlobalScopes()->where('process', RenewalService::PROCESS)->delete();
        $wf = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'process' => RenewalService::PROCESS, 'is_active' => true]);
        $wf->stages()->create(['organization_id' => $this->org->id, 'sequence' => 10, 'name' => 'CFO only if big', 'approver_type' => 'job_title', 'approver_ref' => 'Chief Financial Officer',
            'sla_hours' => 72, 'min_approvals' => 1, 'is_active' => true, 'condition_field' => 'requires_cfo', 'condition_values' => ['yes']]);
        $z = $this->submittedRenewal($this->contract(['title' => 'Tiny', 'contract_value' => '1000000']), '1000000');
        $this->refuses(fn () => $svc->approve($z, $this->owner), 'approval');
        $this->refuses(fn () => $svc->approve($z, $this->procurement), 'approval');
        $svc->approve($z, $this->cfo);
        $this->assertSame('approved', $z->refresh()->status);
    }

    /** One active renewal per contract; withdrawing frees the slot; only the requester or the owner can withdraw. */
    public function test_only_one_active_renewal_per_contract(): void
    {
        $svc = app(RenewalService::class);
        $c = $this->contract();
        $r = $svc->open($c, $this->procurement);
        $this->refuses(fn () => $svc->open($c, $this->procurement), 'workflow', 'already');
        $this->refuses(fn () => $svc->submit($r, $this->procurement), 'workflow', 'recommendation');

        $svc->recommend($r, ['recommendation' => 'renew_as_is', 'proposed_term_months' => 12, 'proposed_value' => '1', 'proposed_currency' => 'NGN', 'justification' => 'x'], $this->procurement);
        $svc->submit($r, $this->procurement);
        $this->refuses(fn () => $svc->withdraw($r, $this->stranger), 'workflow', 'requester or the contract owner');
        $svc->withdraw($r, $this->procurement);
        $this->assertSame('withdrawn', $r->refresh()->status);
        $this->assertSame('REN-0002', $svc->open($c, $this->procurement)->renewal_code);

        $draft = app(ContractService::class)->create(['title' => 'Draft', 'type' => 'other', 'commenced_on' => '2027-01-01', 'ends_on' => '2027-12-31'], $this->procurement);
        $this->refuses(fn () => $svc->open($draft, $this->procurement), 'workflow', 'cannot be renewed');
    }

    /** FIN-06.08: documents land in the evidence vault with the SHA-256 of the file; bad files are refused; the download streams it. */
    public function test_documents_store_evidence_with_hash(): void
    {
        Storage::fake('local');
        $c = $this->contract();
        $bytes = "%PDF-1.4\nsigned master agreement";
        $this->actingAs($this->procurement)->post(route('finance.contracts.documents.store', $c), [
            'kind' => 'contract', 'title' => 'Signed MSA', 'signed_on' => '2027-01-01', 'file' => UploadedFile::fake()->createWithContent('msa.pdf', $bytes),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $doc = $c->documents()->sole();
        $this->assertSame(hash('sha256', $bytes), $doc->sha256);
        $evidence = Evidence::withoutGlobalScopes()->findOrFail($doc->evidence_id);
        $this->assertSame(Contract::class, $evidence->evidenceable_type);
        $this->assertSame($c->id, (int) $evidence->evidenceable_id);
        $this->assertSame('approved', $evidence->status);
        Storage::disk('local')->assertExists($evidence->file_path);
        $this->assertStringStartsWith("evidence/{$this->org->id}/contracts/", $evidence->file_path);
        $this->actingAs($this->procurement)->get(route('finance.contracts.documents.download', [$c, $doc]))->assertOk();
        $this->assertTrue(ContractEvent::where('contract_id', $c->id)->where('type', 'document_added')->where('payload->sha256', $doc->sha256)->exists());

        $this->actingAs($this->procurement)->post(route('finance.contracts.documents.store', $c), ['kind' => 'contract', 'title' => 'Bad', 'file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
        $this->actingAs($this->procurement)->post(route('finance.contracts.documents.store', $c), ['kind' => 'nope', 'title' => 'Bad kind'])->assertSessionHasErrors('kind');
    }

    /** FIN-06.07: the licence position flags over-, under- and fully-used entitlements from the manual deployed quantity. */
    public function test_licence_position_flags(): void
    {
        $pos = fn (?string $entitled, ?string $deployed) => $this->contract(['licence_metric' => 'user', 'licence_quantity' => $entitled, 'deployed_quantity' => $deployed])->licence_position;

        $this->assertSame('over', $pos('100', '120'));
        $this->assertSame('ok', $pos('100', '90'));
        $this->assertSame('ok', $pos('100', '100'));
        $this->assertSame('under', $pos('100', '50'));
        $this->assertNull($pos('100', null));
        $this->assertNull($this->contract(['title' => 'No licence data'])->licence_position);
    }

    /** FIN-07.10: without `view it-finance-amounts` every amount leaves the server as null, on the renewal pages too. */
    public function test_amounts_masked_without_the_amounts_permission(): void
    {
        $viewer = $this->userWith('Viewer');
        $c = $this->contract(['owner_id' => $viewer->id, 'currency' => 'USD', 'contract_value' => '5000', 'price_escalation_pct' => '3']);
        $r = $this->submittedRenewal($c, '5200');

        $this->actingAs($viewer)->get(route('finance.contracts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('contracts.0.contract_value', null)->where('contracts.0.base_contract_value', null)->where('contracts.0.amount_band', null)->where('can.viewAmounts', false));
        $this->actingAs($viewer)->get(route('finance.renewals.show', $r))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('renewal.proposed_value', null)->where('renewal.proposed_base_value', null)->where('renewal.amount_band', null)->where('contract.next_term_forecast', null)
            ->where('contract.fx_rate', null)->where('contract.contract_value', null));
        $this->actingAs($viewer)->get(route('finance.renewals.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('renewals.0.proposed_value', null)->where('renewals.0.contract.base_contract_value', null));

        $this->actingAs($this->procurement)->get(route('finance.renewals.show', $r))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('renewal.proposed_value', '5200.00')->where('can.viewAmounts', true));
    }

    /** C70: a budget owner sees their cost centre, a team member sees the contracts they are on, Procurement sees all. */
    public function test_visibility_budget_owner_team_member_and_procurement(): void
    {
        $mine = $this->contract(['title' => 'In owner cost centre', 'owner_id' => $this->fin->id]);
        $theirs = $this->contract(['title' => 'Security tool', 'cost_centre_id' => $this->cc2->id, 'owner_id' => $this->fin->id]);
        app(ContractService::class)->addTeamMember($theirs, $this->owner->id, 'member', $this->fin);
        $hidden = $this->contract(['title' => 'Hidden', 'cost_centre_id' => $this->cc2->id, 'owner_id' => $this->fin->id]);
        $r = $this->submittedRenewal($hidden);

        $titles = fn (User $u) => collect($this->actingAs($u)->get(route('finance.contracts.index'))->assertOk()->viewData('page')['props']['contracts'])->pluck('title')->sort()->values()->all();
        $this->assertSame(['In owner cost centre', 'Security tool'], $titles($this->owner), 'own cost centre plus the team membership');
        $this->assertSame(['Hidden', 'Security tool'], $titles($this->owner2));
        $this->assertCount(3, $titles($this->procurement));
        $this->assertCount(3, $titles($this->fin));

        $this->actingAs($this->owner)->get(route('finance.contracts.show', $hidden))->assertForbidden();
        $this->actingAs($this->owner)->get(route('finance.renewals.show', $r))->assertForbidden();
        $this->actingAs($this->owner)->get(route('finance.contracts.show', $theirs))->assertOk();
        $this->actingAs($this->procurement)->get(route('finance.renewals.show', $r))->assertOk();
        $this->actingAs($this->owner2)->get(route('finance.renewals.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('renewals', 1));
        $this->actingAs($this->owner)->get(route('finance.renewals.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('renewals', 0));
        $this->actingAs($this->owner)->get(route('finance.contracts.show', $mine))->assertOk();
    }

    /** Tenancy: another bank sees a 404 for a contract, a renewal and a document, and an empty register. */
    public function test_cross_bank_is_404(): void
    {
        Storage::fake('local');
        $c = $this->contract();
        $r = $this->submittedRenewal($c);
        $doc = app(ContractService::class)->addDocument($c, ['kind' => 'other', 'title' => 'Memo'], UploadedFile::fake()->createWithContent('memo.pdf', '%PDF-memo'), $this->procurement);

        $other = $this->otherBank();
        $spy = $this->userWith('IT Finance Controller', [], $other->id);
        $this->actingAs($spy)->get(route('finance.contracts.show', $c))->assertNotFound();
        $this->actingAs($spy)->get(route('finance.renewals.show', $r))->assertNotFound();
        $this->actingAs($spy)->get(route('finance.contracts.documents.download', [$c, $doc]))->assertNotFound();
        $this->actingAs($spy)->post(route('finance.renewals.approve', $r))->assertNotFound();
        $this->actingAs($spy)->get(route('finance.contracts.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('contracts', 0));
    }

    /** FIN-07.06: create, update, activate, approve and execute are all in the it-finance activity log. */
    public function test_lifecycle_is_activity_logged(): void
    {
        $svc = app(RenewalService::class);
        $c = $this->contract();
        app(ContractService::class)->update($c, ['title' => 'Finacle AMC (renamed)'], $this->procurement);
        $r = $this->submittedRenewal($c);
        $svc->approve($r, $this->cfo);
        $svc->execute($r->refresh(), $this->procurement);

        $events = fn (string $class, int $id) => Activity::where('log_name', 'it-finance')->where('subject_type', $class)->where('subject_id', $id)->pluck('event')->all();
        $contractEvents = $events(Contract::class, $c->id);
        foreach (['created', 'updated', 'activated', 'renewed'] as $e) {
            $this->assertContains($e, $contractEvents, "contract {$e}");
        }
        $renewalEvents = $events(Renewal::class, $r->id);
        foreach (['created', 'opened', 'recommended', 'submitted', 'approved', 'executed'] as $e) {
            $this->assertContains($e, $renewalEvents, "renewal {$e}");
        }
        $this->assertSame($this->cfo->id, (int) Activity::where('log_name', 'it-finance')->where('subject_type', Renewal::class)->where('event', 'approved')->value('causer_id'));
    }
}

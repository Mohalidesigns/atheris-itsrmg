<?php

namespace Tests\Feature\Kri;

use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\Issue;
use App\Models\Kri;
use App\Models\KriBreach;
use App\Models\KriReading;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vulnerability;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * KRIs & Dashboards (ATH-VAL-014): tenant isolation of KRIs and board packs; reading status from the
 * thresholds in the KRI's direction; breaches opened, escalated and resolved by readings, with an
 * issue on red; platform KRIs measured from the bank's records; board packs as snapshots of the
 * bank's own figures approved by someone other than the preparer; executive report counts.
 */
class KriDashboardsModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin');
        $this->owner = $this->user('Risk Manager');
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function kri(array $attrs = [], ?Organization $org = null): Kri
    {
        return Kri::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id, 'code' => 'KRI-'.fake()->unique()->numerify('###'), 'name' => 'Overdue critical patches',
            'category' => 'vulnerability', 'threshold_green' => 0, 'threshold_amber' => 5, 'threshold_red' => 20, 'direction' => 'higher_worse',
            'unit' => 'count', 'frequency' => 'weekly', 'source' => 'manual', 'owner_id' => $this->owner->id,
        ]);
    }

    private function template(): BoardPackTemplate
    {
        return BoardPackTemplate::create(['name' => 'BAC Pack — Monthly', 'sections' => ['exec_summary', 'top_10_risks', 'kri_panel', 'risk_appetite', 'control_status', 'cbn_return_readiness']]);
    }

    public function test_kris_and_board_packs_are_isolated_per_bank(): void
    {
        $mine = $this->kri();
        $theirs = $this->kri(['owner_id' => null], $this->other);
        $theirRun = BoardPackRun::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'template_id' => $this->template()->id, 'period' => '2026-09', 'status' => 'draft']);

        $this->actingAs($this->admin)->get(route('kri.index'))->assertInertia(fn (Assert $p) => $p->has('kris', 1)->where('kris.0.id', $mine->id));
        $this->get(route('kri.show', $theirs))->assertNotFound();
        $this->post(route('kri.readings.store', $theirs), ['value' => 1])->assertNotFound();
        $this->get(route('board-packs.show', $theirRun))->assertNotFound();
        $this->get(route('board-packs.index'))->assertInertia(fn (Assert $p) => $p->has('runs', 0));
    }

    public function test_readings_drive_status_breaches_and_issues(): void
    {
        $risk = Risk::factory()->create(['organization_id' => $this->org->id]);
        $kri = $this->kri(['risk_id' => $risk->id]);
        $go = fn (array $data) => $this->actingAs($this->owner)->post(route('kri.readings.store', $kri), $data);

        $go(['value' => 3])->assertSessionHasErrors('commentary');
        $go(['value' => 3, 'commentary' => 'Two Oracle CPU patches slipped.', 'recorded_at' => now()->subWeeks(2)->toDateString()])->assertRedirect();
        $breach = KriBreach::withoutGlobalScopes()->sole();
        $this->assertSame(['amber', null], [$breach->level, $breach->issue_id]);

        // Red escalates the same breach and raises one issue for the owner.
        $go(['value' => 9, 'commentary' => 'Firewall CVE backlog.', 'recorded_at' => now()->subWeek()->toDateString()])->assertRedirect();
        $go(['value' => 12, 'commentary' => 'Still red, same week.', 'recorded_at' => now()->subWeek()->toDateString()])->assertRedirect();
        $this->assertSame(2, KriReading::withoutGlobalScopes()->count()); // one reading per period
        $breach->refresh();
        $issue = Issue::withoutGlobalScopes()->where('source_type', 'kri')->sole();
        $this->assertSame(['red', $issue->id, $this->owner->id], [$breach->level, (int) $breach->issue_id, (int) $issue->owner_id]);
        $this->assertSame(1, KriBreach::withoutGlobalScopes()->count());

        $this->post(route('kri.breaches.acknowledge', $breach), ['action_plan' => 'Emergency patch window agreed with the CAB for Saturday.'])->assertRedirect();
        $this->assertSame($this->owner->id, (int) $breach->fresh()->acknowledged_by);

        // Green resolves the breach and notes it on the issue, which stays open for verification.
        $go(['value' => 0])->assertRedirect();
        $this->assertNotNull($breach->fresh()->resolved_at);
        $this->assertTrue($issue->fresh()->isOpen());
        $this->assertTrue($issue->events()->where('description', 'like', '%green again%')->exists());

        // A KRI where lower is worse reads the thresholds the other way.
        $mfa = $this->kri(['code' => 'KRI-IT-MFA', 'unit' => '%', 'direction' => 'lower_worse', 'threshold_green' => 100, 'threshold_amber' => 95, 'threshold_red' => 80]);
        $this->post(route('kri.readings.store', $mfa), ['value' => 97, 'commentary' => 'Two break-glass accounts pending token issue.'])->assertRedirect();
        $this->assertSame('amber', $mfa->fresh()->latestReading->status);
        $this->actingAs($this->user('Viewer'))->post(route('kri.readings.store', $mfa), ['value' => 100])->assertForbidden();
    }

    public function test_platform_kris_are_measured_from_the_banks_records(): void
    {
        $kri = $this->kri(['code' => 'KRI-IT-PATCH', 'source' => 'platform']);
        Vulnerability::factory()->count(2)->create(['organization_id' => $this->org->id, 'severity' => 'critical', 'status' => 'open', 'due_date' => now()->subWeek()]);
        Vulnerability::factory()->create(['organization_id' => $this->org->id, 'severity' => 'critical', 'status' => 'false_positive', 'due_date' => now()->subWeek()]);
        Vulnerability::factory()->count(3)->create(['organization_id' => $this->other->id, 'severity' => 'critical', 'status' => 'open', 'due_date' => now()->subWeek()]);

        $this->actingAs($this->owner)->post(route('kri.readings.store', $kri), ['value' => 0])->assertSessionHasErrors('value');
        $this->post(route('kri.measure', $kri))->assertRedirect();
        $reading = KriReading::withoutGlobalScopes()->sole();
        // False positives and the other bank are excluded; the reading records who triggered it.
        $this->assertSame([2.0, 'amber', $this->owner->id], [$reading->value, $reading->status, (int) $reading->recorded_by]);

        $manual = $this->kri();
        $this->post(route('kri.measure', $manual))->assertSessionHasErrors('kri');

        Policy::factory()->create(['organization_id' => $this->org->id, 'status' => 'active', 'review_date' => now()->subMonth()->toDateString()]);
        $exp = $this->kri(['code' => 'KRI-POL-EXP', 'source' => 'platform', 'threshold_amber' => 3]);
        $this->post(route('kri.measure', $exp))->assertRedirect();
        $this->assertSame([1.0, 'amber'], [$exp->fresh()->latestReading->value, $exp->fresh()->latestReading->status]);
    }

    public function test_kri_definitions_validate_thresholds_and_pickers(): void
    {
        $kri = $this->kri();
        $base = ['code' => $kri->code, 'name' => $kri->name, 'category' => 'vulnerability', 'unit' => 'count', 'direction' => 'higher_worse',
            'threshold_green' => 0, 'threshold_amber' => 5, 'threshold_red' => 20, 'frequency' => 'monthly'];

        $this->actingAs($this->admin)->put(route('kri.update', $kri), ['threshold_amber' => 30] + $base)->assertSessionHasErrors('threshold_amber');
        $this->put(route('kri.update', $kri), ['owner_id' => $this->user('Viewer', $this->other)->id] + $base)->assertSessionHasErrors('owner_id');
        $this->put(route('kri.update', $kri), ['risk_id' => Risk::factory()->create(['organization_id' => $this->other->id])->id] + $base)->assertSessionHasErrors('risk_id');
        $this->put(route('kri.update', $kri), $base)->assertRedirect();
        $this->assertSame('monthly', $kri->fresh()->frequency);

        $this->post(route('kri.store'), ['code' => 'KRI-NEW-1', 'direction' => 'lower_worse', 'threshold_green' => 99, 'threshold_amber' => 98, 'threshold_red' => 95] + $base)->assertRedirect();
        $this->post(route('kri.store'), ['code' => 'KRI-NEW-1'] + $base)->assertSessionHasErrors('code');
    }

    public function test_board_packs_snapshot_the_banks_figures_and_need_a_second_approver(): void
    {
        $template = $this->template();
        Risk::factory()->create(['organization_id' => $this->org->id, 'risk_id_code' => 'KHB-RSK-001', 'status' => 'identified', 'inherent_score' => 20]);
        Risk::factory()->create(['organization_id' => $this->other->id, 'risk_id_code' => 'FBN-RSK-001', 'status' => 'identified', 'inherent_score' => 25]);
        $this->kri();

        $this->actingAs($this->user('Viewer'))->post(route('board-packs.generate'), ['template_id' => $template->id])->assertForbidden();
        $this->actingAs($this->owner)->post(route('board-packs.generate'), ['template_id' => $template->id, 'period' => '2026-09'])->assertRedirect();

        $run = BoardPackRun::withoutGlobalScopes()->sole();
        $sections = collect($run->content)->keyBy('key');
        $this->assertSame([$this->org->id, 'draft', $this->owner->id, '2026-09'], [(int) $run->organization_id, $run->status, (int) $run->prepared_by, $run->period]);
        $this->assertSame(['KHB-RSK-001'], collect($sections['top_10_risks']['data'])->pluck('code')->all());
        $this->assertSame(1, $sections['kri_panel']['data']['summary']['total']);

        // The preparer cannot approve; another approver can.
        $this->actingAs($this->owner)->post(route('board-packs.approve', $run))->assertForbidden(); // Risk Manager lacks approve reports
        $preparerAdmin = $this->admin;
        $this->actingAs($preparerAdmin)->post(route('dashboard.board-pack-export'))->assertRedirect();
        $own = BoardPackRun::withoutGlobalScopes()->where('prepared_by', $preparerAdmin->id)->sole();
        $this->post(route('board-packs.approve', $own))->assertSessionHasErrors('pack');
        $this->post(route('board-packs.approve', $run), ['notes' => 'Approved for the BAC.'])->assertRedirect();
        $this->assertSame(['approved', $preparerAdmin->id], [$run->fresh()->status, (int) $run->fresh()->approved_by]);
        $this->post(route('board-packs.approve', $run))->assertSessionHasErrors('pack');
    }

    public function test_executive_report_counts_open_work_and_shows_kris(): void
    {
        Vulnerability::factory()->create(['organization_id' => $this->org->id, 'severity' => 'critical', 'status' => 'open']);
        Vulnerability::factory()->create(['organization_id' => $this->org->id, 'severity' => 'critical', 'status' => 'false_positive']);
        Risk::factory()->create(['organization_id' => $this->org->id, 'status' => 'closed']);
        Risk::factory()->create(['organization_id' => $this->org->id, 'status' => 'identified']);
        $this->kri();

        $this->actingAs($this->admin)->get(route('reports.executive'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('securityStats.open_vulnerabilities', 1)->where('securityStats.critical_vulnerabilities', 1)
            ->where('riskStats.total', 1)->has('heatMapData.inherent')->has('heatMapData.residual')->where('kriSummary.total', 1));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->has('kris', 1));
    }
}

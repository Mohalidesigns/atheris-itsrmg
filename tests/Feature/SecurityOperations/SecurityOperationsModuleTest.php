<?php

namespace Tests\Feature\SecurityOperations;

use App\Models\Asset;
use App\Models\DataBreach;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\SecurityAlert;
use App\Models\SiemIntegration;
use App\Models\SiemSignal;
use App\Models\ThreatAdvisory;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\VulnerabilityTicket;
use App\Services\SecurityOps\SiemIngestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Security Operations (ATH-VAL-005): tenant isolation of SIEM data and regulatory notifications,
 * the EPSS/KEV prioritiser, "Am I affected?", signed SIEM ingest, the notification lifecycle with
 * segregation of duties, incident lifecycle rules, the NDPA breach clock, tenant-scoped pickers,
 * and the dashboard feeds.
 */
class SecurityOperationsModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin');
        $this->analyst = $this->user('Security Analyst');

        NotificationTemplate::create([
            'regulator_code' => 'CBN', 'template_key' => 'cbn_24h', 'title' => 'CBN 24h',
            'body_template' => 'Report for {{bank}}: {{incident.code}} — {{incident.title}} ({{incident.severity}})',
        ]);
        NotificationTemplate::create([
            'regulator_code' => 'NDPC', 'template_key' => 'ndpc_72h', 'title' => 'NDPC 72h',
            'body_template' => 'Breach notice for {{incident.code}}, records {{breach.records}}',
        ]);
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function incident(array $attrs = [], ?Organization $org = null): Incident
    {
        $org ??= $this->org;
        $owner = User::factory()->create(['organization_id' => $org->id]);

        return Incident::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $org->id,
            'incident_id_code' => 'INC-'.fake()->unique()->numerify('####'),
            'title' => 'Card data in application logs',
            'type' => 'data_breach',
            'severity' => 'high',
            'status' => 'detected',
            'detected_at' => now()->subHours(5),
            'assigned_to' => $owner->id,
        ]);
    }

    private function vuln(array $attrs = [], ?Organization $org = null): Vulnerability
    {
        return Vulnerability::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id,
            'vuln_id_code' => 'VLN-'.fake()->unique()->numerify('####'),
            'title' => 'Finding',
            'severity' => 'high',
            'cvss_score' => 7.5,
            'status' => 'open',
            'discovered_at' => now()->subDays(3),
            'due_date' => now()->addDays(11),
        ]);
    }

    public function test_siem_connectors_and_signals_are_tenant_scoped(): void
    {
        SiemIntegration::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'provider' => 'sentinel', 'status' => 'active']);
        SiemIntegration::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'provider' => 'splunk_es', 'status' => 'active']);
        SiemSignal::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'provider' => 'sentinel', 'external_id' => 'S-1', 'severity' => 'high', 'title' => 'Mine', 'received_at' => now()]);
        $theirs = SiemSignal::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'provider' => 'splunk_es', 'external_id' => 'S-2', 'severity' => 'high', 'title' => 'Theirs', 'received_at' => now()]);

        $this->actingAs($this->admin)->get(route('siem.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Siem/Index')
                ->has('integrations', 1)->where('integrations.0.provider', 'sentinel')
                ->has('signals', 1)->where('signals.0.title', 'Mine'));

        $this->actingAs($this->admin)->post(route('siem.signals.alert', $theirs->id))->assertNotFound();
    }

    public function test_signed_siem_webhook_ingests_redacts_alerts_and_rejects_forgeries(): void
    {
        $connector = SiemIntegration::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'provider' => 'sentinel', 'status' => 'degraded', 'webhook_secret' => 'top-secret-key',
        ]);
        $body = json_encode(['external_id' => 'SEN-1', 'title' => 'LSASS access on DC01', 'severity' => 'critical', 'customer_phone' => '08031234567', 'host' => 'DC01']);
        $ts = (string) time();
        $headers = fn ($sig, $t = null) => ['X-Atheris-Timestamp' => $t ?? $ts, 'X-Atheris-Signature' => $sig, 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', route('siem.ingest', $connector->id), [], [], [], $this->server($headers('sha256=forged')), $body)->assertStatus(401);
        $stale = (string) (time() - 3600);
        $this->call('POST', route('siem.ingest', $connector->id), [], [], [], $this->server($headers(SiemIngestService::sign('top-secret-key', $stale, $body), $stale)), $body)->assertStatus(401);

        $good = $this->server($headers(SiemIngestService::sign('top-secret-key', $ts, $body)));
        $this->call('POST', route('siem.ingest', $connector->id), [], [], [], $good, $body)->assertCreated()->assertJsonPath('duplicate', false);
        $this->call('POST', route('siem.ingest', $connector->id), [], [], [], $good, $body)->assertOk()->assertJsonPath('duplicate', true);

        $signal = SiemSignal::withoutGlobalScopes()->where('external_id', 'SEN-1')->sole();
        $this->assertSame($this->org->id, $signal->organization_id);
        $this->assertStringNotContainsString('08031234567', json_encode($signal->payload));
        $this->assertSame('DC01', $signal->payload['host']);
        $alert = SecurityAlert::withoutGlobalScopes()->findOrFail($signal->security_alert_id);
        $this->assertSame($this->org->id, $alert->organization_id);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('active', $connector->fresh()->status);
    }

    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server[$k === 'CONTENT_TYPE' ? $k : 'HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $server;
    }

    public function test_prioritiser_uses_epss_kev_and_asset_criticality_deterministically(): void
    {
        DB::table('epss_cache')->insert(['cve_id' => 'CVE-2024-3400', 'epss_score' => 0.95, 'percentile' => 0.99, 'updated_at' => now()]);
        DB::table('kev_cache')->insert(['cve_id' => 'CVE-2024-3400', 'date_added' => '2024-04-12', 'known_ransomware_use' => true, 'updated_at' => now()]);
        $asset = Asset::factory()->create(['organization_id' => $this->org->id, 'criticality' => 'critical']);
        $kev = $this->vuln(['cve_id' => 'CVE-2024-3400', 'severity' => 'critical', 'cvss_score' => 10, 'due_date' => now()->subDay()]);
        $kev->assets()->attach($asset->id);
        $this->vuln(['cve_id' => 'CVE-2023-5678', 'severity' => 'medium', 'cvss_score' => 5.3]);
        $this->vuln(['cve_id' => 'CVE-2024-3400'], $this->other);

        $first = null;
        foreach ([1, 2] as $_) {
            $this->actingAs($this->analyst)->get(route('vuln-prioritiser.index'))
                ->assertInertia(function (Assert $p) use (&$first) {
                    $p->component('Vuln/Prioritiser')->has('rows', 2)
                        ->where('rows.0.cve_id', 'CVE-2024-3400')
                        ->where('rows.0.kev', true)
                        ->where('rows.0.band', 'P1')
                        ->where('rows.0.score', 98.8) // 40 + 0.95×25 + (20 + 5 ransomware) + 10 critical asset
                        ->where('rows.0.sla_breached', true)
                        ->where('stats.kev', 1);
                    $first ??= $p->toArray()['props']['rows'];
                    $this->assertSame($first, $p->toArray()['props']['rows']);
                });
        }
    }

    public function test_am_i_affected_matches_only_this_banks_findings(): void
    {
        ThreatAdvisory::create(['source' => 'ngcert', 'advisory_id' => 'ADV-1', 'title' => 'Citrix Bleed', 'cves' => ['CVE-2023-4966'], 'published_at' => now()]);
        ThreatAdvisory::create(['source' => 'nitda', 'advisory_id' => 'ADV-2', 'title' => 'regreSSHion', 'cves' => ['CVE-2024-6387'], 'published_at' => now()->subDay()]);
        $this->vuln(['cve_id' => 'CVE-2023-4966']);
        $this->vuln(['cve_id' => 'CVE-2024-6387'], $this->other);

        $this->actingAs($this->admin)->get(route('threat-advisories.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Threats/Advisories')
                ->where('advisories.0.advisory_id', 'ADV-1')->where('advisories.0.affected.open', 1)
                ->where('advisories.1.advisory_id', 'ADV-2')->where('advisories.1.affected.open', 0)
                ->has('advisories.1.affected.findings', 0));
    }

    public function test_notifications_are_tenant_scoped_and_drafted_from_the_incident(): void
    {
        $theirs = $this->incident([], $this->other);
        IncidentNotification::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'incident_id' => $theirs->id, 'template_key' => 'cbn_24h', 'delivery_status' => 'draft']);

        $this->actingAs($this->analyst)->get(route('notifications.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Notifications/Index')->has('notifications', 0));
        $this->actingAs($this->analyst)->post(route('notifications.draft'), ['incident_id' => $theirs->id, 'template_key' => 'cbn_24h'])
            ->assertSessionHasErrors('incident_id');

        $mine = $this->incident(['detected_at' => now()->subHours(5)]);
        $this->actingAs($this->analyst)->post(route('notifications.draft'), ['incident_id' => $mine->id, 'template_key' => 'cbn_24h'])
            ->assertRedirect();
        $n = IncidentNotification::withoutGlobalScopes()->where('incident_id', $mine->id)->sole();

        $this->assertSame($this->org->id, $n->organization_id);
        $this->assertSame('CBN', $n->regulator_code);
        $this->assertStringContainsString($mine->incident_id_code, $n->draft_body);
        $this->assertStringNotContainsString('{{', $n->draft_body);
        // CBN clock: 24h from detection, not from drafting.
        $this->assertEqualsWithDelta($mine->detected_at->copy()->addHours(24)->timestamp, $n->deadline_at->timestamp, 5);
    }

    public function test_notification_lifecycle_enforces_segregation_and_closes_the_ndpa_clock(): void
    {
        $incident = $this->incident();
        $breach = DataBreach::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'incident_id' => $incident->id, 'breach_id_code' => 'BRC-1',
            'title' => 'Card data', 'records_affected' => 1200, 'ndpa_notification_required' => true,
            'discovered_at' => now()->subHours(3), 'ndpa_notification_deadline' => now()->addHours(69),
        ]);
        $this->actingAs($this->admin)->post(route('notifications.draft'), ['incident_id' => $incident->id, 'template_key' => 'ndpc_72h']);
        $n = IncidentNotification::withoutGlobalScopes()->sole();
        $this->assertStringContainsString('1,200', $n->draft_body);

        $go = fn (User $u, string $action, array $extra = []) => $this->actingAs($u)->post(route('notifications.transition', $n->id), ['action' => $action] + $extra);

        $go($this->admin, 'approve')->assertSessionHas('error'); // still a draft
        $go($this->admin, 'submit_review');
        $go($this->admin, 'approve')->assertSessionHas('error', 'The preparer cannot approve their own notification.');
        $go($this->analyst, 'approve');
        $this->assertSame('approved', $n->fresh()->delivery_status);
        $this->assertSame($this->analyst->id, $n->fresh()->approved_by_id);

        $go($this->admin, 'submit')->assertSessionHasErrors('submission_reference');
        $go($this->admin, 'submit', ['submission_reference' => 'NDPC/BR/2026/0001']);
        $this->assertSame('submitted', $n->fresh()->delivery_status);
        $this->assertNotNull($breach->fresh()->ndpa_notified_at);
        $this->assertTrue($incident->events()->where('event_type', 'communication')->exists());

        $this->actingAs($this->admin)->put(route('notifications.update', $n->id), ['draft_body' => 'changed'])->assertSessionHas('error');
    }

    public function test_incident_lifecycle_stamps_times_blocks_regression_and_requires_root_cause(): void
    {
        $incident = $this->incident(['type' => 'denial_of_service', 'root_cause' => null]);
        $patch = fn (string $status) => $this->actingAs($this->analyst)->patch(route('incidents.update-status', $incident->id), ['status' => $status]);

        $patch('containing');
        $incident->refresh();
        $this->assertSame('containing', $incident->status);
        $this->assertNotNull($incident->responded_at);
        $this->assertNotNull($incident->contained_at);

        $patch('triaged')->assertSessionHas('error');
        $patch('resolved');
        $patch('closed')->assertSessionHas('error');
        $this->assertSame('resolved', $incident->fresh()->status);

        $patch('investigating'); // reopen
        $this->assertNull($incident->fresh()->resolved_at);

        // Editing a seeded-style incident with a non-legacy type validates.
        $this->actingAs($this->analyst)->put(route('incidents.update', $incident->id), [
            'title' => $incident->title, 'severity' => 'high', 'type' => 'denial_of_service', 'status' => 'closed',
        ])->assertSessionHasErrors('root_cause');
    }

    public function test_vulnerability_store_scopes_pickers_and_applies_the_sla(): void
    {
        DB::table('kev_cache')->insert(['cve_id' => 'CVE-2024-21762', 'date_added' => '2024-02-09', 'updated_at' => now()]);
        $foreignAsset = Asset::factory()->create(['organization_id' => $this->other->id]);
        $foreignUser = User::factory()->create(['organization_id' => $this->other->id]);

        $this->actingAs($this->admin)->post(route('vulnerabilities.store'), [
            'title' => 'x', 'severity' => 'high', 'asset_ids' => [$foreignAsset->id], 'assigned_to' => $foreignUser->id,
        ])->assertSessionHasErrors(['asset_ids.0', 'assigned_to']);
        $this->actingAs($this->admin)->post(route('vulnerabilities.store'), ['title' => 'x', 'severity' => 'high', 'cve_id' => 'CVE-2024-3400-v12'])
            ->assertSessionHasErrors('cve_id');

        $this->actingAs($this->admin)->post(route('vulnerabilities.store'), [
            'title' => 'FortiOS', 'severity' => 'medium', 'cve_id' => 'CVE-2024-21762', 'discovered_at' => now()->toDateString(),
        ])->assertRedirect();
        // KEV-listed: critical SLA (72h) despite medium severity.
        $this->assertSame(now()->addHours(72)->toDateString(), Vulnerability::withoutGlobalScopes()->where('title', 'FortiOS')->sole()->due_date->toDateString());

        $this->actingAs($this->admin)->get(route('vulnerabilities.index', ['sort' => 'id; drop table users', 'direction' => 'sideways']))->assertOk();
    }

    public function test_tickets_scope_the_finding_inherit_priority_and_need_resolution_notes(): void
    {
        $foreign = $this->vuln([], $this->other);
        $this->actingAs($this->analyst)->post(route('vulnerability-tickets.store'), ['vulnerability_id' => $foreign->id, 'title' => 'x'])
            ->assertSessionHasErrors('vulnerability_id');

        $mine = $this->vuln(['severity' => 'critical']);
        $this->actingAs($this->admin)->post(route('vulnerability-tickets.store'), ['vulnerability_id' => $mine->id, 'title' => 'Patch it']);
        $ticket = VulnerabilityTicket::withoutGlobalScopes()->sole();
        $this->assertSame(1, $ticket->priority);
        $this->assertSame(72, $ticket->sla_hours);
        $this->assertSame('in_progress', $mine->fresh()->status);

        $this->actingAs($this->admin)->patch(route('vulnerability-tickets.update-status', $ticket->id), ['status' => 'resolved'])->assertSessionHas('error');
        $this->actingAs($this->admin)->patch(route('vulnerability-tickets.update-status', $ticket->id), ['status' => 'resolved', 'resolution_notes' => 'Patched'])
            ->assertSessionHas('success');
        $this->assertSame('in_progress', $mine->fresh()->status); // the fix still needs verifying
    }

    public function test_breach_clock_runs_from_discovery_and_records_late_notification(): void
    {
        $foreign = $this->incident([], $this->other);
        $this->actingAs($this->admin)->post(route('data-breaches.store'), ['title' => 'x', 'incident_id' => $foreign->id])
            ->assertSessionHasErrors('incident_id');

        $discovered = now()->subHours(80)->startOfMinute();
        $this->actingAs($this->admin)->post(route('data-breaches.store'), [
            'title' => 'KYC docs exposed', 'ndpa_notification_required' => true, 'discovered_at' => $discovered->toDateTimeString(),
            'data_types_affected' => ['BVN', 'Phone'],
        ])->assertRedirect();
        $breach = DataBreach::withoutGlobalScopes()->where('title', 'KYC docs exposed')->sole();
        $this->assertEquals($discovered->copy()->addHours(72), $breach->ndpa_notification_deadline);

        $this->actingAs($this->admin)->post(route('data-breaches.notified', $breach->id), ['party' => 'regulator'])
            ->assertSessionHas('warning');
        $this->assertSame('notified', $breach->fresh()->status);

        $this->actingAs($this->admin)->get(route('data-breaches.index'))->assertInertia(fn (Assert $p) => $p->component('SecurityOps/Breaches/Index'));
    }

    public function test_alerts_link_to_open_incidents_in_the_same_bank_only(): void
    {
        $alert = SecurityAlert::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'alert_id_code' => 'ALR-1', 'title' => 'Beacon', 'severity' => 'high', 'status' => 'new', 'received_at' => now()]);
        $foreign = $this->incident(['status' => 'investigating'], $this->other);
        $mine = $this->incident(['status' => 'investigating']);

        $this->actingAs($this->analyst)->post(route('security-alerts.link', $alert->id), ['incident_id' => $foreign->id])->assertSessionHasErrors('incident_id');
        $this->actingAs($this->analyst)->post(route('security-alerts.link', $alert->id), ['incident_id' => $mine->id]);
        $this->assertSame($mine->id, $alert->fresh()->incident_id);
        $this->assertSame('investigating', $alert->fresh()->status);
    }

    public function test_dashboards_report_secops_kpis_for_the_signed_in_bank(): void
    {
        DB::table('kev_cache')->insert(['cve_id' => 'CVE-2024-1086', 'date_added' => '2024-05-30', 'updated_at' => now()]);
        $this->vuln(['cve_id' => 'CVE-2024-1086', 'due_date' => now()->subDays(2)]);
        $this->vuln(['cve_id' => 'CVE-2024-1086'], $this->other);
        $this->incident(['severity' => 'critical', 'status' => 'investigating']);
        $this->incident(['status' => 'investigating'], $this->other);
        $n = IncidentNotification::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'incident_id' => $this->incident()->id, 'template_key' => 'cbn_24h',
            'regulator_code' => 'CBN', 'delivery_status' => 'draft', 'deadline_at' => now()->subHour(),
        ]);

        $this->actingAs($this->admin)->get(route('security-ops.dashboard'))
            ->assertInertia(fn (Assert $p) => $p->component('SecurityOps/Dashboard')
                ->where('stats.vulns_kev', 1)->where('stats.vulns_sla_breached', 1)
                ->where('stats.incidents_critical', 1)->where('stats.notifications_overdue', 1)
                ->where('clocks.0.id', $n->id)->where('clocks.0.overdue', true)
                ->has('activeIncidents', 2));

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('secops.stats.vulns_kev', 1)->where('secops.stats.notifications_overdue', 1));
    }
}

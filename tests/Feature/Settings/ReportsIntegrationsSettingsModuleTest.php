<?php

namespace Tests\Feature\Settings;

use App\Models\CustomField;
use App\Models\FeatureFlag;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\TenantTheme;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Reports, Integrations hub and Settings (ATH-VAL-018). */
class ReportsIntegrationsSettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin', $this->org);
        $this->otherAdmin = $this->user('Organization Admin', $this->other);
    }

    private function user(string $role, Organization $org): User
    {
        $u = User::factory()->create(['organization_id' => $org->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_report_counts_use_real_statuses_and_one_basis(): void
    {
        $risk = Risk::factory()->create(['organization_id' => $this->org->id, 'status' => 'assessed', 'inherent_rating' => 'high']);
        Risk::factory()->create(['organization_id' => $this->org->id, 'status' => 'mitigated', 'inherent_rating' => 'high']);
        Risk::factory()->create(['organization_id' => $this->org->id, 'status' => 'closed', 'inherent_rating' => 'high']);
        foreach (['draft', 'approved', 'in_progress'] as $st) {
            RiskTreatment::create(['organization_id' => $this->org->id, 'risk_id' => $risk->id, 'title' => 't', 'status' => $st, 'strategy' => 'mitigate']);
        }

        $this->actingAs($this->admin)->get(route('reports.risks'))->assertInertia(fn (Assert $p) => $p
            ->where('stats.total', 2)->where('stats.open', 1)
            ->where('treatmentStats.not_started', 2)->where('treatmentStats.in_progress', 1));
        $this->get(route('reports.executive'))->assertInertia(fn (Assert $p) => $p
            ->where('riskStats.total', 2)->where('riskStats.open', 1));
    }

    public function test_scheduled_reports_are_bank_isolated_and_run_due_advances(): void
    {
        $this->actingAs($this->admin)->post(route('reports.scheduled.store'), [
            'name' => 'Weekly', 'report_type' => 'executive', 'frequency' => 'weekly', 'recipients' => ['a@bank.ng'],
        ])->assertSessionDoesntHaveErrors();
        $this->post(route('reports.scheduled.store'), ['name' => 'x', 'report_type' => 'bogus', 'frequency' => 'weekly'])->assertSessionHasErrors('report_type');
        $this->post(route('reports.scheduled.store'), ['name' => 'x', 'report_type' => 'risks', 'frequency' => 'daily', 'recipients' => ['nope']])->assertSessionHasErrors('recipients.0');

        $mine = ScheduledReport::firstOrFail();
        $this->actingAs($this->otherAdmin)->get(route('reports.scheduled'))->assertInertia(fn (Assert $p) => $p->has('schedules', 0));
        $this->delete(route('reports.scheduled.destroy', $mine->id))->assertNotFound();

        $mine->update(['next_run_at' => now()->subHour()]);
        $this->artisan('reports:run-due')->assertSuccessful();
        $mine->refresh();
        $this->assertTrue($mine->next_run_at->isFuture());
        $this->assertNotNull($mine->last_run_at);
        $this->assertSame(1, ScheduledReportRun::withoutGlobalScopes()->where('scheduled_report_id', $mine->id)->count());
    }

    public function test_integration_save_masks_secrets_and_is_bank_isolated(): void
    {
        $this->actingAs($this->admin)->post(route('integrations.save', 'tenable'), [
            'base_url' => 'https://cloud.tenable.com', 'access_key' => 'SUPERSECRET', 'scope' => 'read-only', 'sync_frequency' => 'hourly',
        ])->assertSessionDoesntHaveErrors();

        $raw = \DB::table('integration_connections')->value('config');
        $this->assertStringNotContainsString('SUPERSECRET', $raw);
        $this->get(route('integrations.show', 'tenable'))->assertInertia(fn (Assert $p) => $p
            ->where('config.access_key', null)->where('config.access_key_set', true)->where('connector.status', 'configured'));
        $this->assertStringNotContainsString('SUPERSECRET', $this->get(route('integrations.show', 'tenable'))->getContent());

        $this->actingAs($this->otherAdmin)->get(route('integrations.show', 'tenable'))->assertInertia(fn (Assert $p) => $p
            ->where('connector.status', 'not_configured')->where('config', []));
    }

    public function test_integration_test_fails_without_fields_and_viewer_is_forbidden(): void
    {
        $this->actingAs($this->admin)->post(route('integrations.test', 'okta'))->assertSessionHas('error');
        $this->assertSame('failed', IntegrationConnection::firstOrFail()->status);
        $this->post(route('integrations.save', 'okta'), ['base_url' => 'https://x.okta.com', 'access_key' => 'k', 'scope' => 'read-only', 'sync_frequency' => 'daily']);
        $this->post(route('integrations.test', 'okta'))->assertSessionHas('success');
        $this->assertSame('connected', IntegrationConnection::where('connector_key', 'okta')->value('status'));

        $viewer = $this->user('Viewer', $this->org);
        $this->actingAs($viewer)->post(route('integrations.test', 'okta'))->assertForbidden();
        $this->post(route('integrations.save', 'okta'), [])->assertForbidden();
    }

    public function test_theme_and_custom_fields_are_bank_scoped(): void
    {
        $this->actingAs($this->admin)->put(route('settings.theme.update'), [
            'tokens' => ['navy' => '#111111', 'gold' => '#222222', 'green' => '#333333', 'charcoal' => '#444444'], 'logo_path' => null,
        ])->assertSessionDoesntHaveErrors();
        $this->put(route('settings.theme.update'), ['tokens' => ['navy' => 'red']])->assertSessionHasErrors('tokens.navy');
        $this->post(route('settings.custom-fields.store'), ['subject_type' => 'Risk', 'key' => 'my_field', 'label' => 'F', 'data_type' => 'text'])->assertSessionDoesntHaveErrors();
        $this->post(route('settings.custom-fields.store'), ['subject_type' => 'Risk', 'key' => 'my_field', 'label' => 'F', 'data_type' => 'text'])->assertSessionHasErrors('key');

        $this->actingAs($this->otherAdmin)->get(route('settings.theme'))->assertInertia(fn (Assert $p) => $p
            ->where('theme.is_default', true)->where('theme.tokens.navy', '#0A1F44'));
        $this->get(route('settings.custom-fields'))->assertInertia(fn (Assert $p) => $p->has('fields', 0));
        // same key is allowed in another bank
        $this->post(route('settings.custom-fields.store'), ['subject_type' => 'Risk', 'key' => 'my_field', 'label' => 'F', 'data_type' => 'text'])->assertSessionDoesntHaveErrors();
        $this->delete(route('settings.custom-fields.destroy', CustomField::withoutGlobalScopes()->where('organization_id', $this->org->id)->value('id')))->assertNotFound();
        $this->assertSame(1, TenantTheme::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
    }

    public function test_bank_flag_override_is_isolated_and_global_default_is_super_admin_only(): void
    {
        $flag = FeatureFlag::create(['organization_id' => null, 'key' => 'x.demo', 'enabled' => true, 'rollout_percent' => 100]);

        $this->actingAs($this->admin)->post(route('settings.feature-flags.toggle', $flag->id));
        $this->assertFalse(FeatureFlag::enabledFor('x.demo', $this->org->id));
        $this->assertTrue(FeatureFlag::enabledFor('x.demo', $this->other->id));
        $this->assertTrue($flag->fresh()->enabled);

        $this->post(route('settings.feature-flags.toggle-global', $flag->id))->assertForbidden();
        $super = $this->user('Super Admin', $this->org);
        $this->actingAs($super)->post(route('settings.feature-flags.toggle-global', $flag->id));
        $this->assertFalse($flag->fresh()->enabled);
        $this->assertFalse(FeatureFlag::enabledFor('x.demo', $this->other->id));
    }

    public function test_notification_preferences_save_for_self_only(): void
    {
        $payload = ['email_risk_alerts' => false, 'email_compliance_deadlines' => true, 'email_incidents' => true,
            'email_weekly_digest' => true, 'inapp_task_assignments' => false, 'inapp_mentions' => true, 'finance_daily_digest' => true];
        $this->actingAs($this->admin)->patch(route('settings.notifications.update'), $payload)->assertSessionDoesntHaveErrors();

        $this->assertFalse($this->admin->fresh()->notification_preferences['email_risk_alerts']);
        $this->assertNull($this->otherAdmin->fresh()->notification_preferences);
        $this->get(route('settings.notifications'))->assertInertia(fn (Assert $p) => $p->where('prefs.email_weekly_digest', true));
    }
}

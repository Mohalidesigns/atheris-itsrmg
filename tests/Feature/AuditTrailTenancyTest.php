<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** P7 review C1: the platform audit trail is tenant-scoped and masks finance amounts. */
class AuditTrailTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Organization::factory()->create();
        $this->b = Organization::factory()->create();
        foreach (['view audit-trail', 'view it-finance-amounts'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    private function row(Organization $org, string $log, array $props = []): Activity
    {
        $a = new Activity;
        $a->log_name = $log;
        $a->description = "row-{$org->id}-{$log}";
        $a->properties = collect($props);
        $a->organization_id = $org->id;
        $a->save();

        return $a;
    }

    private function viewer(Organization $org, bool $amounts = false): User
    {
        $u = User::factory()->create(['organization_id' => $org->id]);
        $u->givePermissionTo($amounts ? ['view audit-trail', 'view it-finance-amounts'] : ['view audit-trail']);

        return $u;
    }

    public function test_viewer_sees_only_own_org_rows(): void
    {
        $this->row($this->a, 'default');
        $this->row($this->b, 'default');
        $res = $this->actingAs($this->viewer($this->b))->get('/settings/audit-trail')->assertOk();
        $descs = collect($res->viewData('page')['props']['activities']['data'])->pluck('description');
        $this->assertTrue($descs->contains("row-{$this->b->id}-default"));
        $this->assertFalse($descs->contains("row-{$this->a->id}-default"));
    }

    public function test_finance_properties_masked_without_amounts_permission(): void
    {
        $this->row($this->a, 'it-finance', ['attributes' => ['amount' => '999.00']]);
        $props = fn (User $u) => collect($this->actingAs($u)->get('/settings/audit-trail?log_name=it-finance')->assertOk()
            ->viewData('page')['props']['activities']['data'])->first()['properties'];

        $this->assertStringNotContainsString('999.00', json_encode($props($this->viewer($this->a))));
        $this->assertStringContainsString('999.00', json_encode($props($this->viewer($this->a, true))));
    }

    public function test_activity_without_auth_user_still_saves(): void
    {
        $act = activity()->log('console job');
        $this->assertNotNull($act->id);
        $this->assertNull(Activity::withoutGlobalScopes()->find($act->id)->organization_id);
    }

    public function test_creating_resolves_org_from_causer(): void
    {
        $u = User::factory()->create(['organization_id' => $this->a->id]);
        $act = activity()->causedBy($u)->log('x');
        $this->assertSame($this->a->id, Activity::withoutGlobalScopes()->find($act->id)->organization_id);
    }

    public function test_backfill_assigns_org_from_subject_then_causer(): void
    {
        $subject = User::factory()->create(['organization_id' => $this->a->id]);
        $causer = User::factory()->create(['organization_id' => $this->b->id]);
        $now = now();
        $base = ['log_name' => 'default', 'description' => 'd', 'created_at' => $now, 'updated_at' => $now];
        $s = DB::table('activity_log')->insertGetId($base + ['subject_type' => User::class, 'subject_id' => $subject->id, 'causer_type' => User::class, 'causer_id' => $causer->id]);
        $c = DB::table('activity_log')->insertGetId($base + ['causer_type' => User::class, 'causer_id' => $causer->id]);

        (include base_path('database/migrations/2027_04_03_000001_add_organization_id_to_activity_log.php'))->up();

        $this->assertSame($this->a->id, (int) DB::table('activity_log')->where('id', $s)->value('organization_id'));
        $this->assertSame($this->b->id, (int) DB::table('activity_log')->where('id', $c)->value('organization_id'));
    }
}

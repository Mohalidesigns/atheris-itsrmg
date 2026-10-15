<?php

namespace Tests\Feature\Identity;

use App\Models\CopilotConversation;
use App\Models\CopilotMessage;
use App\Models\Organization;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Identity & Access, Administration and Copilot (ATH-VAL-019): tenant isolation and authenticated SCIM. */
class IdentityAdminCopilotModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = Organization::factory()->create();
        $this->b = Organization::factory()->create();
        $this->adminA = $this->make('Organization Admin', $this->a);
        $this->userB = $this->make('Organization Admin', $this->b);
    }

    private function make(string $role, Organization $org): User
    {
        $u = User::factory()->create(['organization_id' => $org->id]);
        $u->assignRole($role);

        return $u;
    }

    private function token(Organization $org): string
    {
        $plain = 'scim_'.bin2hex(random_bytes(12));
        ScimToken::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'name' => 't', 'token_hash' => hash('sha256', $plain),
        ]);

        return $plain;
    }

    public function test_demo_token_is_rejected_and_token_sees_only_its_own_bank(): void
    {
        $this->getJson('/scim/v2/Users', ['Authorization' => 'Bearer demo-anything'])->assertStatus(401);

        $tokenA = $this->token($this->a);
        $res = $this->getJson('/scim/v2/Users', ['Authorization' => "Bearer {$tokenA}"])->assertOk();
        $emails = collect($res->json('Resources'))->pluck('userName');
        $this->assertTrue($emails->contains($this->adminA->email));
        $this->assertFalse($emails->contains($this->userB->email));
        $this->getJson("/scim/v2/Users/{$this->userB->id}", ['Authorization' => "Bearer {$tokenA}"])->assertNotFound();
    }

    public function test_scim_create_refuses_email_of_another_bank_and_creates_in_token_org(): void
    {
        $h = ['Authorization' => 'Bearer '.$this->token($this->a)];
        $this->postJson('/scim/v2/Users', ['userName' => $this->userB->email], $h)->assertStatus(409);

        $this->postJson('/scim/v2/Users', ['userName' => 'new.person@example.test', 'name' => ['formatted' => 'New Person']], $h)->assertCreated();
        $this->assertSame($this->a->id, User::where('email', 'new.person@example.test')->value('organization_id'));
    }

    public function test_bank_admin_cannot_touch_users_of_another_bank_or_move_users(): void
    {
        $this->actingAs($this->adminA);
        $payload = ['name' => 'X', 'email' => $this->userB->email, 'roles' => ['Organization Admin'], 'organization_id' => $this->a->id];
        $this->get(route('admin.users.edit', $this->userB->id))->assertNotFound();
        $this->put(route('admin.users.update', $this->userB->id), $payload)->assertNotFound();
        $this->delete(route('admin.users.destroy', $this->userB->id))->assertNotFound();
        $this->patch(route('admin.users.toggle-active', $this->userB->id))->assertNotFound();

        $own = $this->make('Organization Admin', $this->a);
        $this->put(route('admin.users.update', $own->id), ['name' => 'Own', 'email' => $own->email, 'roles' => ['Organization Admin'], 'organization_id' => $this->b->id]);
        $this->assertSame($this->a->id, $own->fresh()->organization_id);

        $this->post(route('admin.users.store'), [
            'name' => 'N', 'email' => 'n@example.test', 'password' => 'Str0ng!Passw0rd#1', 'password_confirmation' => 'Str0ng!Passw0rd#1',
            'organization_id' => $this->b->id, 'roles' => ['Super Admin'],
        ])->assertForbidden();
    }

    public function test_only_super_admin_can_change_roles(): void
    {
        $role = Role::create(['name' => 'Temp Role']);
        $this->actingAs($this->adminA)
            ->put(route('admin.roles.update', $role->id), ['name' => 'Renamed', 'permissions' => []])
            ->assertForbidden();
        $this->assertSame('Temp Role', $role->fresh()->name);

        $super = $this->make('Super Admin', $this->a);
        $this->actingAs($super)
            ->put(route('admin.roles.update', $role->id), ['name' => 'Renamed', 'permissions' => []])
            ->assertRedirect();
        $this->assertSame('Renamed', $role->fresh()->name);
    }

    public function test_copilot_is_bank_scoped_and_offline(): void
    {
        $conv = CopilotConversation::withoutGlobalScopes()->create([
            'organization_id' => $this->b->id, 'user_id' => $this->userB->id, 'title' => 'B only', 'mode' => 'general',
        ]);
        CopilotMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'secret', 'model' => 'atheris-offline']);

        $this->actingAs($this->adminA)->get(route('copilot.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Copilot/Index')->where('conversations', fn ($c) => count($c) === 0));
        $this->get(route('copilot.index', ['c' => $conv->id]))->assertNotFound();
        $this->post(route('copilot.send'), ['conversation_id' => $conv->id, 'content' => 'hello'])->assertNotFound();

        $this->post(route('copilot.send'), ['content' => 'hello there'])->assertRedirect();
        $own = CopilotConversation::withoutGlobalScopes()->where('user_id', $this->adminA->id)->first();
        $this->assertSame($this->a->id, $own->organization_id);
        $this->assertSame('atheris-offline', CopilotMessage::where('conversation_id', $own->id)->where('role', 'assistant')->value('model'));
    }

    public function test_sso_list_is_bank_scoped_and_saml_acs_never_logs_in(): void
    {
        SsoConnection::withoutGlobalScopes()->create(['organization_id' => $this->a->id, 'type' => 'saml', 'idp_name' => 'A-IdP']);
        SsoConnection::withoutGlobalScopes()->create(['organization_id' => $this->b->id, 'type' => 'saml', 'idp_name' => 'B-IdP']);

        $this->actingAs($this->adminA)->get(route('identity.sso'))
            ->assertInertia(fn (Assert $p) => $p->where('connections', fn ($c) => collect($c)->pluck('idp_name')->all() === ['A-IdP']));

        auth()->logout();
        $this->post(route('saml.acs', ['tenant' => 'x']), ['SAMLResponse' => 'forged'])->assertRedirect(route('login'));
        $this->assertGuest();
    }
}

<?php

namespace Tests\Feature\Changes;

use App\Models\Change\ChangeCabMeeting;
use App\Models\Change\ChangeRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Change\ChangePolicySeeder;
use App\Services\Change\ChangeWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Change Management routes (ATH-CHG-001 §7): every `changes.*` GET page renders for an
 * Organization Admin over the default policy, is forbidden without `view changes`, and
 * record pages 404 for another bank's records.
 */
class ChangeTenancyAndRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private ChangeRequest $change;

    private ChangeCabMeeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'job_title' => 'CISO']);
        $this->admin->assignRole('Organization Admin');

        ChangePolicySeeder::ensureDefaults($this->org->id);
        [$cab] = ChangePolicySeeder::ensureBoards($this->org->id);
        $this->change = app(ChangeWorkflow::class)->create([
            'title' => 'Patch perimeter firewall', 'description' => 'Vendor hotfix.', 'change_type' => 'normal', 'category' => 'network',
            'priority' => 'high', 'impact' => 2, 'likelihood' => 2, 'impact_assessment' => 'None.', 'implementation_plan' => 'Apply.', 'backout_plan' => 'Revert.',
        ], $this->admin);
        $this->meeting = ChangeCabMeeting::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'board_id' => $cab->id, 'title' => 'Weekly CAB', 'scheduled_at' => now()->addDay(), 'status' => 'scheduled',
        ]);
    }

    /** @return array<string, string> route name => URL with this bank's records bound */
    private function getRoutes(): array
    {
        $params = ['change' => $this->change->id, 'meeting' => $this->meeting->id];

        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'changes.') && in_array('GET', $r->methods(), true))
            ->mapWithKeys(function (RouteDefinition $r) use ($params) {
                $bound = collect($r->parameterNames())->mapWithKeys(fn ($name) => [$name => $params[$name] ?? $this->fail("No fixture for route parameter {{$name}} on {$r->getName()}")]);

                return [$r->getName() => route($r->getName(), $bound->all())];
            })->sortKeys()->all();
    }

    public function test_every_changes_page_renders_for_an_organization_admin(): void
    {
        $routes = $this->getRoutes();
        $this->assertEqualsCanonicalizing([
            'changes.index', 'changes.create', 'changes.approvals', 'changes.calendar', 'changes.export', 'changes.cab.index',
            'changes.cab.meetings.show', 'changes.policy', 'changes.templates', 'changes.freeze.index', 'changes.show',
        ], array_keys($routes));

        foreach ($routes as $name => $url) {
            $response = $this->actingAs($this->admin)->get($url);
            $this->assertSame(200, $response->getStatusCode(), "{$name} ({$url}) returned {$response->getStatusCode()}");
            if ($name === 'changes.export') {
                $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
            } else {
                $response->assertInertia(fn ($p) => $p->has('auth.user'));
            }
        }
    }

    public function test_every_changes_page_is_forbidden_without_view_changes(): void
    {
        $nobody = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertFalse($nobody->can('view changes'));

        foreach ($this->getRoutes() as $name => $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
        $this->actingAs($nobody)->post(route('changes.store'), [])->assertForbidden();
        $this->actingAs($nobody)->post(route('changes.transition', $this->change), ['action' => 'submit'])->assertForbidden();
        $this->actingAs($nobody)->post(route('changes.decide', $this->change), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_record_pages_404_for_another_banks_records(): void
    {
        $other = Organization::factory()->create();
        $outsider = User::factory()->create(['organization_id' => $other->id, 'job_title' => 'CISO']);
        $outsider->assignRole('Organization Admin');

        $this->actingAs($outsider)->get(route('changes.show', $this->change))->assertNotFound();
        $this->actingAs($outsider)->get(route('changes.cab.meetings.show', $this->meeting))->assertNotFound();
        $this->actingAs($outsider)->put(route('changes.update', $this->change), ['title' => 'x'])->assertNotFound();
        $this->actingAs($outsider)->post(route('changes.transition', $this->change), ['action' => 'submit'])->assertNotFound();
        $this->actingAs($outsider)->get(route('changes.index'))->assertOk()->assertInertia(fn ($p) => $p->has('changes', 0));
        $this->actingAs($this->admin)->get(route('changes.index'))->assertOk()->assertInertia(fn ($p) => $p->has('changes', 1));
    }
}

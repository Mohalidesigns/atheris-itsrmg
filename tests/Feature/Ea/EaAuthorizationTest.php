<?php

namespace Tests\Feature\Ea;

use App\Models\Ea\AdmDeliverable;
use App\Models\Ea\AnomalyFinding;
use App\Models\Ea\ArbSubmission;
use App\Models\Ea\Capability;
use App\Models\Ea\DecisionRecord;
use App\Models\Ea\Diagram;
use App\Models\Ea\DiagramVersion;
use App\Models\Ea\DraftChange;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\EvidencePack;
use App\Models\Ea\Exception;
use App\Models\Ea\ExchangeJob;
use App\Models\Ea\Initiative;
use App\Models\Ea\Plateau;
use App\Models\Ea\PlateauEntity;
use App\Models\Ea\Principle;
use App\Models\Ea\Standard;
use App\Models\Ea\Subscription;
use App\Models\Ea\Survey;
use App\Models\Ea\SurveyCampaign;
use App\Models\Ea\SurveyResponse;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ThreatModel;
use App\Models\User;
use App\Policies\Ea\EaPolicy;
use App\Services\Ea\GraphQlService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ATH-EAR-002 Phase 0 exit criterion and §10 release gate:
 *
 *   "Every write route gated; every controller action authorised; a test
 *    asserting Viewer receives 403 on all 44 write routes."
 *
 * §2.2 (RC-2) recorded the pre-remediation position: `view ea` is granted to
 * Viewer and Auditor, zero of the 44 write routes carried their own middleware,
 * EaController contained zero authorize() calls, and EaPolicy — itself never
 * registered — ended in `return $user->id > 0`. A Viewer could
 * POST /ea/applications and DELETE /ea/tech-components/{id}.
 */
class EaAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seedBoundRecords();
    }

    /**
     * Create id=1 in every table an EA write route binds a model from.
     *
     * Without these the request 404s in SubstituteBindings — which runs in the
     * `web` group, before route-level middleware — and the test would pass
     * while proving nothing about authorisation. A 404 is not a security
     * failure, but it is not evidence of a 403 either.
     */
    private function seedBoundRecords(): void
    {
        Capability::forceCreate(['code' => 'CAP-1', 'name' => 'Capability']);
        EaApplication::forceCreate(['code' => 'APP-1', 'name' => 'Application']);
        TechComponent::forceCreate(['code' => 'TEC-1', 'name' => 'Component']);
        EaInterface::forceCreate(['code' => 'INT-1', 'name' => 'Interface']);
        Principle::forceCreate(['code' => 'PR-1', 'name' => 'Principle']);
        Standard::forceCreate(['code' => 'STD-1', 'name' => 'Standard']);
        ArbSubmission::forceCreate(['code' => 'ARB-1', 'title' => 'Submission']);
        Exception::forceCreate(['code' => 'EXC-1', 'subject' => 'Waiver']);
        $initiative = Initiative::forceCreate(['code' => 'INI-1', 'name' => 'Initiative']);
        AdmDeliverable::forceCreate([
            'initiative_id' => $initiative->id, 'phase' => 'A', 'code' => 'DEL-1', 'name' => 'Deliverable',
        ]);
        Diagram::forceCreate(['code' => 'DIA-1', 'name' => 'Diagram']);
        ThreatModel::forceCreate([
            'code' => 'TM-1', 'name' => 'Threat model',
            'subject_type' => EaApplication::class, 'subject_id' => 1,
        ]);
        AnomalyFinding::forceCreate([
            'rule_code' => 'R-1', 'entity_type' => EaApplication::class, 'title' => 'Finding',
        ]);
        EvidencePack::forceCreate(['code' => 'EP-1', 'name' => 'Pack', 'period' => '2026-Q3']);
        ExchangeJob::forceCreate(['type' => 'export']);

        // Phase 1 bound models (subscriptions, surveys, campaigns, ADRs).
        $user = User::factory()->create();
        Subscription::forceCreate([
            'entity_type' => EaApplication::class,
            'entity_id' => 1, 'user_id' => $user->id, 'role' => 'accountable',
        ]);
        $survey = Survey::forceCreate([
            'code' => 'SUR-1', 'name' => 'Survey',
            'entity_type' => EaApplication::class,
            'fields' => [['attribute' => 'criticality', 'label' => 'Criticality', 'type' => 'select']],
        ]);
        SurveyCampaign::forceCreate([
            'survey_id' => $survey->id, 'code' => 'SUR-1-1', 'state' => 'running',
        ]);
        DecisionRecord::forceCreate(['code' => 'ADR-0001', 'title' => 'Decision']);

        // Phase 4 bound models. Without these, ea.diagrams.restore,
        // ea.plateaux.* and ea.drafts.* 404 in SubstituteBindings and the
        // Viewer/Auditor sweeps stop proving anything about them — the exact
        // trap this method exists to avoid.
        DiagramVersion::forceCreate(['diagram_id' => 1, 'version' => 1]);
        Plateau::forceCreate([
            'code' => 'PLA-1', 'name' => 'Plateau', 'plateau_type' => 'target',
        ]);
        PlateauEntity::forceCreate([
            'plateau_id' => 1,
            'entity_type' => EaApplication::class,
            'entity_id' => 1,
            'disposition' => 'retain',
        ]);
        DraftChange::forceCreate([
            'reference' => 'DRAFT-SEEDED',
            'origin' => 'graphql',
            'operation' => 'update',
            'entity_type' => EaApplication::class,
            'entity_id' => 1,
            'payload' => ['criticality' => 'high'],
            'status' => 'pending',
        ]);
    }

    /**
     * The magic-link survey portal is unauthenticated **by design**.
     *
     * ATH-EAR-002 §5.4 B2 requires "magic-link responses from non-licensed
     * users" — §3.4 records that LeanIX can only survey active licensed users,
     * "a structural crowdsourcing ceiling", and beating it means the respondent
     * cannot be required to hold an account or a permission. The credential is
     * the token, not the session, so these routes are excluded from the
     * permission-gating assertions and covered separately by
     * test_the_survey_portal_is_reachable_without_a_session() below.
     */
    private const UNAUTHENTICATED_BY_DESIGN = [
        'ea.portal.respond',
        'ea.portal.submit',
        'ea.portal.decline',
    ];

    /**
     * `POST /ea/graphql` is a write *method* carrying a read *surface*.
     *
     * GraphQL transports queries over POST, so gating the route on
     * `create ea` would refuse every reader the WS 4.5 API exists to serve.
     * The grant therefore sits one layer in, on the mutation resolver
     * ({@see GraphQlService::resolveMutation}), which is the
     * only path that records anything — and a recorded draft still writes
     * nothing to the repository until someone with `approve ea` applies it.
     *
     * Excluded from the route-middleware sweep and asserted directly in
     * test_the_graphql_endpoint_is_gated_in_the_service_not_the_route()
     * below, so the exemption is evidence rather than an assumption.
     */
    private const GATED_IN_SERVICE = [
        'ea.graphql',
    ];

    /** Names the route-level gating assertions do not apply to. */
    private function exemptFromRouteGating(): array
    {
        return array_merge(self::UNAUTHENTICATED_BY_DESIGN, self::GATED_IN_SERVICE);
    }

    /** Every POST/PUT/PATCH/DELETE route in the `ea.` group. */
    private function eaWriteRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'ea.')) {
                continue;
            }
            if (in_array($name, $this->exemptFromRouteGating(), true)) {
                continue;
            }
            $methods = array_diff($route->methods(), ['HEAD']);
            $write = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
            if ($write) {
                $routes[$name] = ['method' => reset($write), 'uri' => $route->uri()];
            }
        }

        return $routes;
    }

    /** Substitute 1 for every route parameter — authorisation is checked before binding. */
    private function fill(string $uri): string
    {
        return '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $uri), '/');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_the_ea_group_still_exposes_the_expected_write_surface(): void
    {
        // Guards the test itself: if the route group shrinks, the assertions
        // below would silently stop covering anything.
        $this->assertGreaterThanOrEqual(44, count($this->eaWriteRoutes()));
    }

    public function test_every_ea_write_route_carries_its_own_permission_middleware(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'ea.')) {
                continue;
            }
            if (in_array($name, $this->exemptFromRouteGating(), true)) {
                continue;
            }
            if (! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                continue;
            }

            $middleware = implode(',', $route->gatherMiddleware());
            // `permission:view ea` is the group-level read gate and does not
            // count as gating a write.
            $hasWriteGate = (bool) preg_match(
                '/permission:(create|edit|delete|approve|export) ea/',
                $middleware
            );

            if (! $hasWriteGate) {
                $ungated[] = $name;
            }
        }

        $this->assertSame([], $ungated, 'EA write routes with no write permission: '.implode(', ', $ungated));
    }

    public function test_viewer_is_refused_on_every_ea_write_route(): void
    {
        $viewer = $this->userWithRole('Viewer');

        // The precondition that made RC-2 exploitable: Viewer *can* read EA.
        $this->assertTrue($viewer->can('view ea'));
        $this->assertFalse($viewer->can('create ea'));
        $this->assertFalse($viewer->can('edit ea'));
        $this->assertFalse($viewer->can('delete ea'));

        $allowed = [];
        foreach ($this->eaWriteRoutes() as $name => $r) {
            $response = $this->actingAs($viewer)
                ->call($r['method'], $this->fill($r['uri']));

            if ($response->status() !== 403) {
                $allowed[] = "{$name} ({$r['method']}) => {$response->status()}";
            }
        }

        $this->assertSame([], $allowed, "Viewer was not refused on:\n".implode("\n", $allowed));
    }

    public function test_auditor_is_refused_on_every_ea_write_route_except_export(): void
    {
        // §2.3: Auditor holds `view ea` and `export ea` and nothing else.
        $auditor = $this->userWithRole('Auditor');

        $allowed = [];
        foreach ($this->eaWriteRoutes() as $name => $r) {
            $response = $this->actingAs($auditor)->call($r['method'], $this->fill($r['uri']));

            // Evidence-pack generation is an export action and is legitimately
            // available to an auditor.
            $isExport = str_contains($name, 'evidence-packs') || str_contains($name, 'exchange.download');

            if (! $isExport && $response->status() !== 403) {
                $allowed[] = "{$name} ({$r['method']}) => {$response->status()}";
            }
        }

        $this->assertSame([], $allowed, "Auditor was not refused on:\n".implode("\n", $allowed));
    }

    public function test_enterprise_architect_is_never_refused_on_an_ea_write_route(): void
    {
        // The other half of the Phase 0 exit criterion: "An Enterprise
        // Architect can perform every catalogue operation." A gate that
        // refuses everyone is not a fix.
        $architect = $this->userWithRole('Enterprise Architect');

        $refused = [];
        foreach ($this->eaWriteRoutes() as $name => $r) {
            $response = $this->actingAs($architect)->call($r['method'], $this->fill($r['uri']));

            // 302 (redirect back), 422 (validation) and 200 all mean the
            // request passed authorisation, which is what is under test here.
            if ($response->status() === 403) {
                $refused[] = "{$name} ({$r['method']})";
            }
        }

        $this->assertSame([], $refused, "Enterprise Architect was refused on:\n".implode("\n", $refused));
    }

    public function test_enterprise_architect_role_exists_and_holds_the_full_ea_grant(): void
    {
        // §2.3 (RC-3): "There is no Enterprise Architect, Solution Architect,
        // Application Owner or Data Steward. The people who would populate and
        // maintain an architecture repository have no seat."
        foreach (['Enterprise Architect', 'Solution Architect', 'Application Owner', 'Data Steward'] as $role) {
            $this->assertNotNull(Role::where('name', $role)->first(), "Role {$role} was not seeded.");
        }

        $architect = $this->userWithRole('Enterprise Architect');
        foreach (['view ea', 'create ea', 'edit ea', 'delete ea', 'approve ea', 'export ea'] as $permission) {
            $this->assertTrue($architect->can($permission), "Enterprise Architect lacks '{$permission}'.");
        }
    }

    public function test_solution_architect_may_author_but_not_delete_or_approve(): void
    {
        // Segregation of duties: a solution architect submits to the ARB, it
        // does not decide its own submissions.
        $sa = $this->userWithRole('Solution Architect');

        $this->assertTrue($sa->can('create ea'));
        $this->assertTrue($sa->can('edit ea'));
        $this->assertFalse($sa->can('delete ea'));
        $this->assertFalse($sa->can('approve ea'));

        $this->assertSame(403, $this->actingAs($sa)->delete('/ea/capabilities/1')->status());
        $this->assertSame(403, $this->actingAs($sa)->post('/ea/arb/1/decide')->status());
    }

    public function test_application_owner_may_edit_but_not_create_or_delete(): void
    {
        $owner = $this->userWithRole('Application Owner');

        $this->assertTrue($owner->can('view ea'));
        $this->assertTrue($owner->can('edit ea'));
        $this->assertFalse($owner->can('create ea'));
        $this->assertFalse($owner->can('delete ea'));

        $this->assertSame(403, $this->actingAs($owner)->post('/ea/applications')->status());
    }

    /**
     * The magic-link portal is excluded from the gating assertions above, so
     * its actual security properties are asserted here rather than assumed.
     */
    public function test_the_survey_portal_is_reachable_without_a_session_but_only_with_a_valid_token(): void
    {
        $survey = Survey::first();
        $campaign = SurveyCampaign::first();
        $campaign->update(['opens_at' => now(), 'closes_at' => now()->addDays(14)]);

        $response = SurveyResponse::forceCreate([
            'campaign_id' => $campaign->id,
            'entity_type' => EaApplication::class,
            'entity_id' => 1,
            'recipient_user_id' => null,   // a genuinely non-licensed respondent
            'recipient_email' => 'owner@bank.ng',
            'token' => SurveyResponse::newToken(),
            'state' => SurveyResponse::PENDING,
        ]);

        // Reachable with no authentication at all — that is the point.
        $this->assertGuest();
        $this->get(route('ea.portal.respond', $response->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ea/Portal/Respond'));

        // A wrong token gets the same generic "unavailable" page, not a 404 or
        // a 403, so the endpoint cannot be used to probe for valid tokens.
        $this->get(route('ea.portal.respond', str_repeat('x', 64)))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ea/Portal/Invalid'));

        // And the token gives no access to the repository itself.
        $this->get('/ea/applications')->assertRedirect();
        $this->get('/ea/surveys')->assertRedirect();
    }

    /**
     * `ea.graphql` is excluded from the route-middleware sweep, so the grant it
     * relies on instead is asserted here — in both directions, and against the
     * repository rather than against the response alone.
     */
    public function test_the_graphql_endpoint_is_gated_in_the_service_not_the_route(): void
    {
        $viewer = $this->userWithRole('Viewer');
        // seedBoundRecords() leaves one draft behind for route binding, so the
        // assertions below are about the *delta*, not the absolute count.
        $baseline = DraftChange::count();

        // 1. A reader may query. This is the reason the route cannot demand
        //    `create ea`: GraphQL puts reads on POST.
        $this->actingAs($viewer)
            ->postJson('/ea/graphql', ['query' => 'query { applications { code } }'])
            ->assertOk()
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.applications.0.code', 'APP-1');

        // 2. The same reader may not propose. GraphQL reports resolver failures
        //    as a 200 carrying `errors` — which is precisely why the route-level
        //    status sweep cannot see this gate and this test has to.
        $refused = $this->actingAs($viewer)->postJson('/ea/graphql', [
            'query' => 'mutation { proposeCreate(entityType: "EaApplication", input: { code: "APP-VIEWER", name: "Smuggled" }) }',
        ])->assertOk();

        $this->assertStringContainsString('create ea', $refused->json('errors.0.message'));
        $this->assertSame($baseline, DraftChange::count(), 'A Viewer recorded a draft change.');
        $this->assertNull(EaApplication::withoutGlobalScopes()->where('code', 'APP-VIEWER')->first());

        // 3. An architect may propose — and that still writes nothing to the
        //    repository, which is the WS 4.5 contract: the API only ever drafts.
        $architect = $this->userWithRole('Enterprise Architect');

        $accepted = $this->actingAs($architect)->postJson('/ea/graphql', [
            'query' => 'mutation { proposeCreate(entityType: "EaApplication", input: { code: "APP-DRAFTED", name: "Proposed" }) }',
        ])->assertOk();

        $accepted->assertJsonMissingPath('errors');
        $this->assertFalse($accepted->json('data.proposeCreate.applied'));
        $this->assertSame('pending', $accepted->json('data.proposeCreate.status'));
        $this->assertSame($baseline + 1, DraftChange::count());
        $this->assertNull(
            EaApplication::withoutGlobalScopes()->where('code', 'APP-DRAFTED')->first(),
            'A pending draft reached the repository before anyone approved it.'
        );
    }

    /**
     * The mutation resolver must refuse an unauthenticated caller outright.
     *
     * The guard read `Auth::check() && ! Gate::allows(...)`, which refused a
     * logged-in reader but waved a caller with no identity straight through.
     * The HTTP route is inside the `auth` group, so this was not exploitable
     * over the network — but the service is also constructed directly in
     * console and job context, where it would have been.
     */
    public function test_the_graphql_mutation_resolver_refuses_a_guest(): void
    {
        $this->assertGuest();
        $baseline = DraftChange::count();

        $result = (new GraphQlService)->execute(
            'mutation { proposeCreate(entityType: "EaApplication", input: { code: "APP-GUEST", name: "No identity" }) }'
        );

        $this->assertStringContainsString('create ea', $result['errors'][0]['message']);
        $this->assertSame($baseline, DraftChange::count());
    }

    public function test_ea_policy_is_registered_and_fails_closed(): void
    {
        // §2.2 Defect C: EaPolicy had no AuthServiceProvider mapping, no
        // Gate::policy() call and no #[UsePolicy] attribute anywhere.
        $this->assertInstanceOf(
            EaPolicy::class,
            Gate::getPolicyFor(Capability::class),
        );

        // Defect B: the old policy ended in `return $user->id > 0`, so a user
        // holding no EA permission at all was granted everything.
        $nobody = User::factory()->create();
        $this->assertFalse($nobody->can('create', Capability::class));
        $this->assertFalse($nobody->can('viewAny', Capability::class));
    }

    public function test_permission_names_the_policy_checks_actually_exist(): void
    {
        // §2.2 Defect A: the policy checked `ea.view` / `ea.write` /
        // `ea.approve` / `ea.admin`, none of which the seeder creates.
        foreach (['view ea', 'create ea', 'edit ea', 'delete ea', 'approve ea', 'export ea'] as $name) {
            $this->assertNotNull(
                Permission::where('name', $name)->first(),
                "Permission '{$name}' — checked by EaPolicy — is not seeded."
            );
        }

        foreach (['ea.view', 'ea.write', 'ea.approve', 'ea.admin'] as $stale) {
            $this->assertNull(
                Permission::where('name', $stale)->first(),
                "Stale permission name '{$stale}' reintroduced — EaPolicy must use the '{action} ea' form."
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\DecisionRecord;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Initiative;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Process;
use App\Models\Ea\QualitySeal;
use App\Models\Ea\SealPolicy;
use App\Models\Ea\Subscription;
use App\Models\Ea\Survey;
use App\Models\Ea\SurveyCampaign;
use App\Models\Ea\SurveyResponse;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ValueStream;
use App\Models\User;
use App\Services\Ea\EntityRegistry;
use App\Services\Ea\OwnershipService;
use App\Services\Ea\QualitySealService;
use Illuminate\Database\Seeder;

/**
 * Demo data for ATH-EAR-002 Phase 1 — ownership, quality seals, surveys and
 * decision records.
 *
 * Deliberately seeds a *partial* picture rather than a perfect one: roughly
 * two-thirds of records get an owner, and the seal states are spread across
 * approved / check-needed / draft with some already expired. A demo where
 * everything is green tells the evaluator nothing about the mechanic — the
 * point of the freshness model is that it surfaces the gap, and §2.4 warns that
 * a repository "populated once by consultants" goes stale within a quarter.
 *
 * Idempotent: safe to re-run on an already-seeded database.
 */
class EaStewardshipSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::query()->limit(12)->get();
        if ($users->isEmpty()) {
            $this->command?->warn('[EA] No users — skipping stewardship seed.');

            return;
        }

        $ownership = new OwnershipService;
        $seals = new QualitySealService($ownership);

        $this->seedSealPolicies();
        $this->seedSubscriptions($users, $ownership);
        $this->seedSeals($seals);
        $this->seedSurveys($users);
        $this->seedDecisionRecords($users);

        $this->command?->info('[EA] Stewardship demo data seeded.');
    }

    /** Tighter intervals on the objects a supervisor asks about most. */
    private function seedSealPolicies(): void
    {
        $intervals = [
            EaApplication::class => 90,
            Capability::class => 90,
            TechComponent::class => 60,
            // The CBN connection catalogue is reviewed hardest — App. II
            // §1.1(k) requires connections to be "regularly reviewed".
            EaInterface::class => 30,
        ];

        foreach ($intervals as $type => $days) {
            SealPolicy::updateOrCreate(
                ['entity_type' => $type],
                [
                    'renewal_interval_days' => $days,
                    'auto_expiry_enabled' => true,
                    'break_on_edit' => true,
                    'mandatory_attributes' => array_keys(EntityRegistry::definition($type)['mandatory'] ?? []),
                ],
            );
        }
    }

    /** Roughly two-thirds ownership coverage, so the gap is visible. */
    private function seedSubscriptions($users, OwnershipService $ownership): void
    {
        // Every type in the EntityRegistry, so a return citing an initiative
        // or a data entity can report a real evidence confidence rather than
        // an artefact of which types the seeder happened to cover.
        $plan = [
            EaApplication::class => ['Application Owner', 'Solution Architect'],
            Capability::class => ['Capability Owner', 'Business Architect'],
            TechComponent::class => ['Platform Lead', 'Infrastructure Architect'],
            EaInterface::class => ['Integration Lead', 'Solution Architect'],
            LogicalEntity::class => ['Data Steward', 'Data Architect'],
            Process::class => ['Process Owner', 'Business Architect'],
            DataFlow::class => ['Data Steward', 'Integration Lead'],
            Initiative::class => ['Programme Manager', 'Enterprise Architect'],
            ValueStream::class => ['Value Stream Owner', 'Business Architect'],
        ];

        foreach ($plan as $type => $businessRoles) {
            $records = $type::query()->get();
            if ($records->isEmpty()) {
                continue;
            }

            foreach ($records->values() as $i => $record) {
                // Leave every third record deliberately unowned.
                if ($i % 3 === 2) {
                    continue;
                }

                $accountable = $users[$i % $users->count()];
                $ownership->subscribe($type, $record->id, $accountable->id, 'accountable', $businessRoles[0]);

                if ($i % 2 === 0) {
                    $responsible = $users[($i + 3) % $users->count()];
                    if ($responsible->id !== $accountable->id) {
                        $ownership->subscribe($type, $record->id, $responsible->id, 'responsible', $businessRoles[1]);
                    }
                }

                if ($i % 5 === 0) {
                    $observer = $users[($i + 5) % $users->count()];
                    $ownership->subscribe($type, $record->id, $observer->id, 'observer');
                }
            }
        }
    }

    /**
     * A spread of seal states, including some already past their expiry so the
     * scheduled-expiry job has something to act on and the dashboards are not
     * uniformly green.
     */
    private function seedSeals(QualitySealService $seals): void
    {
        foreach (EntityRegistry::types() as $type) {
            $records = $type::query()->get()->values();
            $policy = SealPolicy::effectiveFor($type);

            foreach ($records as $i => $record) {
                $seal = $seals->sealFor($type, $record->id);
                $completeness = $seals->computeCompleteness($type, $record->id);

                // Anything with a blank mandatory attribute or no owner stays
                // in Draft — that is the gate doing its job, not a seed choice.
                if (! empty($completeness['missing_mandatory'])) {
                    $seal->fill([
                        'state' => QualitySeal::DRAFT,
                        'completeness' => $completeness['score'],
                        'missing_attributes' => $completeness['missing'],
                    ])->save();

                    continue;
                }

                $approver = Subscription::forEntity($type, $record->id)->approvers()->with('user')->first();
                $bucket = $i % 6;

                $attrs = [
                    'completeness' => $completeness['score'],
                    'missing_attributes' => $completeness['missing'],
                    'approved_by' => $approver?->user_id,
                    'approved_by_name' => $approver?->user?->name,
                ];

                if ($bucket <= 2) {
                    // Approved and current.
                    $approvedAt = now()->subDays(random_int(1, max(1, $policy->renewal_interval_days - 10)));
                    $seal->fill($attrs + [
                        'state' => QualitySeal::APPROVED,
                        'approved_at' => $approvedAt,
                        'expires_at' => $approvedAt->copy()->addDays($policy->renewal_interval_days),
                        'break_reason' => null,
                        'broken_at' => null,
                    ])->save();
                } elseif ($bucket === 3) {
                    // Approved but already past its interval — the daily job
                    // will move these to Check Needed.
                    $approvedAt = now()->subDays($policy->renewal_interval_days + random_int(1, 20));
                    $seal->fill($attrs + [
                        'state' => QualitySeal::APPROVED,
                        'approved_at' => $approvedAt,
                        'expires_at' => $approvedAt->copy()->addDays($policy->renewal_interval_days),
                    ])->save();
                } elseif ($bucket === 4) {
                    $seal->fill($attrs + [
                        'state' => QualitySeal::CHECK_NEEDED,
                        'approved_at' => now()->subDays(random_int(30, 200)),
                        'expires_at' => null,
                        'break_reason' => 'Edited after approval: criticality, lifecycle.',
                        'broken_at' => now()->subDays(random_int(1, 20)),
                    ])->save();
                } else {
                    $seal->fill($attrs + ['state' => QualitySeal::DRAFT])->save();
                }
            }
        }
    }

    private function seedSurveys($users): void
    {
        $definitions = [
            [
                'code' => 'SUR-APP-Q',
                'name' => 'Quarterly application owner confirmation',
                'description' => 'We are confirming the details we hold for the systems you own. It should take under two minutes.',
                'entity_type' => EaApplication::class,
                'fields' => [
                    ['attribute' => 'criticality', 'label' => 'How critical is this application to the business?', 'type' => 'select', 'options' => ['critical', 'high', 'medium', 'low']],
                    ['attribute' => 'lifecycle', 'label' => 'Where is it in its lifecycle?', 'type' => 'select', 'options' => ['plan', 'build', 'live', 'sunset', 'retired']],
                    ['attribute' => 'owner_role', 'label' => 'Who owns this application?', 'type' => 'text'],
                    ['attribute' => 'user_count', 'label' => 'Roughly how many people use it?', 'type' => 'number'],
                ],
                'cadence' => 'quarterly',
                'stale_after_days' => 90,
            ],
            [
                'code' => 'SUR-INT-CBN',
                'name' => 'CBN connection objective review',
                'description' => 'The CBN Risk-Based Cybersecurity Framework requires the objective of every network connection to be documented and regularly reviewed. Please confirm this one.',
                'entity_type' => EaInterface::class,
                'fields' => [
                    ['attribute' => 'objective', 'label' => 'What is the objective of this connection?', 'type' => 'textarea'],
                    ['attribute' => 'counterparty_type', 'label' => 'Who is on the other end?', 'type' => 'select', 'options' => ['internal', 'regulator', 'switch', 'third_party']],
                    ['attribute' => 'last_reviewed_at', 'label' => 'When was it last reviewed?', 'type' => 'date'],
                ],
                'cadence' => 'quarterly',
                'stale_after_days' => 30,
            ],
            [
                'code' => 'SUR-TEC-EOL',
                'name' => 'Deployed version check',
                'description' => 'The obsolescence picture is only as good as the versions we hold. Please confirm what is actually running.',
                'entity_type' => TechComponent::class,
                'fields' => [
                    ['attribute' => 'version', 'label' => 'Which version is actually deployed?', 'type' => 'text'],
                    ['attribute' => 'eol_date', 'label' => 'Known end-of-life date', 'type' => 'date'],
                ],
                'cadence' => 'annually',
                'stale_after_days' => 180,
            ],
        ];

        foreach ($definitions as $definition) {
            Survey::updateOrCreate(
                ['code' => $definition['code']],
                $definition + [
                    'audience_mode' => 'subscription',
                    'audience_roles' => ['responsible', 'accountable'],
                    'window_days' => 14,
                    'reminder_interval_days' => 7,
                    'max_reminders' => 2,
                    'is_active' => true,
                ],
            );
        }

        $this->seedRunningCampaign($users);
    }

    /**
     * One campaign mid-flight, part answered — so the completion dashboard has
     * something to show and the "magic link to a non-licensed owner" path is
     * demonstrable without sending mail.
     */
    private function seedRunningCampaign($users): void
    {
        $survey = Survey::where('code', 'SUR-APP-Q')->first();
        if (! $survey || SurveyCampaign::where('survey_id', $survey->id)->exists()) {
            return;
        }

        $apps = EaApplication::query()->limit(24)->get();
        if ($apps->isEmpty()) {
            return;
        }

        $campaign = SurveyCampaign::create([
            'survey_id' => $survey->id,
            'code' => $survey->code.'-'.now()->subDays(5)->format('Ymd-His'),
            'state' => SurveyCampaign::RUNNING,
            'opens_at' => now()->subDays(5),
            'closes_at' => now()->addDays(9),
            'launched_by' => 'Enterprise Architecture',
        ]);

        // A handful of genuine business owners with no platform account — the
        // audience LeanIX structurally cannot reach.
        $unlicensed = [
            ['email' => 'adaeze.okonkwo@bank.example.ng', 'name' => 'Adaeze Okonkwo'],
            ['email' => 'ibrahim.suleiman@bank.example.ng', 'name' => 'Ibrahim Suleiman'],
            ['email' => 'folake.adeyemi@bank.example.ng', 'name' => 'Folake Adeyemi'],
        ];

        $recipients = 0;
        $responses = 0;

        foreach ($apps->values() as $i => $app) {
            $subscription = Subscription::forEntity(EaApplication::class, $app->id)->approvers()->with('user')->first();

            $targets = [];
            if ($subscription?->user) {
                $targets[] = [
                    'user_id' => $subscription->user_id,
                    'email' => $subscription->user->email,
                    'name' => $subscription->user->name,
                    'role' => $subscription->role,
                ];
            }
            if ($i % 4 === 0) {
                $contact = $unlicensed[$i % count($unlicensed)];
                $targets[] = ['user_id' => null, 'email' => $contact['email'], 'name' => $contact['name'], 'role' => null];
            }

            foreach ($targets as $t) {
                $submitted = ($recipients % 3 === 0);

                SurveyResponse::create([
                    'campaign_id' => $campaign->id,
                    'entity_type' => EaApplication::class,
                    'entity_id' => $app->id,
                    'recipient_user_id' => $t['user_id'],
                    'recipient_email' => $t['email'],
                    'recipient_name' => $t['name'],
                    'recipient_role' => $t['role'],
                    'token' => SurveyResponse::newToken(),
                    'state' => $submitted
                        ? SurveyResponse::SUBMITTED
                        : ($recipients % 3 === 1 ? SurveyResponse::OPENED : SurveyResponse::PENDING),
                    'answers' => $submitted
                        ? ['criticality' => $app->criticality, 'lifecycle' => $app->lifecycle]
                        : null,
                    'opened_at' => $recipients % 3 !== 2 ? now()->subDays(2) : null,
                    'submitted_at' => $submitted ? now()->subDays(1) : null,
                    'reminders_sent' => $submitted ? 0 : ($recipients % 2),
                    'last_reminded_at' => (! $submitted && $recipients % 2) ? now()->subDays(1) : null,
                ]);

                $recipients++;
                if ($submitted) {
                    $responses++;
                }
            }
        }

        $campaign->update([
            'recipients_count' => $recipients,
            'entities_count' => $apps->count(),
            'responses_count' => $responses,
        ]);
    }

    private function seedDecisionRecords($users): void
    {
        $decider = $users->first()->name;

        $records = [
            [
                'code' => 'ADR-0001',
                'title' => 'Adopt TOGAF 9.2 as the architecture framework of record',
                'context' => 'The CBN Nigeria Financial Services IT Standards Blueprint v2.1 names TOGAF version 9.2 specifically and sets a target architecture maturity level by institution category. TOGAF 10 is the current release, but the regulator has not adopted it.',
                'decision' => 'We will build the repository, the ADM phase tracking and the maturity assessment against TOGAF 9.2, and treat TOGAF 10 as an alternate framework pack rather than the default.',
                'alternatives' => 'Adopt TOGAF 10 and map back to 9.2 at assessment time. Rejected: the mapping would have to be maintained by hand and defended to an assessor.',
                'consequences' => 'Assessment evidence lines up with what the IT Standards Governance Council names. Cost: we carry a superseded framework version, and any TOGAF 10 tooling needs a translation layer.',
                'status' => DecisionRecord::ACCEPTED,
                'driver' => 'Regulatory',
                'decided_on' => now()->subMonths(7)->toDateString(),
            ],
            [
                'code' => 'ADR-0002',
                'title' => 'Ownership is modelled as a relationship, not an attribute',
                'context' => 'Owner was a free-text column on each record. It could not be queried, could not route a notification, and could not answer "who signs off on this".',
                'decision' => 'Introduce a subscription object carrying Responsible / Accountable / Consulted / Observer against any architecture entity. Survey audiences, quality-seal approval rights and notification routing all resolve through it.',
                'alternatives' => 'A single owner_user_id foreign key per table. Rejected: it cannot express accountable-versus-responsible, and every new entity type would need its own column.',
                'consequences' => 'Ownership becomes measurable and the freshness mechanics have an identity spine. Cost: a polymorphic join table with no database-level referential integrity, so the application layer has to clean up on delete.',
                'status' => DecisionRecord::ACCEPTED,
                'driver' => 'Capability',
                'decided_on' => now()->subMonths(2)->toDateString(),
            ],
            [
                'code' => 'ADR-0003',
                'title' => 'Survey responses are accepted from unauthenticated magic links',
                'context' => 'The people who know whether an application record is accurate are business owners who will never hold a platform licence. Requiring an account caps response rates at the licensed population.',
                'decision' => 'Issue a 64-character token per recipient per record. The token authorises writing only the attributes the survey declared, on that one record, until it is used or the campaign closes.',
                'alternatives' => 'Require SSO for respondents. Rejected: it reproduces the incumbent ceiling and would have made the freshness model unusable in the exact banks we are targeting.',
                'consequences' => 'Response rates are no longer bounded by licence count. Cost: an unauthenticated write path exists and has to stay narrowly scoped, which constrains how surveys can evolve.',
                'status' => DecisionRecord::ACCEPTED,
                'driver' => 'Risk',
                'decided_on' => now()->subMonth()->toDateString(),
            ],
            [
                'code' => 'ADR-0004',
                'title' => 'Defer user-definable metamodel types',
                'context' => 'Competitors allow customers to define their own entity types. Reviews of the most flexible product report inconsistent models across teams, and the Nigerian market has roughly 49 enterprise architect roles nationally.',
                'decision' => 'Ship an opinionated, CBN-shaped metamodel. Allow custom fields; do not allow custom types.',
                'alternatives' => 'A fully user-definable metamodel. Rejected as actively harmful given architect scarcity.',
                'consequences' => 'Faster time-to-value and consistent regulatory mapping. Cost: a customer with a genuinely unusual object has to wait for us to model it.',
                'status' => DecisionRecord::PROPOSED,
                'driver' => 'Capability',
                'decided_on' => null,
            ],
        ];

        foreach ($records as $record) {
            DecisionRecord::updateOrCreate(
                ['code' => $record['code']],
                $record + ['decided_by' => $record['decided_on'] ? $decider : null],
            );
        }

        // Demonstrate the supersession chain rather than just describing it.
        $superseded = DecisionRecord::where('code', 'ADR-0001')->first();
        if ($superseded && ! DecisionRecord::where('code', 'ADR-0005')->exists()) {
            DecisionRecord::create([
                'code' => 'ADR-0005',
                'title' => 'Keep TOGAF 9.2 as primary, add a TOGAF 10 content pack',
                'context' => 'Two design-partner conversations asked for TOGAF 10 artefacts alongside the 9.2 assessment evidence.',
                'decision' => 'TOGAF 9.2 remains the framework of record for regulatory assessment. TOGAF 10 ships as an installable content pack for teams that want it.',
                'consequences' => 'Both audiences are served. Cost: two framework packs to maintain.',
                'status' => DecisionRecord::PROPOSED,
                'driver' => 'Regulatory',
                'supersedes_id' => $superseded->id,
            ]);
        }
    }
}

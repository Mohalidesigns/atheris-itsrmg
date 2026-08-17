<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Initiative;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Process;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ValueStream;

/**
 * EntityRegistry — the single description of every EA object that can be owned,
 * sealed and surveyed.
 *
 * Phase 1 introduces three cross-cutting mechanics (subscriptions, quality
 * seals, surveys) that all need the same three facts about an entity type: how
 * to label it, how to address it, and which of its attributes matter. Without a
 * registry each mechanic grows its own copy of that list and they drift.
 *
 * `mandatory` drives two things at once, per §5.4 B3: the completeness score,
 * and the gate on approving a seal. `surveyable` is the subset a form builder
 * may write to — deliberately narrower than `mandatory`, because a survey
 * addressed to a business owner should not invite them to rewrite a code or a
 * primary key.
 */
class EntityRegistry
{
    /**
     * @return array<class-string, array{
     *   label:string, plural:string, route:?string, title:string,
     *   mandatory:array<string,string>, optional:array<string,string>,
     *   surveyable:array<string,array{label:string,type:string,options?:array}>
     * }>
     */
    public static function all(): array
    {
        return [
            EaApplication::class => [
                'label' => 'Application',
                'plural' => 'Applications',
                'route' => 'ea.applications',
                'showRoute' => 'ea.applications.show',
                'title' => 'name',
                'mandatory' => [
                    'name' => 'Name',
                    'criticality' => 'Criticality',
                    'lifecycle' => 'Lifecycle',
                    'owner_role' => 'Owner role',
                    'business_fit' => 'Business fit',
                    'technical_fit' => 'Technical fit',
                ],
                'optional' => [
                    'description' => 'Description',
                    'time_score' => 'TIME score',
                    'annual_cost_ngn' => 'Annual cost',
                    'user_count' => 'User count',
                    'capability_ids' => 'Supported capabilities',
                ],
                'surveyable' => [
                    'criticality' => ['label' => 'How critical is this application to the business?', 'type' => 'select', 'options' => ['critical', 'high', 'medium', 'low']],
                    'lifecycle' => ['label' => 'Where is it in its lifecycle?', 'type' => 'select', 'options' => ['plan', 'build', 'live', 'sunset', 'retired']],
                    'business_fit' => ['label' => 'Business fit (1 = poor, 5 = excellent)', 'type' => 'number'],
                    'technical_fit' => ['label' => 'Technical fit (1 = poor, 5 = excellent)', 'type' => 'number'],
                    'owner_role' => ['label' => 'Who owns this application?', 'type' => 'text'],
                    'user_count' => ['label' => 'Roughly how many people use it?', 'type' => 'number'],
                    'annual_cost_ngn' => ['label' => 'Annual cost (₦)', 'type' => 'number'],
                    'description' => ['label' => 'What does it do?', 'type' => 'textarea'],
                ],
            ],

            Capability::class => [
                'label' => 'Capability',
                'plural' => 'Capabilities',
                'route' => 'ea.capabilities',
                'showRoute' => 'ea.capabilities.show',
                'title' => 'name',
                'mandatory' => [
                    'name' => 'Name',
                    'criticality' => 'Criticality',
                    'maturity' => 'Maturity',
                    'owner_role' => 'Owner role',
                ],
                'optional' => [
                    'description' => 'Description',
                    'last_verified_at' => 'Last verified',
                ],
                'surveyable' => [
                    'criticality' => ['label' => 'How critical is this capability?', 'type' => 'select', 'options' => ['critical', 'high', 'medium', 'low']],
                    'maturity' => ['label' => 'Maturity today (1–5)', 'type' => 'number'],
                    'owner_role' => ['label' => 'Who owns this capability?', 'type' => 'text'],
                    'description' => ['label' => 'Describe the capability', 'type' => 'textarea'],
                ],
            ],

            TechComponent::class => [
                'label' => 'Technology component',
                'plural' => 'Technology components',
                'route' => 'ea.technology-radar',
                'showRoute' => null,
                'title' => 'name',
                'mandatory' => [
                    'name' => 'Name',
                    'category' => 'Category',
                    'vendor' => 'Vendor',
                    'version' => 'Version',
                    'radar_status' => 'Radar ring',
                    'eol_date' => 'End of life',
                ],
                'optional' => ['cpe' => 'CPE', 'eos_date' => 'End of support'],
                'surveyable' => [
                    'version' => ['label' => 'Which version is actually deployed?', 'type' => 'text'],
                    'vendor' => ['label' => 'Vendor', 'type' => 'text'],
                    'radar_status' => ['label' => 'Recommended ring', 'type' => 'select', 'options' => ['adopt', 'trial', 'assess', 'hold']],
                    'eol_date' => ['label' => 'Known end-of-life date', 'type' => 'date'],
                ],
            ],

            EaInterface::class => [
                'label' => 'Connection',
                'plural' => 'Connections',
                'route' => 'ea.interfaces',
                'showRoute' => null,
                'title' => 'name',
                'mandatory' => [
                    'name' => 'Name',
                    // The CBN RBCF App. II §1.1(j) field. Making it mandatory
                    // here means an unanswered objective visibly drags the
                    // completeness score down and blocks the seal.
                    'objective' => 'Connection objective',
                    'counterparty_type' => 'Counterparty type',
                    'protocol' => 'Protocol',
                    'review_cadence' => 'Review cadence',
                ],
                'optional' => [
                    'classification' => 'Data classification',
                    'last_reviewed_at' => 'Last reviewed',
                ],
                'surveyable' => [
                    'objective' => ['label' => 'What is the objective of this connection?', 'type' => 'textarea'],
                    'counterparty_type' => ['label' => 'Who is on the other end?', 'type' => 'select', 'options' => ['internal', 'regulator', 'switch', 'third_party']],
                    'protocol' => ['label' => 'Protocol', 'type' => 'text'],
                    'pii_carrying' => ['label' => 'Does it carry personal data?', 'type' => 'select', 'options' => ['1', '0']],
                    'last_reviewed_at' => ['label' => 'When was it last reviewed?', 'type' => 'date'],
                ],
            ],

            LogicalEntity::class => [
                'label' => 'Logical entity',
                'plural' => 'Logical entities',
                'route' => 'ea.logical-entities',
                'showRoute' => null,
                'title' => 'name',
                'mandatory' => [
                    'name' => 'Name',
                    'classification' => 'Classification',
                    'owner_role' => 'Data steward',
                ],
                'optional' => ['description' => 'Description'],
                'surveyable' => [
                    'classification' => ['label' => 'Data classification', 'type' => 'text'],
                    'owner_role' => ['label' => 'Who is the data steward?', 'type' => 'text'],
                ],
            ],

            Process::class => [
                'label' => 'Business process',
                'plural' => 'Business processes',
                'route' => 'ea.processes',
                'showRoute' => null,
                'title' => 'name',
                'mandatory' => [
                    'name' => 'Name',
                    'criticality' => 'Criticality',
                    'rto_hours' => 'RTO (hours)',
                    'rpo_hours' => 'RPO (hours)',
                ],
                'optional' => ['description' => 'Description'],
                'surveyable' => [
                    'rto_hours' => ['label' => 'Recovery time objective, in hours', 'type' => 'number'],
                    'rpo_hours' => ['label' => 'Recovery point objective, in hours', 'type' => 'number'],
                    'criticality' => ['label' => 'Criticality', 'type' => 'select', 'options' => ['critical', 'high', 'medium', 'low']],
                ],
            ],

            ValueStream::class => [
                'label' => 'Value stream',
                'plural' => 'Value streams',
                'route' => 'ea.value-streams',
                'showRoute' => null,
                'title' => 'name',
                'mandatory' => ['name' => 'Name', 'owner_role' => 'Owner role'],
                'optional' => ['description' => 'Description'],
                'surveyable' => [
                    'owner_role' => ['label' => 'Who owns this value stream?', 'type' => 'text'],
                ],
            ],

            DataFlow::class => [
                'label' => 'Data flow',
                'plural' => 'Data flows',
                'route' => 'ea.data-flows',
                'showRoute' => null,
                'title' => 'name',
                'mandatory' => ['name' => 'Name', 'classification' => 'Classification'],
                'optional' => [],
                'surveyable' => [
                    'classification' => ['label' => 'Data classification', 'type' => 'text'],
                ],
            ],

            Initiative::class => [
                'label' => 'Initiative',
                'plural' => 'Initiatives',
                'route' => 'ea.initiatives',
                'showRoute' => null,
                'title' => 'name',
                'mandatory' => [
                    'name' => 'Name',
                    'status' => 'Status',
                    'start_date' => 'Start date',
                    'target_end_date' => 'Target end date',
                ],
                'optional' => ['budget_ngn' => 'Budget', 'description' => 'Description'],
                'surveyable' => [
                    'progress_percent' => ['label' => 'Percent complete', 'type' => 'number'],
                    'status' => ['label' => 'Current status', 'type' => 'select', 'options' => ['proposed', 'approved', 'in_flight', 'delivered', 'on_hold', 'cancelled']],
                    'target_end_date' => ['label' => 'Revised target end date', 'type' => 'date'],
                ],
            ],
        ];
    }

    /** @return array<int, class-string> */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    public static function supports(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::all());
    }

    public static function definition(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function label(string $type): string
    {
        return self::definition($type)['label'] ?? class_basename($type);
    }

    /** Options for a type picker in the UI. */
    public static function options(): array
    {
        return collect(self::all())
            ->map(fn ($def, $type) => ['value' => $type, 'label' => $def['plural']])
            ->values()
            ->all();
    }

    /** Resolve an entity instance, or null if the type is not registered. */
    public static function find(string $type, int|string $id): ?\Illuminate\Database\Eloquent\Model
    {
        if (! self::supports($type)) {
            return null;
        }

        return $type::find($id);
    }

    /** A short human label for one record — used in survey and task lists. */
    public static function describe(string $type, int|string $id): string
    {
        $model = self::find($type, $id);
        if (! $model) {
            return self::label($type).' #'.$id;
        }

        $titleField = self::definition($type)['title'] ?? 'name';
        $code = $model->code ?? null;
        $title = $model->{$titleField} ?? ('#'.$id);

        return $code ? "{$code} — {$title}" : (string) $title;
    }
}

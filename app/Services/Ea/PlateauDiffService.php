<?php

namespace App\Services\Ea;

use App\Models\Ea\ApplicationCapability;
use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Initiative;
use App\Models\Ea\Plateau;
use App\Models\Ea\PlateauEntity;
use App\Models\Ea\TechComponent;
use App\Models\Risk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PlateauDiffService — scenario authoring and comparison (WS 4.3 / B15).
 *
 * §5.1 deleted the old page outright: "`Scenarios.jsx` in current form — 93 LOC
 * read-only comparer with no authoring. Shipping a 'Scenario Compare' that
 * cannot create a scenario invites the comparison to Ardoq we lose. Delete the
 * page; keep `ScenarioComparer`. Rebuild in Phase 4 as **Plateau diff** inside
 * the Roadmap workspace."
 *
 * §11 wedge 3 explains why it earns its 8 days: "33 recapitalised banks, mergers
 * in flight. Offer a fixed-scope estate mapping sprint as paid discovery."
 * Post-merger rationalisation is a *diff* — two estates, one target, and a board
 * that wants the cost, risk and count deltas before it approves the programme.
 *
 * Membership comes from `ea_plateau_entities` with a disposition, which is what
 * makes authoring possible. {@see ScenarioComparer} — which reads the single
 * `plateau_id` column — is kept for the legacy screen and is strictly weaker:
 * one entity can only sit in one plateau there, so nothing can be compared
 * without duplicating records.
 */
class PlateauDiffService
{
    /** Entity types a plateau can hold, in the order the diff reports them. */
    public const TYPES = [
        EaApplication::class => 'Applications',
        TechComponent::class => 'Technology components',
        Capability::class => 'Capabilities',
    ];

    public function __construct(
        private CostModelService $cost = new CostModelService(),
    ) {
    }

    /* ===================== authoring ===================== */

    /**
     * Seed a plateau's membership from the live estate — the first thing anyone
     * does when creating a target scenario, because a target state is an edit of
     * today, not a blank sheet.
     *
     * @return array<string,int> rows created per type
     */
    public function seedFromCurrentEstate(Plateau $plateau, ?array $types = null, string $disposition = 'retain'): array
    {
        $types = $types ?: array_keys(self::TYPES);
        $created = [];

        foreach ($types as $type) {
            $ids = $type::query()
                ->when($type === EaApplication::class, fn ($q) => $q->whereNotIn('lifecycle', ['retired']))
                ->pluck('id');

            $rows = [];
            foreach ($ids as $id) {
                $rows[] = [
                    'organization_id' => $plateau->organization_id,
                    'plateau_id' => $plateau->id,
                    'entity_type' => $type,
                    'entity_id' => (int) $id,
                    'disposition' => $disposition,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('ea_plateau_entities')->insertOrIgnore($chunk);
            }
            $created[class_basename($type)] = count($rows);
        }

        return $created;
    }

    /**
     * Copy one plateau's membership into another — "clone the target and try a
     * cheaper variant", the workflow a scenario comparison exists to support.
     */
    public function clone(Plateau $from, Plateau $to): int
    {
        $rows = [];
        foreach (PlateauEntity::where('plateau_id', $from->id)->get() as $row) {
            $rows[] = [
                'organization_id' => $to->organization_id,
                'plateau_id' => $to->id,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'disposition' => $row->disposition,
                'replaced_by_id' => $row->replaced_by_id,
                'target_annual_cost' => $row->target_annual_cost,
                'target_cost_currency' => $row->target_cost_currency,
                'one_off_cost' => $row->one_off_cost,
                'confidence' => $row->confidence,
                'rationale' => $row->rationale,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ea_plateau_entities')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    /** Set (or change) the disposition of one entity in one plateau. */
    public function setDisposition(Plateau $plateau, string $entityType, int $entityId, string $disposition, array $extra = []): PlateauEntity
    {
        if (! in_array($disposition, PlateauEntity::DISPOSITIONS, true)) {
            throw new \InvalidArgumentException(
                "'{$disposition}' is not a disposition. Use one of: ".implode(', ', PlateauEntity::DISPOSITIONS).'.'
            );
        }

        if ($disposition === 'replace' && empty($extra['replaced_by_id'])) {
            throw new \InvalidArgumentException(
                'A `replace` disposition needs the successor it is replaced by — otherwise the target state has a gap where a capability used to be.'
            );
        }

        return PlateauEntity::updateOrCreate(
            ['plateau_id' => $plateau->id, 'entity_type' => $entityType, 'entity_id' => $entityId],
            array_merge([
                'organization_id' => $plateau->organization_id,
                'disposition' => $disposition,
            ], array_intersect_key($extra, array_flip([
                'replaced_by_id', 'target_annual_cost', 'target_cost_currency',
                'one_off_cost', 'confidence', 'rationale',
            ])))
        );
    }

    /** Bulk disposition — the rationalisation workflow acts on a set, not a row. */
    public function setDispositions(Plateau $plateau, string $entityType, array $entityIds, string $disposition, array $extra = []): int
    {
        $count = 0;
        foreach ($entityIds as $id) {
            $this->setDisposition($plateau, $entityType, (int) $id, $disposition, $extra);
            $count++;
        }

        return $count;
    }

    /* ===================== the diff ===================== */

    /**
     * Compare two plateaux.
     *
     * @return array<string,mixed>
     */
    public function diff(Plateau $from, Plateau $to): array
    {
        $fromMembers = $this->membership($from);
        $toMembers = $this->membership($to);

        $byType = [];
        foreach (self::TYPES as $type => $label) {
            $byType[$type] = $this->diffType($type, $label, $fromMembers, $toMembers, $from, $to);
        }

        $cost = $this->costDelta(EaApplication::class, $fromMembers, $toMembers);
        $risk = $this->riskDelta($fromMembers, $toMembers);
        $capability = $this->capabilityCoverageDelta($fromMembers, $toMembers);
        $residency = $this->residencyDelta($fromMembers, $toMembers);
        $debt = $this->debtDelta($fromMembers, $toMembers);

        $initiatives = $this->initiativeBridge($from, $to);

        return [
            'from' => $this->plateauHeader($from, $fromMembers),
            'to' => $this->plateauHeader($to, $toMembers),
            'entities' => array_values($byType),
            'cost' => $cost,
            'risk' => $risk,
            'debt' => $debt,
            'capability_coverage' => $capability,
            'residency' => $residency,
            'initiatives' => $initiatives,
            'summary' => [
                'added' => array_sum(array_map(fn ($t) => count($t['added']), $byType)),
                'removed' => array_sum(array_map(fn ($t) => count($t['removed']), $byType)),
                'retained' => array_sum(array_map(fn ($t) => count($t['retained']), $byType)),
                'changed' => array_sum(array_map(fn ($t) => count($t['changed']), $byType)),
                'annual_cost_delta_ngn' => $cost['delta_ngn'],
                'one_off_cost_ngn' => $cost['one_off_ngn'],
                'risk_delta' => $risk['delta'],
                'debt_delta' => $debt['delta'],
                'capabilities_gained' => count($capability['gained']),
                'capabilities_lost' => count($capability['lost']),
                // The honesty line, same as everywhere else in the module: a
                // delta computed over an estate where half the costs are blank
                // is a partial delta and must say so.
                'cost_confidence_percent' => $cost['confidence_percent'],
            ],
        ];
    }

    /**
     * Membership rows for a plateau, keyed by "Class|id".
     *
     * Falls back to the legacy `plateau_id` column for entities with no explicit
     * membership row, so a plateau created before Phase 4 still diffs.
     */
    private function membership(Plateau $plateau): Collection
    {
        $rows = PlateauEntity::where('plateau_id', $plateau->id)->get()
            ->keyBy(fn ($row) => $row->entity_type.'|'.$row->entity_id);

        foreach (array_keys(self::TYPES) as $type) {
            foreach ($type::where('plateau_id', $plateau->id)->pluck('id') as $id) {
                $key = $type.'|'.$id;
                if (! $rows->has($key)) {
                    $rows->put($key, new PlateauEntity([
                        'plateau_id' => $plateau->id,
                        'entity_type' => $type,
                        'entity_id' => (int) $id,
                        'disposition' => 'retain',
                    ]));
                }
            }
        }

        return $rows;
    }

    /** Ids present in a plateau, for one type. */
    private function presentIds(Collection $members, string $type): array
    {
        return $members
            ->filter(fn ($row) => $row->entity_type === $type && $row->isPresent())
            ->map(fn ($row) => (int) $row->entity_id)
            ->values()->all();
    }

    private function diffType(string $type, string $label, Collection $fromMembers, Collection $toMembers, Plateau $from, Plateau $to): array
    {
        $fromIds = $this->presentIds($fromMembers, $type);
        $toIds = $this->presentIds($toMembers, $type);

        $addedIds = array_values(array_diff($toIds, $fromIds));
        $removedIds = array_values(array_diff($fromIds, $toIds));
        $retainedIds = array_values(array_intersect($fromIds, $toIds));

        // "Changed" is a retained entity whose disposition or target cost differs
        // between the two plateaux — a modernisation, not a replacement.
        $changed = [];
        foreach ($retainedIds as $id) {
            $before = $fromMembers->get($type.'|'.$id);
            $after = $toMembers->get($type.'|'.$id);
            if (! $before || ! $after) {
                continue;
            }
            $deltas = [];
            if ($before->disposition !== $after->disposition) {
                $deltas['disposition'] = [$before->disposition, $after->disposition];
            }
            if ((float) $before->target_annual_cost !== (float) $after->target_annual_cost) {
                $deltas['target_annual_cost'] = [$before->target_annual_cost, $after->target_annual_cost];
            }
            if ($deltas) {
                $changed[] = ['id' => $id] + $this->entityStub($type, $id) + ['changes' => $deltas];
            }
        }

        return [
            'entity_type' => $type,
            'label' => $label,
            'from_count' => count($fromIds),
            'to_count' => count($toIds),
            'count_delta' => count($toIds) - count($fromIds),
            'added' => array_map(fn ($id) => $this->entityStub($type, $id, $toMembers), $addedIds),
            'removed' => array_map(fn ($id) => $this->entityStub($type, $id, $fromMembers), $removedIds),
            'retained' => array_map(fn ($id) => ['id' => $id], $retainedIds),
            'changed' => $changed,
        ];
    }

    private array $stubCache = [];

    private function entityStub(string $type, int $id, ?Collection $members = null): array
    {
        $key = $type.'|'.$id;
        if (! isset($this->stubCache[$key])) {
            $model = $type::withoutGlobalScopes()->find($id);
            $this->stubCache[$key] = $model ? [
                'id' => $id,
                'code' => $model->code ?? null,
                'name' => $model->name ?? "#{$id}",
                'criticality' => $model->criticality ?? null,
                'lifecycle' => $model->lifecycle ?? null,
            ] : ['id' => $id, 'code' => null, 'name' => "#{$id} (missing)", 'criticality' => null, 'lifecycle' => null];
        }

        $stub = $this->stubCache[$key];

        if ($members) {
            $row = $members->get($key);
            $stub['disposition'] = $row->disposition ?? null;
            $stub['rationale'] = $row->rationale ?? null;
            $stub['target_annual_cost'] = $row->target_annual_cost ?? null;
        }

        return $stub;
    }

    /**
     * Annual cost of each plateau's application set, in naira.
     *
     * A plateau's per-entity `target_annual_cost` wins where present; otherwise
     * the entity's own modelled TCO is used. That is the honest default: an
     * unmodified application costs what it costs today.
     */
    private function costDelta(string $type, Collection $fromMembers, Collection $toMembers): array
    {
        $fx = new FxExposureService();

        $sideCost = function (Collection $members) use ($type, $fx) {
            $total = 0.0;
            $unknown = 0;
            $counted = 0;
            $oneOff = 0.0;

            foreach ($members->filter(fn ($row) => $row->entity_type === $type) as $row) {
                $oneOff += (float) ($row->one_off_cost ?? 0);

                if (! $row->isPresent()) {
                    continue;
                }

                if ($row->target_annual_cost !== null) {
                    $converted = $fx->toNgn((float) $row->target_annual_cost, $row->target_cost_currency ?: 'NGN');
                    if ($converted !== null) {
                        $total += $converted;
                        $counted++;

                        continue;
                    }
                }

                $application = EaApplication::withoutGlobalScopes()->find($row->entity_id);
                $tco = $application ? $this->cost->tco($application)['total_ngn'] : null;
                if ($tco === null) {
                    $unknown++;

                    continue;
                }
                $total += $tco;
                $counted++;
            }

            return ['total' => round($total, 2), 'unknown' => $unknown, 'counted' => $counted, 'one_off' => round($oneOff, 2)];
        };

        $before = $sideCost($fromMembers);
        $after = $sideCost($toMembers);

        $totalConsidered = $before['counted'] + $before['unknown'] + $after['counted'] + $after['unknown'];
        $known = $before['counted'] + $after['counted'];

        return [
            'from_ngn' => $before['total'],
            'to_ngn' => $after['total'],
            'delta_ngn' => round($after['total'] - $before['total'], 2),
            'delta_percent' => $before['total'] > 0
                ? round(($after['total'] - $before['total']) / $before['total'] * 100, 1)
                : null,
            'one_off_ngn' => $after['one_off'],
            // Payback in years on the recurring saving, when there is one.
            'payback_years' => ($before['total'] - $after['total']) > 0 && $after['one_off'] > 0
                ? round($after['one_off'] / ($before['total'] - $after['total']), 1)
                : null,
            'without_cost' => ['from' => $before['unknown'], 'to' => $after['unknown']],
            'confidence_percent' => $totalConsidered ? round($known / $totalConsidered * 100, 1) : 100.0,
        ];
    }

    /**
     * Risk delta over the *live* risk register, not a modelled score.
     *
     * I-1 (Phase 2) opens a real `Risk` row for every obsolete technology
     * component, tagged back to the EA entity. Retiring the component therefore
     * closes a countable, auditable risk — which is a far stronger claim to a
     * board than a change in an internal heuristic.
     */
    private function riskDelta(Collection $fromMembers, Collection $toMembers): array
    {
        $fromTech = $this->presentIds($fromMembers, TechComponent::class);
        $toTech = $this->presentIds($toMembers, TechComponent::class);
        $fromApps = $this->presentIds($fromMembers, EaApplication::class);
        $toApps = $this->presentIds($toMembers, EaApplication::class);

        $obsoleteBefore = $fromTech ? TechComponent::whereIn('id', $fromTech)->where('obsolescence_flag', true)->count() : 0;
        $obsoleteAfter = $toTech ? TechComponent::whereIn('id', $toTech)->where('obsolescence_flag', true)->count() : 0;

        $retiredTech = array_values(array_diff($fromTech, $toTech));
        $closable = collect();

        if ($retiredTech && class_exists(Risk::class)) {
            // I-1 mints `EA-OBS-{padded component id}` as the stable identity of
            // an obsolescence risk, so retiring the component tells us exactly
            // which register entries the programme closes.
            $codes = array_map(fn ($id) => 'EA-OBS-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT), $retiredTech);

            $closable = Risk::query()
                ->whereIn('risk_id_code', $codes)
                ->whereNotIn('status', ['closed', 'archived'])
                ->get(['id', 'risk_id_code', 'title', 'inherent_score', 'inherent_rating', 'status']);
        }

        $criticalBefore = $fromApps ? EaApplication::whereIn('id', $fromApps)->where('criticality', 'critical')->count() : 0;
        $criticalAfter = $toApps ? EaApplication::whereIn('id', $toApps)->where('criticality', 'critical')->count() : 0;

        return [
            'obsolete_tech' => ['from' => $obsoleteBefore, 'to' => $obsoleteAfter, 'delta' => $obsoleteAfter - $obsoleteBefore],
            'critical_applications' => ['from' => $criticalBefore, 'to' => $criticalAfter, 'delta' => $criticalAfter - $criticalBefore],
            'risks_closable' => $closable->count(),
            'risks_closable_detail' => $closable->take(20)->values(),
            'delta' => $obsoleteAfter - $obsoleteBefore,
        ];
    }

    /** Mean technical-debt score across each plateau's application set. */
    private function debtDelta(Collection $fromMembers, Collection $toMembers): array
    {
        $mean = function (array $ids) {
            if (! $ids) {
                return null;
            }
            $scores = [];
            foreach (EaApplication::whereIn('id', $ids)->get() as $app) {
                $scores[] = $this->cost->technicalDebt($app)['score'];
            }

            return $scores ? round(array_sum($scores) / count($scores), 1) : null;
        };

        $before = $mean($this->presentIds($fromMembers, EaApplication::class));
        $after = $mean($this->presentIds($toMembers, EaApplication::class));

        return [
            'from' => $before,
            'to' => $after,
            'delta' => $before !== null && $after !== null ? round($after - $before, 1) : null,
        ];
    }

    /**
     * Capability coverage: which capabilities lose their last realising
     * application between the two plateaux.
     *
     * This is the check that stops a rationalisation programme quietly deleting
     * a business capability along with the system that happened to provide it —
     * the single most expensive mistake in a post-merger estate consolidation.
     */
    private function capabilityCoverageDelta(Collection $fromMembers, Collection $toMembers): array
    {
        $links = ApplicationCapability::query()->get(['application_id', 'capability_id']);

        $coverage = function (array $appIds) use ($links) {
            $set = array_flip($appIds);

            return $links->filter(fn ($link) => isset($set[$link->application_id]))
                ->pluck('capability_id')->unique()->map(fn ($i) => (int) $i)->values()->all();
        };

        $before = $coverage($this->presentIds($fromMembers, EaApplication::class));
        $after = $coverage($this->presentIds($toMembers, EaApplication::class));

        $lost = array_values(array_diff($before, $after));
        $gained = array_values(array_diff($after, $before));

        $stub = fn ($ids) => Capability::whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'criticality', 'level'])->values();

        return [
            'from_count' => count($before),
            'to_count' => count($after),
            'lost' => $stub($lost),
            'gained' => $stub($gained),
            // A critical capability losing coverage is a stop-the-programme
            // finding, so it is surfaced separately rather than counted.
            'critical_lost' => Capability::whereIn('id', $lost)
                ->where('criticality', 'critical')->get(['id', 'code', 'name'])->values(),
        ];
    }

    /**
     * Residency posture delta — Phase 3's A2 lens applied to a scenario.
     *
     * "Does this consolidation move Nigerian payment data offshore" is a legal
     * question with a 1 January 2027 deadline (§6.2), and it is exactly the kind
     * of thing a cost-driven target state gets wrong.
     */
    private function residencyDelta(Collection $fromMembers, Collection $toMembers): array
    {
        $posture = function (array $ids) {
            if (! $ids) {
                return ['offshore' => 0, 'offshore_with_payment_data' => 0, 'dr_offshore' => 0, 'unknown' => 0];
            }
            $apps = EaApplication::whereIn('id', $ids)->get();

            return [
                'offshore' => $apps->filter(fn ($a) => $a->hosting_country && $a->hosting_country !== 'NG')->count(),
                'offshore_with_payment_data' => $apps->filter(fn ($a) => $a->contains_nigerian_payment_data
                    && $a->hosting_country && $a->hosting_country !== 'NG')->count(),
                // The trap Phase 3's gap register exists to catch: primary in
                // Nigeria, DR abroad.
                'dr_offshore' => $apps->filter(fn ($a) => $a->dr_country && $a->dr_country !== 'NG')->count(),
                'unknown' => $apps->filter(fn ($a) => ! $a->hosting_country)->count(),
            ];
        };

        $before = $posture($this->presentIds($fromMembers, EaApplication::class));
        $after = $posture($this->presentIds($toMembers, EaApplication::class));

        $delta = [];
        foreach ($before as $key => $value) {
            $delta[$key] = $after[$key] - $value;
        }

        return ['from' => $before, 'to' => $after, 'delta' => $delta];
    }

    /**
     * The initiatives that carry the estate from one plateau to the other —
     * the transition path, and whether it is funded and scheduled.
     */
    private function initiativeBridge(Plateau $from, Plateau $to): array
    {
        $initiatives = Initiative::query()
            ->whereIn('plateau_id', array_filter([$from->id, $to->id]))
            ->get(['id', 'code', 'name', 'status', 'adm_phase', 'plateau_id', 'budget_ngn', 'start_date', 'target_end_date', 'progress_percent']);

        $toInitiatives = $initiatives->where('plateau_id', $to->id);

        return [
            'targeting_to' => $toInitiatives->values(),
            'count' => $toInitiatives->count(),
            'budget_ngn' => round((float) $toInitiatives->sum('budget_ngn'), 2),
            'unfunded' => $toInitiatives->whereNull('budget_ngn')->count(),
            'not_started' => $toInitiatives->where('progress_percent', 0)->count(),
            'latest_end_date' => optional($toInitiatives->max('target_end_date'))
                ? (string) $toInitiatives->max('target_end_date')
                : null,
        ];
    }

    private function plateauHeader(Plateau $plateau, Collection $members): array
    {
        $counts = [];
        foreach (self::TYPES as $type => $label) {
            $counts[class_basename($type)] = count($this->presentIds($members, $type));
        }

        return [
            'id' => $plateau->id,
            'code' => $plateau->code,
            'name' => $plateau->name,
            'plateau_type' => $plateau->plateau_type,
            'effective_from' => optional($plateau->effective_from)->toDateString(),
            'effective_to' => optional($plateau->effective_to)->toDateString(),
            'description' => $plateau->description,
            'counts' => $counts,
            'membership_rows' => $members->count(),
            'dispositions' => $members->groupBy('disposition')->map->count()->all(),
        ];
    }

    /**
     * A plateau's membership as an editable table — the authoring surface.
     */
    public function membershipTable(Plateau $plateau, string $entityType = EaApplication::class): array
    {
        $rows = PlateauEntity::where('plateau_id', $plateau->id)
            ->where('entity_type', $entityType)->get()->keyBy('entity_id');

        $entities = $entityType::query()->orderBy('name')->get();

        return $entities->map(function ($entity) use ($rows, $entityType) {
            $row = $rows->get($entity->id);
            $tco = $entityType === EaApplication::class ? $this->cost->tco($entity)['total_ngn'] : null;

            return [
                'entity_type' => $entityType,
                'entity_id' => $entity->id,
                'code' => $entity->code ?? null,
                'name' => $entity->name,
                'criticality' => $entity->criticality ?? null,
                'lifecycle' => $entity->lifecycle ?? null,
                'current_tco_ngn' => $tco,
                'in_plateau' => $row !== null,
                'disposition' => $row->disposition ?? null,
                'target_annual_cost' => $row->target_annual_cost ?? null,
                'target_cost_currency' => $row->target_cost_currency ?? null,
                'one_off_cost' => $row->one_off_cost ?? null,
                'confidence' => $row->confidence ?? null,
                'rationale' => $row->rationale ?? null,
                'replaced_by_id' => $row->replaced_by_id ?? null,
            ];
        })->values()->all();
    }
}

<?php

namespace App\Listeners\Ea;

use App\Events\Ea\TechComponentBecameObsolete;
use App\Models\Ea\TechComponent;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Services\Ea\AuditLogger;
use App\Services\Ea\OwnershipService;

/**
 * Contract I-1 — Obsolescence → Risk.
 *
 * §7.3: "Upsert a `Risk` with category 'Technology Obsolescence', inherent
 * score from criticality × exposure, back-link to the `TechComponent`, owner
 * from the EA ownership model. Re-runs update rather than duplicate;
 * auto-close when remediated."
 *
 * Status before this: **Absent** — "no reference to `App\Models\Risk` anywhere
 * in `app/Services/Ea`".
 *
 * Idempotency is the whole design problem here. §7.5: "Every listener must be
 * idempotent — I-1 in particular will fire repeatedly against the same
 * component and must update rather than accumulate." The daily
 * RecomputeTechObsolescence job fires this for every flagged component every
 * night; a naive implementation produces 365 duplicate risks a year.
 */
class OpenObsolescenceRisk
{
    public function __construct(private OwnershipService $ownership)
    {
    }

    public function handle(TechComponentBecameObsolete $event): void
    {
        if (! config('ea.contracts.obsolescence_to_risk', true)) {
            return;
        }

        $component = $event->component;

        // The stable identity that makes re-runs idempotent. Deriving it from
        // the component id rather than its name means a rename does not orphan
        // the existing risk and open a second one.
        $code = 'EA-OBS-'.str_pad((string) $component->id, 5, '0', STR_PAD_LEFT);

        $existing = Risk::withoutGlobalScopes()->where('risk_id_code', $code)->first();

        // Remediated: the component is no longer flagged, so close rather than
        // leave a stale open risk. §7.3 — "auto-close when remediated".
        if (! $component->obsolescence_flag) {
            if ($existing && ! in_array($existing->status, ['closed', 'archived'], true)) {
                $existing->update([
                    'status' => 'closed',
                    'description' => $existing->description."\n\n".
                        '[Auto-closed '.now()->toDateString().'] The component is no longer flagged as obsolete.',
                ]);

                AuditLogger::logBare('contract.i1.close', TechComponent::class, $component->id, [
                    'risk' => $code,
                ]);
            }

            return;
        }

        [$likelihood, $impact] = $this->score($component);

        $attributes = [
            'organization_id' => $component->organization_id,
            'title' => "Technology obsolescence: {$component->name}",
            'description' => $this->describe($component),
            'category_id' => $this->categoryId($component->organization_id),
            'status' => 'identified',
            'inherent_likelihood' => $likelihood,
            'inherent_impact' => $impact,
            'inherent_score' => $likelihood * $impact,
            'inherent_rating' => $this->rating($likelihood * $impact),
            // The discriminator §7.4 asks the Risk module to accept, so a
            // machine-created risk is distinguishable from an analyst's.
            'source' => 'ea.obsolescence',
            'tags' => [
                'ea',
                'obsolescence',
                'tech_component:'.$component->id,
                'tech_component_code:'.$component->code,
            ],
            'review_date' => now()->addMonths(3)->toDateString(),
        ];

        if ($existing) {
            // Re-open a previously auto-closed risk rather than creating a
            // second one — the component regressed, the history should show it.
            if (in_array($existing->status, ['closed', 'archived'], true)) {
                $attributes['status'] = 'identified';
            } else {
                unset($attributes['status']);
            }

            // Never overwrite an owner a human assigned.
            $existing->update($attributes);

            return;
        }

        $attributes['risk_id_code'] = $code;
        $attributes['risk_owner_id'] = $this->ownerId($component);

        $risk = Risk::create($attributes);

        AuditLogger::logBare('contract.i1.open', TechComponent::class, $component->id, [
            'risk_id' => $risk->id,
            'risk_code' => $code,
            'inherent_score' => $attributes['inherent_score'],
        ]);
    }

    /**
     * Inherent score from criticality × exposure rather than a fixed number.
     *
     * A flat "medium" on every obsolete component makes the register useless
     * for prioritisation, which is the complaint that gets an auto-created risk
     * feed switched off within a month.
     */
    private function score(TechComponent $component): array
    {
        // Likelihood rises as EOL approaches and passes.
        $daysToEol = $component->eol_date
            ? (int) round(now()->diffInDays($component->eol_date, false))
            : null;

        $likelihood = match (true) {
            $daysToEol === null => 3,
            $daysToEol < 0 => 5,        // already unsupported
            $daysToEol <= 90 => 4,
            $daysToEol <= 365 => 3,
            default => 2,
        };

        // Impact from how much depends on it, and how exposed it is.
        $debt = (float) ($component->tech_debt_score ?? 0);
        $criticalApps = $this->criticalApplicationCount($component);

        $impact = match (true) {
            $criticalApps >= 3 || $debt >= 85 => 5,
            $criticalApps >= 1 || $debt >= 70 => 4,
            $debt >= 50 => 3,
            default => 2,
        };

        return [$likelihood, $impact];
    }

    private function criticalApplicationCount(TechComponent $component): int
    {
        $applicationIds = $component->application_ids ?? [];

        if (empty($applicationIds)) {
            return 0;
        }

        return \App\Models\Ea\EaApplication::withoutGlobalScopes()
            ->whereIn('id', $applicationIds)
            ->whereIn('criticality', ['critical', 'high'])
            ->count();
    }

    private function rating(int $score): string
    {
        return match (true) {
            $score >= 20 => 'critical',
            $score >= 12 => 'high',
            $score >= 6 => 'medium',
            default => 'low',
        };
    }

    private function describe(TechComponent $component): string
    {
        $lines = [
            "Automatically raised by Enterprise Architecture from the technology obsolescence scan.",
            '',
            "Component: {$component->code} — {$component->name}".
                ($component->vendor ? " ({$component->vendor})" : '').
                ($component->version ? " v{$component->version}" : ''),
        ];

        if ($component->eol_date) {
            $lines[] = $component->eol_date->isPast()
                ? "End of life passed on {$component->eol_date->toDateString()} — the component is unsupported."
                : "End of life falls on {$component->eol_date->toDateString()}.";
        }

        if ($component->tech_debt_score !== null) {
            $lines[] = "Technical debt score: {$component->tech_debt_score}/100.";
        }

        $criticalApps = $this->criticalApplicationCount($component);
        if ($criticalApps > 0) {
            $lines[] = "{$criticalApps} critical or high-criticality application(s) depend on it.";
        }

        $lines[] = '';
        $lines[] = 'This risk is maintained by the EA module and will close automatically when the '
            .'component is upgraded or retired. Edit the treatment, not the description.';

        return implode("\n", $lines);
    }

    /** Owner from the EA ownership model (B1), not a free-text guess. */
    private function ownerId(TechComponent $component): ?int
    {
        return $this->ownership
            ->approvers(TechComponent::class, $component->id)
            ->first()?->user_id;
    }

    private function categoryId(?int $organizationId): ?int
    {
        if (! class_exists(RiskCategory::class)) {
            return null;
        }

        $name = config('ea.obsolescence.risk_category', 'Technology Obsolescence');

        // slug/color/sort_order/is_active are NOT NULL on risk_categories, so
        // firstOrCreate needs them in the create half or the listener throws
        // inside a queued job where nobody sees it.
        return RiskCategory::withoutGlobalScopes()
            ->firstOrCreate(
                ['name' => $name, 'organization_id' => $organizationId ?? 1],
                [
                    'slug' => 'technology-obsolescence',
                    'description' => 'Raised automatically by Enterprise Architecture when a technology '
                        .'component passes end of life with no replacement in flight.',
                    'color' => '#B3261E',
                    'sort_order' => 99,
                    'is_active' => true,
                ],
            )->id;
    }
}

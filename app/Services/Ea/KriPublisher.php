<?php

namespace App\Services\Ea;

use App\Models\Ea\KriDefinition;
use App\Models\Ea\KriValue;
use App\Models\Kri;
use App\Models\KriReading;

/**
 * Contract I-8 — EA KRI → Platform KRI.
 *
 * §7.3: "`KriValue` publishes to core `Kri`/`KriReading` so EA metrics appear
 * on board packs and executive dashboards." Status before this: "Absent — EA
 * maintains a parallel KRI model."
 *
 * A parallel KRI model is the quiet version of the duplicate-capability
 * problem: the board sees one set of indicators, the architects another, and
 * nobody notices they disagree until an assessor puts the two packs side by
 * side. Publishing rather than mirroring keeps EA as the computation and the
 * platform KRI register as the single presentation surface.
 *
 * Idempotent on (code, period) — RecomputeKris runs nightly.
 */
class KriPublisher
{
    /** Marks a platform KRI as EA-sourced so the board pack can attribute it. */
    public const CATEGORY = 'Enterprise Architecture';

    /**
     * @return array{definitions:int, readings:int}
     */
    public function publish(): array
    {
        if (! config('ea.contracts.kri_to_platform', true)) {
            return ['definitions' => 0, 'readings' => 0];
        }

        $definitions = 0;
        $readings = 0;

        foreach (KriDefinition::withoutGlobalScopes()->get() as $eaKri) {
            $kri = $this->upsertDefinition($eaKri);

            if (! $kri) {
                continue;
            }

            $definitions++;
            $readings += $this->publishReadings($eaKri, $kri);
        }

        return ['definitions' => $definitions, 'readings' => $readings];
    }

    private function upsertDefinition(KriDefinition $eaKri): ?Kri
    {
        // Prefixed so an EA-published indicator can never collide with one a
        // risk manager created by hand, and so the source stays legible in the
        // register.
        $code = 'EA-'.$eaKri->code;

        return Kri::withoutGlobalScopes()->updateOrCreate(
            ['code' => $code],
            [
                'organization_id' => $eaKri->organization_id,
                'name' => $eaKri->name,
                'description' => 'Published from Enterprise Architecture. Computed by '
                    .'KriCalculator from the architecture repository; edit the definition in EA, not here.',
                'category' => self::CATEGORY,
                'threshold_green' => $eaKri->threshold_green,
                'threshold_amber' => $eaKri->threshold_amber,
                'threshold_red' => $eaKri->threshold_red,
                'direction' => in_array($eaKri->direction, ['higher_worse', 'lower_worse'], true)
                    ? $eaKri->direction
                    : 'higher_worse',
                'unit' => $eaKri->unit ?: 'count',
            ],
        );
    }

    private function publishReadings(KriDefinition $eaKri, Kri $kri): int
    {
        $published = 0;

        $values = KriValue::where('kri_id', $eaKri->id)
            ->orderByDesc('recorded_at')
            // The board pack shows a trend, not the whole history.
            ->limit(24)
            ->get();

        foreach ($values as $value) {
            $reading = KriReading::updateOrCreate(
                ['kri_id' => $kri->id, 'period' => $value->period],
                [
                    'value' => $value->value,
                    'status' => $this->status($value->status),
                    'recorded_at' => $value->recorded_at ?? now(),
                ],
            );

            if ($reading->wasRecentlyCreated || $reading->wasChanged()) {
                $published++;
            }
        }

        return $published;
    }

    /** EA statuses are free-form strings; the platform column is an enum. */
    private function status(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'green', 'ok', 'pass' => 'green',
            'red', 'breach', 'fail', 'critical' => 'red',
            default => 'amber',
        };
    }
}

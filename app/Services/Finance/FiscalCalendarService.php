<?php

namespace App\Services\Finance;

use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fiscal years and accounting periods (ATH-FIN-001 FIN-01.01): 12-month, 13-period and 4-4-5
 * calendars, one current year per bank, and the close then lock sequence for periods.
 * The close checklist and reopen arrive in P4 and wrap closePeriod().
 */
class FiscalCalendarService
{
    /** Allowed source status per period action. */
    public const ADJUSTMENT_NAME = 'Adjustment';

    public const PERIOD_TRANSITIONS = ['close' => ['open'], 'lock' => ['closed']];

    public function createYear(int $orgId, array $data, User $by): FiscalYear
    {
        $start = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        $scheme = $data['period_scheme'] ?? 'monthly';
        $end = $scheme === '4-4-5' ? $start->addDays(363) : $start->addYear()->subDay();

        $overlap = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)
            ->whereDate('starts_on', '<=', $end->toDateString())->whereDate('ends_on', '>=', $start->toDateString())->exists();
        if ($overlap) {
            $this->fail('This fiscal year overlaps another fiscal year of the bank.');
        }
        $status = $data['status'] ?? 'planning';
        $current = (bool) ($data['is_current'] ?? false);
        if ($current && $status !== 'open') {
            $this->fail('Only an open fiscal year can be the current year.');
        }

        return DB::transaction(function () use ($orgId, $data, $by, $start, $end, $scheme, $status, $current) {
            $fy = FiscalYear::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'year_label' => $data['year_label'],
                'starts_on' => $start, 'ends_on' => $end, 'period_scheme' => $scheme, 'status' => $status, 'is_current' => false,
            ]);

            foreach ($this->periodsFor($start, $end, $scheme) as $i => [$name, $from, $to]) {
                Period::withoutGlobalScopes()->create([
                    'organization_id' => $orgId, 'created_by_id' => $by->id, 'fiscal_year_id' => $fy->id,
                    'sequence' => $i + 1, 'name' => $name, 'starts_on' => $from, 'ends_on' => $to, 'status' => 'open',
                    'is_adjustment' => $name === self::ADJUSTMENT_NAME,
                ]);
            }
            if ($current) {
                $this->makeCurrent($fy);
            }

            return $fy->refresh();
        });
    }

    public function updateYear(FiscalYear $fy, array $data): FiscalYear
    {
        $status = $data['status'] ?? $fy->status;
        $current = array_key_exists('is_current', $data) ? (bool) $data['is_current'] : $fy->is_current;

        if ($status !== $fy->status) {
            $allowed = ['planning' => ['open'], 'open' => ['closed'], 'closed' => []][$fy->status] ?? [];
            if (! in_array($status, $allowed, true)) {
                $this->fail("A {$fy->status} fiscal year cannot become {$status}.");
            }
            if ($status === 'closed' && $fy->periods()->where('status', 'open')->exists()) {
                $this->fail('Close every period before closing the fiscal year.');
            }
        }
        if ($current && $status !== 'open') {
            $this->fail('Only an open fiscal year can be the current year.');
        }

        DB::transaction(function () use ($fy, $data, $status, $current) {
            $fy->fill(['year_label' => $data['year_label'] ?? $fy->year_label, 'status' => $status])->save();
            if ($current) {
                $this->makeCurrent($fy);
            } elseif ($fy->is_current) {
                $fy->forceFill(['is_current' => false])->save();
            }
        });

        return $fy->refresh();
    }

    public function makeCurrent(FiscalYear $fy): void
    {
        FiscalYear::withoutGlobalScopes()->where('organization_id', $fy->organization_id)
            ->where('id', '!=', $fy->id)->where('is_current', true)->get()
            ->each(fn (FiscalYear $other) => $other->forceFill(['is_current' => false])->save());
        $fy->forceFill(['is_current' => true])->save();
    }

    public function deleteYear(FiscalYear $fy): void
    {
        if ($fy->status !== 'planning') {
            $this->fail('Only a planning fiscal year can be deleted.');
        }
        if ($fy->periods()->whereIn('status', ['closed', 'locked'])->exists()) {
            $this->fail('A fiscal year with closed periods cannot be deleted.');
        }
        if ($fy->cycles()->withTrashed()->exists() || $fy->budgets()->withTrashed()->exists()) {
            $this->fail('A fiscal year with budget cycles or budgets cannot be deleted.');
        }
        DB::transaction(function () use ($fy) {
            $fy->periods()->delete();
            $fy->delete();
        });
    }

    public function closePeriod(Period $p, User $by): Period
    {
        return DB::transaction(function () use ($p, $by) {
            $p = Period::withoutGlobalScopes()->lockForUpdate()->findOrFail($p->id);
            $this->assertTransition('close', $p);
            $fy = FiscalYear::withoutGlobalScopes()->lockForUpdate()->findOrFail($p->fiscal_year_id);
            if ($fy->status === 'planning') {
                $this->fail('Open the fiscal year before closing its periods.');
            }
            $earlierOpen = Period::withoutGlobalScopes()->where('fiscal_year_id', $p->fiscal_year_id)
                ->where('sequence', '<', $p->sequence)->where('status', 'open')->lockForUpdate()->exists();
            if ($earlierOpen) {
                $this->fail('Close the earlier periods first; periods close in order.');
            }
            if ((int) $p->sequence === 1) {
                $priorOpen = Period::withoutGlobalScopes()->where('organization_id', $p->organization_id)
                    ->where('status', 'open')
                    ->whereIn('fiscal_year_id', FiscalYear::withoutGlobalScopes()->where('organization_id', $p->organization_id)
                        ->where('ends_on', '<', $fy->starts_on)->select('id'))
                    ->lockForUpdate()->exists();
                if ($priorOpen) {
                    $this->fail('Close every period of the prior fiscal year first.');
                }
            }
            $p->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_id' => $by->id])->save();

            return $p->refresh();
        });
    }

    public function lockPeriod(Period $p, User $by): Period
    {
        return DB::transaction(function () use ($p, $by) {
            $p = Period::withoutGlobalScopes()->lockForUpdate()->findOrFail($p->id);
            $this->assertTransition('lock', $p);
            $earlierUnlocked = Period::withoutGlobalScopes()->where('fiscal_year_id', $p->fiscal_year_id)
                ->where('sequence', '<', $p->sequence)->where('status', '!=', 'locked')->lockForUpdate()->exists();
            if ($earlierUnlocked) {
                $this->fail('Lock the earlier periods first; periods lock in order.');
            }
            $p->forceFill(['status' => 'locked', 'locked_at' => now(), 'locked_by_id' => $by->id])->save();

            return $p->refresh();
        });
    }

    /** The regular period containing a date; the 13th adjustment period is never matched by date. */
    public function periodFor(int $orgId, CarbonInterface $date): ?Period
    {
        return Period::withoutGlobalScopes()->where('organization_id', $orgId)->containing($date)->first();
    }

    /** @return array<int, array{0:string,1:CarbonImmutable,2:CarbonImmutable}> */
    private function periodsFor(CarbonImmutable $start, CarbonImmutable $end, string $scheme): array
    {
        $out = [];
        if ($scheme === '4-4-5') {
            $weeks = [4, 4, 5, 4, 4, 5, 4, 4, 5, 4, 4, 5];
            $from = $start;
            foreach ($weeks as $i => $w) {
                $to = $from->addWeeks($w)->subDay();
                $out[] = [sprintf('P%02d', $i + 1), $from, $to];
                $from = $to->addDay();
            }

            return $out;
        }

        $from = $start;
        for ($i = 0; $i < 12; $i++) {
            $to = $start->addMonthsNoOverflow($i + 1)->subDay();
            $out[] = [$from->format('M Y'), $from, $to];
            $from = $to->addDay();
        }
        if ($scheme === 'monthly_13') {
            $out[] = [self::ADJUSTMENT_NAME, $end, $end];
        }

        return $out;
    }

    private function assertTransition(string $action, Period $p): void
    {
        if (! in_array($p->status, self::PERIOD_TRANSITIONS[$action], true)) {
            $this->fail("A {$p->status} period cannot be {$action}d.");
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }
}

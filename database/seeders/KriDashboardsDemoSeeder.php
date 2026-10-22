<?php

namespace Database\Seeders;

use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Models\Kri;
use App\Models\KriBreach;
use App\Models\Organization;
use App\Models\User;
use App\Services\Kri\KriMeasures;
use App\Services\Kri\KriService;
use App\Services\Reports\BoardPackBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * KRIs & Dashboards demo data (ATH-VAL-014). Runs after ContinuousMonitoringDemoSeeder.
 *
 * Per bank, through the real services:
 * - the 30-indicator Nigerian KRI pack (First Bank had none), each with an owner, the risk it
 *   indicates and a reporting frequency; the 12 the platform can measure are marked "platform";
 * - readings replace the platform seeder's random ones: manual KRIs get a deterministic history
 *   (most within appetite, some amber, a few red), platform KRIs are measured from the bank's
 *   records; statuses come from the thresholds, breaches open and close with the readings, and red
 *   breaches raise issues; most open breaches have the owner's action plan, two do not;
 * - two monthly KRIs are left without a recent reading (deliberate gaps);
 * - board packs: last month's BAC pack approved, this month's CRO pack approved, the CISO weekly
 *   pack still a draft. The fake PPTX/PDF runs are gone (migration 2027_01_23).
 */
class KriDashboardsDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'security' => 'soc.analyst', 'approver' => 'admin', 'ciso_alt' => 'sysadmin'],
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'security' => 'soc', 'approver' => 'ciso', 'ciso_alt' => 'sysadmin'],
    ];

    /** Category => owner mailbox (falls back to the CRO). */
    private const OWNERS = [
        'channel' => 'digital', 'payments' => 'cro', 'fraud' => 'risk.analyst', 'infrastructure' => 'itops', 'vulnerability' => 'SECURITY',
        'iam' => 'SECURITY', 'governance' => 'policy', 'incident' => 'incident', 'tprm' => 'tprm', 'compliance' => 'compliance',
        'resilience' => 'itops', 'security' => 'SECURITY', 'data' => 'dpco', 'operations' => 'itops', 'finance' => 'cfo',
    ];

    /** KRI => risk-title fragments, first match wins. */
    private const RISKS = [
        'KRI-CH-ATM' => ['ATM'], 'KRI-CH-USSD' => ['USSD'], 'KRI-CH-MB' => ['SIM swap', 'DDoS'], 'KRI-PAY-NIP' => ['NIP'],
        'KRI-PAY-CARD' => ['Interswitch'], 'KRI-FRAUD-BEC' => ['BEC', 'SWIFT'], 'KRI-FRAUD-CARD' => ['skimming'], 'KRI-IT-EOL' => ['AIX'],
        'KRI-IT-PATCH' => ['Unpatched'], 'KRI-IT-PRIV' => ['privileged'], 'KRI-IT-MFA' => ['MFA', 'privileged'], 'KRI-IT-STALE' => ['privileged'],
        'KRI-POL-ATT' => ['attestation'], 'KRI-POL-EXP' => ['attestation'], 'KRI-INC-OPEN' => ['ansomware'], 'KRI-INC-MTTR' => ['ansomware'],
        'KRI-VUL-KEV' => ['Unpatched'], 'KRI-VUL-CRIT' => ['Unpatched'], 'KRI-TPR-GRADE' => ['vendor DDQ'], 'KRI-TPR-BREACH' => ['Interswitch'],
        'KRI-COMP-CBN' => ['CBN'], 'KRI-COMP-NDPC' => ['NDPA'], 'KRI-BCP-RTO' => ['DR cutover'], 'KRI-BCP-BACK' => ['backup', 'DR cutover'],
        'KRI-CYB-EMAIL' => ['hishing'], 'KRI-CYB-SIEM' => ['ansomware'], 'KRI-DPR-DLP' => ['DLP', 'BVN'], 'KRI-DPR-MASK' => ['BVN'],
        'KRI-OPS-CHG' => ['regression', 'DR cutover'], 'KRI-OPS-OUT' => ['outage'],
    ];

    private const WEEKLY = ['channel', 'payments', 'fraud', 'security'];

    /** Deliberately left without a recent reading. */
    private const STALE = ['KRI-COMP-NDPC', 'KRI-DPR-MASK'];

    public function run(): void
    {
        $template = Kri::withoutGlobalScopes()->where('organization_id', Organization::where('slug', 'kano-heritage-bank')->value('id'))
            ->where('code', 'like', 'KRI-%')->orderBy('code')->get();
        if ($template->isEmpty()) {
            return;
        }

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || DB::table('kri_readings')->where('organization_id', $org->id)->whereNotNull('recorded_by')->exists()) {
                continue;
            }
            $person = fn (string $local) => User::where('email', ($local === 'SECURITY' ? $bank['security'] : $local)."@{$bank['domain']}")->first();
            $cro = $person('cro');

            $kris = $this->definitions($org->id, $template, $person, $cro);
            $this->readings($org->id, $kris);
            $this->boardPacks($org->id, $bank, $person, $cro);
        }
    }

    private function definitions(int $orgId, $template, callable $person, ?User $cro)
    {
        $measures = app(KriMeasures::class);
        $risks = DB::table('risks')->where('organization_id', $orgId)->whereNull('deleted_at')->whereNotIn('status', ['closed', 'archived'])->get(['id', 'title']);

        return $template->map(function (Kri $t) use ($orgId, $person, $cro, $measures, $risks) {
            $riskId = collect(self::RISKS[$t->code] ?? [])->map(fn ($f) => $risks->first(fn ($r) => str_contains($r->title, $f))?->id)->filter()->first();

            return Kri::withoutGlobalScopes()->updateOrCreate(['organization_id' => $orgId, 'code' => $t->code], [
                'name' => $t->code === 'KRI-IT-EOL' ? 'End-of-life assets in service' : $t->name,
                'description' => $t->code === 'KRI-IT-EOL' ? 'Active assets past their end-of-life date (asset register).' : $t->description,
                'category' => $t->category, 'unit' => $t->unit, 'direction' => $t->direction,
                'threshold_green' => $t->threshold_green, 'threshold_amber' => $t->threshold_amber, 'threshold_red' => $t->threshold_red,
                'frequency' => in_array($t->category, self::WEEKLY, true) ? 'weekly' : 'monthly',
                'source' => $measures->supports($t->code) ? 'platform' : 'manual',
                'owner_id' => ($person(self::OWNERS[$t->category] ?? 'cro') ?? $cro)?->id,
                'risk_id' => $riskId,
            ]);
        });
    }

    private function readings(int $orgId, $kris): void
    {
        $service = app(KriService::class);
        $ids = $kris->pluck('id');
        DB::table('kri_breaches')->whereIn('kri_id', $ids)->delete();
        DB::table('kri_readings')->whereIn('kri_id', $ids)->delete();

        foreach ($kris as $kri) {
            $owner = $kri->owner_id ? User::find($kri->owner_id) : null;
            $weekly = $kri->frequency === 'weekly';
            $steps = $weekly ? 12 : 6;
            $stale = in_array($kri->code, self::STALE, true);

            if ($kri->source === 'platform') {
                [$now] = app(KriMeasures::class)->measure($kri->code, $orgId);
                foreach (range($steps, 1) as $n) {
                    $at = $weekly ? now()->subWeeks($n) : now()->subMonths($n);
                    $past = $this->drift((float) $now, $kri, crc32("{$orgId}|{$kri->code}|{$n}"), $n);
                    $service->record($kri, $past, null, 'Measured from platform data.', $at->setTime(6, 0), false);
                }
                $service->measure($kri, null, now()->subHour());

                continue;
            }

            $band = $this->band(crc32("{$orgId}|{$kri->code}"));
            foreach (range($steps, $stale ? 3 : 0) as $n) {
                $at = ($weekly ? now()->subWeeks($n) : now()->subMonths($n))->setTime(9, 0);
                // The last few periods follow the KRI's band; earlier ones were within appetite.
                $b = $n <= 2 ? $band : ($n <= 4 && $band === 'red' ? 'amber' : 'green');
                $value = $this->value($kri, $b, crc32("{$orgId}|{$kri->code}|{$n}"));
                $service->record($kri, $value, $owner, $b === 'green' ? null : $this->commentary($kri, $b), $at, $n === 0);
            }
        }

        // Owners have written action plans for most open breaches; two are still waiting.
        KriBreach::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('resolved_at')->with('kri')->orderBy('id')->get()
            ->each(function (KriBreach $b, int $i) use ($service) {
                $owner = $b->kri?->owner_id ? User::find($b->kri->owner_id) : null;
                if ($owner && $i % 4 !== 3) {
                    $service->acknowledge($b, "Root cause reviewed with the {$b->kri->category} team; remediation tracked weekly at the risk committee, target back within appetite next period.", $owner);
                    DB::table('kri_breaches')->where('id', $b->id)->update(['acknowledged_at' => $b->occurred_at->copy()->addDays(2)]);
                }
            });
    }

    private function band(int $h): string
    {
        $h %= 100;

        return $h < 62 ? 'green' : ($h < 86 ? 'amber' : 'red');
    }

    /** A value inside a band for the KRI's direction. */
    private function value(Kri $kri, string $band, int $h): float
    {
        [$g, $a, $r] = [$kri->threshold_green, $kri->threshold_amber, $kri->threshold_red];
        $f = ($h % 100) / 100;
        $count = $kri->unit !== '%' && $kri->unit !== 'hours';
        if ($kri->direction === 'lower_worse') {
            $v = match ($band) {
                'green' => $g + (min(100, $g + ($g - $a)) - $g) * $f,
                'amber' => $a + ($g - $a) * $f * 0.95,
                default => $r + ($a - $r) * $f * 0.95,
            };
        } else {
            $v = match ($band) {
                'green' => $g * $f,
                'amber' => $g + max(1, $a - $g) * max(0.2, $f),
                default => $a + max(1, ($r - $a)) * (0.2 + $f * 0.8),
            };
            $v = $band === 'amber' ? min($v, $a) : $v;
        }

        if (! $count) {
            return round($v, 2);
        }

        return match ($band) {
            'amber' => max(floor($g) + 1, min(floor($v), $a)),
            'red' => max(floor($a) + 1, round($v)),
            default => round($v),
        };
    }

    /** Earlier platform readings: within a few units of today's, trending towards it. */
    private function drift(float $now, Kri $kri, int $h, int $n): float
    {
        $delta = (($h % 5) - 2) * max(1, $now * 0.08) * min(1, $n / 4);
        $v = max(0, $now + $delta);

        return $kri->unit === '%' ? min(100, round($v, 1)) : round($v);
    }

    private function commentary(Kri $kri, string $band): string
    {
        return $band === 'red'
            ? "Beyond appetite. Escalated to the {$kri->category} owner; containment actions under way and tracked at the risk committee."
            : 'Within tolerance but above appetite. Cause identified; corrective action scheduled for this period.';
    }

    private function boardPacks(int $orgId, array $bank, callable $person, ?User $cro): void
    {
        BoardPackRun::withoutGlobalScopes()->where('organization_id', $orgId)->delete();
        $builder = app(BoardPackBuilder::class);
        $approver = $person($bank['approver']);
        $security = $person($bank['security']) ?? $cro;
        $templates = BoardPackTemplate::all()->keyBy(fn ($t) => strtok($t->name, ' '));
        if (! $cro || ! $approver) {
            return;
        }

        $packs = [
            ['BAC', $cro, now()->subMonth()->format('Y-m'), now()->subMonth()->endOfMonth()->subDays(3), true],
            ['CRO', $cro, now()->format('Y-m'), now()->subDays(4), true],
            ['CISO', $security, now()->format('Y-m'), now()->subDay(), false],
        ];
        foreach ($packs as [$key, $preparer, $period, $at, $approve]) {
            $template = $templates[$key] ?? null;
            if (! $template || ! $preparer) {
                continue;
            }
            $run = $builder->generate($template, $preparer, $period, $at);
            if ($approve) {
                $builder->approve($run, $approver, 'Reviewed with the CRO; approved for circulation to the committee.');
                $run->update(['approved_at' => $at->copy()->addDays(2)]);
            }
        }
    }
}

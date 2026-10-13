<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\SharedVendorDirectory;
use App\Models\TprmBreachEvent;
use App\Models\TprmSecurityRating;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Services\Vendors\SecurityRatingService;
use App\Services\Vendors\VendorTiering;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vendor Management demo data (ATH-VAL-008). Runs after AssetManagementDemoSeeder.
 *
 * The bank seeders record vendors without the facts tiering needs, and the platform seeder left
 * five ratings and six breach headlines pointing at vendors that no longer exist. Per bank,
 * deterministically, this seeder:
 * - records NDPA processor and CBN material-outsourcing facts from each vendor's category and data
 *   access, with DPA and CBN-notification dates; two processors without a DPA and one material
 *   arrangement not notified (deliberate gaps);
 * - assigns relationship owners (the Vendor Risk Manager for the critical estate), leaving two
 *   vendors unowned; derives rating domains from websites;
 * - computes every tier, records completion and residual rating on completed assessments and sets
 *   the review clock from them; two Tier 1 vendors are overdue (deliberate gaps);
 * - links vendors to the Shared Nigerian Vendor Directory by name;
 * - captures six months of ratings through the sandbox provider, with one sharp drop;
 * - raises simulated breach-watch events in every triage state, one of which opened an
 *   incident-driven reassessment.
 */
class VendorManagementDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'tprm' => 'tprm', 'itops' => 'itops', 'ciso' => 'admin', 'dpco' => 'dpco',
            'no_dpa' => ['Infobip', "Africa's Talking"], 'not_notified' => ['Rack Centre'], 'overdue' => ['Infosys — Finacle', 'SWIFT'],
            'unowned' => ['Globacom', '9mobile'], 'drop' => 'Appzone Group'],
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'tprm' => 'tprm', 'itops' => 'itops', 'ciso' => 'ciso', 'dpco' => 'dpco',
            'no_dpa' => ['Infobip', 'Salesforce'], 'not_notified' => ['MainOne'], 'overdue' => ['Infosys', 'Microsoft'],
            'unowned' => ['Glo Mobile', 'Airtel'], 'drop' => 'e-Tranzact'],
    ];

    /** Category fragments that make an arrangement material outsourcing under the CBN guidelines. */
    private const MATERIAL = ['core banking', 'cloud', 'data centre', 'colo', 'card processing', 'payments', 'switch', 'agent banking', 'international payments', 'hsm'];

    /** Category fragments for vendors that handle customer personal data even without system access. */
    private const PERSONAL_DATA = ['sms', 'messaging', 'crm', 'contact centre', 'kyc', 'bvn', 'agent banking'];

    /** Category fragments owned by IT operations rather than the Vendor Risk Manager. */
    private const IT_OWNED = ['network', 'connectivity', 'telco', 'vulnerability', 'email', 'cdn', 'security', 'devops', 'observability'];

    public function run(): void
    {
        $tiering = app(VendorTiering::class);
        $ratings = app(SecurityRatingService::class);
        $directory = SharedVendorDirectory::all();

        // Monitoring rows with no organisation (or whose vendor is gone) belong to no bank.
        $vendorIds = Vendor::withoutGlobalScopes()->pluck('id');
        TprmSecurityRating::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('organization_id')->orWhereNotIn('vendor_id', $vendorIds))->delete();
        TprmBreachEvent::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('organization_id')->orWhereNotIn('vendor_id', $vendorIds))->delete();

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || Vendor::withoutGlobalScopes()->where('organization_id', $org->id)->whereNotNull('tier')->exists()) {
                continue;
            }
            mt_srand(crc32($slug.'|vendors'));

            $people = User::where('organization_id', $org->id)->pluck('id', 'email');
            $person = fn (string $local) => $people["{$local}@{$bank['domain']}"] ?? null;
            $vendors = Vendor::withoutGlobalScopes()->where('organization_id', $org->id)->orderBy('id')->get();

            foreach ($vendors as $vendor) {
                $this->facts($vendor, $bank, $person, $directory);
            }

            foreach (Vendor::withoutGlobalScopes()->where('organization_id', $org->id)->orderBy('id')->get() as $vendor) {
                $vendor->forceFill(['tier' => $tiering->evaluate($vendor)['tier']])->saveQuietly();
                $this->reviewClock($vendor, $bank, $tiering);
            }

            $this->ratings($org->id, $bank, $ratings);
            $this->breachWatch($org->id, $bank, $person);
        }
        mt_srand();
    }

    private function facts(Vendor $vendor, array $bank, callable $person, $directory): void
    {
        $category = Str::lower(($vendor->category ?? '').' '.($vendor->services_provided ?? ''));
        $has = fn (array $fragments) => collect($fragments)->contains(fn ($f) => str_contains($category, $f));
        $named = fn (array $names) => collect($names)->contains(fn ($n) => str_contains($vendor->name, $n));

        $material = $has(self::MATERIAL) && $vendor->status !== 'terminated';
        $personal = in_array($vendor->data_access_level, ['full', 'limited'], true) || $has(self::PERSONAL_DATA);
        $start = $vendor->contract_start ? Carbon::parse($vendor->contract_start) : now()->subYears(2);

        $owner = $named($bank['unowned']) ? null : ($has(self::IT_OWNED) ? $person($bank['itops']) : $person($bank['tprm']));
        $host = $vendor->website ? Str::lower((string) parse_url($vendor->website, PHP_URL_HOST)) : null;
        $entry = $directory->first(fn ($d) => str_contains(Str::lower($vendor->name), Str::lower(Str::before($d->legal_name, ' ('))));

        DB::table('vendors')->where('id', $vendor->id)->update([
            'material_outsourcing' => $material,
            'regulator_notified_at' => $material && ! $named($bank['not_notified']) ? $start->copy()->subDays(30)->toDateString() : null,
            'processes_personal_data' => $personal,
            'dpa_signed_at' => $personal && ! $named($bank['no_dpa']) ? $start->toDateString() : null,
            'owner_id' => $owner,
            // Same demo pattern as the bank seeders' websites ("interswitch-limited.com").
            'domain' => $host ? Str::after($host, 'www.') : Str::slug(preg_replace('/\(.*?\)/', '', $vendor->name)).'.com',
            'shared_directory_id' => $entry?->id,
            'updated_at' => now(),
        ]);
    }

    /**
     * Completed assessments carry their completion and residual rating; the vendor's clock runs
     * from its latest completed assessment (or the date the bank seeder recorded). Named Tier 1
     * vendors are pushed past their review date.
     */
    private function reviewClock(Vendor $vendor, array $bank, VendorTiering $tiering): void
    {
        foreach (VendorAssessment::withoutGlobalScopes()->where('vendor_id', $vendor->id)->where('status', 'completed')->get() as $a) {
            DB::table('vendor_assessments')->where('id', $a->id)->update([
                'completed_at' => $a->completed_at ?? $a->assessment_date,
                'residual_rating' => VendorAssessment::residualFor($a->overall_score !== null ? (float) $a->overall_score : null),
                'next_review_date' => $a->assessment_date ? $tiering->nextReview($a->assessment_date, $vendor->tier)->toDateString() : $a->next_review_date,
            ]);
        }

        $latest = VendorAssessment::withoutGlobalScopes()->where('vendor_id', $vendor->id)->where('status', 'completed')->max('assessment_date');
        $last = $latest ? Carbon::parse($latest) : ($vendor->last_assessed ? Carbon::parse($vendor->last_assessed) : null);

        if (collect($bank['overdue'])->contains(fn ($n) => str_contains($vendor->name, $n)) && $vendor->tier === 1) {
            $last = now()->subMonths(14)->startOfMonth();
        }

        DB::table('vendors')->where('id', $vendor->id)->update([
            'last_assessed' => $last?->toDateString(),
            'next_review_date' => $vendor->status === 'terminated' ? null : $tiering->nextReview($last ?? now()->subMonths(6), $vendor->tier)->toDateString(),
        ]);
    }

    private function ratings(int $orgId, array $bank, SecurityRatingService $service): void
    {
        TprmSecurityRating::withoutGlobalScopes()->where('organization_id', $orgId)->delete();
        $vendors = Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['active', 'under_review'])->whereNotNull('domain')->get();

        foreach ([5, 4, 3, 2, 1] as $monthsAgo) {
            foreach ($vendors as $vendor) {
                $service->capture($vendor, now()->subMonths($monthsAgo)->startOfMonth()->addDays(1)->setTime(6, 0));
            }
        }
        foreach ($vendors as $vendor) {
            $service->capture($vendor, now()->subDays(2)->setTime(6, 0));
        }

        // One sharp drop in the latest capture, so the drop alert has something to show.
        $dropped = $vendors->first(fn ($v) => str_contains($v->name, $bank['drop']));
        $latest = $dropped ? TprmSecurityRating::withoutGlobalScopes()->where('vendor_id', $dropped->id)->latest('captured_at')->first() : null;
        if ($latest) {
            $value = max(30, $latest->rating_value - 16);
            $latest->update(['rating_value' => $value, 'grade' => TprmSecurityRating::gradeFor($value), 'delta_from_previous' => $latest->delta_from_previous - 16]);
        }
    }

    /** Simulated breach-watch items in each triage state. Headlines say they are simulated. */
    private function breachWatch(int $orgId, array $bank, callable $person): void
    {
        // A re-run replaces the reassessment it opened along with the events.
        $previous = TprmBreachEvent::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id');
        VendorAssessment::withoutGlobalScopes()->whereIn('breach_event_id', $previous)->delete();
        TprmBreachEvent::withoutGlobalScopes()->where('organization_id', $orgId)->delete();
        $tier1 = Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->where('tier', 1)->where('status', 'active')->orderBy('id')->get();
        $others = Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->where('tier', '>', 1)->where('status', 'active')->whereNotNull('domain')->orderBy('id')->get();
        if ($tier1->count() < 3 || $others->isEmpty()) {
            return;
        }

        $reviewer = $person($bank['tprm']);
        $events = [
            [$tier1[0], 'breach', 'critical', 'Simulated alert: credential dump on a paste site references the vendor\'s staff domain', 9, 'reassessment', 'Vendor confirms investigation; incident-driven reassessment opened.'],
            [$tier1[1], 'vulnerability', 'high', 'Simulated alert: critical CVE published for a product the vendor operates for the bank', 3, 'new', null],
            [$tier1[2], 'ransomware', 'high', 'Simulated alert: ransomware leak site lists a company with a similar name', 1, 'new', null],
            [$others[0], 'outage', 'medium', 'Simulated alert: customers report a multi-hour service disruption', 12, 'acknowledged', 'Confirmed with the account manager; no bank service affected.'],
            [$others[min(1, $others->count() - 1)], 'typosquat', 'low', 'Simulated alert: look-alike domain registered for the vendor\'s brand', 20, 'dismissed', 'Registered by the vendor itself as a defensive registration.'],
        ];

        foreach ($events as [$vendor, $type, $severity, $headline, $daysAgo, $status, $notes]) {
            $event = TprmBreachEvent::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'vendor_id' => $vendor->id, 'event_type' => $type, 'severity' => $severity,
                'headline' => $headline, 'url' => null, 'source' => 'demo_feed', 'discovered_at' => now()->subDays($daysAgo)->setTime(7, 30),
                'status' => $status, 'review_notes' => $notes,
                'reviewed_by' => $status === 'new' ? null : $reviewer, 'reviewed_at' => $status === 'new' ? null : now()->subDays($daysAgo - 1),
            ]);

            if ($status === 'reassessment') {
                VendorAssessment::withoutGlobalScopes()->create([
                    'organization_id' => $orgId, 'vendor_id' => $vendor->id, 'breach_event_id' => $event->id,
                    'title' => 'Incident-driven reassessment — '.$vendor->name,
                    'assessment_type' => 'incident_driven', 'status' => 'in_progress', 'assessed_by' => $reviewer,
                    'assessment_date' => now()->subDays($daysAgo - 1)->toDateString(),
                    'findings' => 'Awaiting the vendor\'s incident report and evidence of credential resets.',
                ]);
            }
        }
    }
}

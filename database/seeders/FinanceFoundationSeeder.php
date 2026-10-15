<?php

namespace Database\Seeders;

use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Finance\GlAccount;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * IT Financial Management foundation demo data (ATH-FIN-001 §3.1, §13): settings, TBM taxonomy,
 * chart of accounts, currencies and FX, taxes, fiscal calendar, cost centres, drivers and the
 * finance users per bank. Runs after ChangeManagementDemoSeeder. Deliberate gaps are kept: an
 * unowned cost centre in each bank and an inactive suspense GL account at First Bank.
 */
class FinanceFoundationSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['prefix' => 'KHB', 'admin' => 'admin@kanoheritage.ng', 'domain' => 'kanoheritage.ng', 'seed' => 8101],
        'first-bank-nigeria' => ['prefix' => 'FBN', 'admin' => 'admin@firstbanknigeria.ng', 'domain' => 'firstbanknigeria.ng', 'seed' => 8102],
    ];

    private const NEW_USERS = [
        'kano-heritage-bank' => ['finance' => 'Zainab Garba', 'procurement' => 'Ibrahim Yusuf', 'cfo' => 'Hadiza Bello', 'cio' => 'Musa Danladi'],
        'first-bank-nigeria' => ['finance' => 'Olumide Adeyemi', 'procurement' => 'Chinwe Eze', 'cfo' => 'Folake Ogunleye', 'cio' => 'Emeka Nwosu'],
    ];

    /** code, name, parent code, owner key, legal entity code, business unit */
    private const COST_CENTRES = [
        'kano-heritage-bank' => [
            ['KHB-IT', 'IT Division', null, 'itops', null, 'Technology'],
            ['KHB-IT-INF', 'IT Infrastructure', 'KHB-IT', 'itops', null, 'Technology'],
            ['KHB-IT-DC', 'Data Centre & Cloud', 'KHB-IT', 'sysadmin', null, 'Technology'],
            ['KHB-IT-NET', 'Network & Telecoms', 'KHB-IT', 'network', null, 'Technology'],
            ['KHB-IT-APP', 'Core Banking Applications', 'KHB-IT', 'finacle', null, 'Technology'],
            ['KHB-IT-DIG', 'Digital Channels', 'KHB-IT', 'digital', null, 'Technology'],
            ['KHB-IT-TRY', 'Treasury Systems', 'KHB-IT', 'treasury', null, 'Technology'],
            ['KHB-IT-ITSM', 'IT Service Management', 'KHB-IT', null, null, 'Technology'],
            ['KHB-SEC', 'Information Security', null, 'ciso', null, 'Information Security'],
            ['KHB-SEC-SOC', 'Security Operations', 'KHB-SEC', 'soc', null, 'Information Security'],
        ],
        'first-bank-nigeria' => [
            ['FBN-GT', 'Group Technology', null, 'itops', 'GRP', 'Technology'],
            ['FBN-GT-INF', 'Infrastructure', 'FBN-GT', 'itops', 'NG-BANK', 'Technology'],
            ['FBN-GT-DC', 'Data Centres', 'FBN-GT', 'sysadmin', 'NG-BANK', 'Technology'],
            ['FBN-GT-NET', 'Network', 'FBN-GT', 'network', 'NG-BANK', 'Technology'],
            ['FBN-GT-CBS', 'Core Banking (Finacle)', 'FBN-GT', 'finacle', 'NG-BANK', 'Technology'],
            ['FBN-GT-DIG', 'Digital Banking', 'FBN-GT', 'digital', 'NG-BANK', 'Technology'],
            ['FBN-GT-DATA', 'Data & Analytics', 'FBN-GT', null, 'NG-BANK', 'Technology'],
            ['FBN-GT-PAY', 'Payments Technology', 'FBN-GT', 'treasury', 'NG-PSP', 'Technology'],
            ['FBN-GT-GH', 'Ghana Subsidiary IT', 'FBN-GT', 'itops', 'GH-BANK', 'Technology'],
            ['FBN-GT-UK', 'UK Subsidiary IT', 'FBN-GT', 'itops', 'UK-BANK', 'Technology'],
            ['FBN-GIS', 'Group Information Security', null, 'ciso', 'GRP', 'Information Security'],
            ['FBN-GIS-SOC', 'Cyber Defence Centre', 'FBN-GIS', 'soc', 'NG-BANK', 'Information Security'],
        ],
    ];

    /** code, name, unit, [kano base, first bank base] */
    private const DRIVERS = [
        ['HEADCOUNT', 'Staff headcount', 'FTE', [2150, 9800]],
        ['BRANCHES', 'Branches', 'count', [74, 750]],
        ['M365_SEATS', 'Microsoft 365 seats', 'users', [2400, 11500]],
        ['TXN_VOL', 'Transaction volume', 'millions/month', [18, 210]],
        ['STORAGE_TB', 'Primary storage', 'TB', [380, 4200]],
    ];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin) {
                continue;
            }
            Auth::setUser($admin);
            mt_srand($bank['seed']);
            $orgId = $org->id;
            $isFirst = $slug === 'first-bank-nigeria';

            $finance = $this->users($slug, $orgId, $bank);
            app(FinanceSettings::class)->ensureDefaults($orgId);

            if (CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
                continue;
            }

            $this->settingsTweaks($orgId, $isFirst);
            $this->fiscalYears($orgId, $admin, $finance);
            $this->costCentres($slug, $orgId, $admin);
            $this->customCategories($orgId, $isFirst);
            $this->fx($orgId, $isFirst);
            $this->drivers($orgId, $isFirst);
        }

        mt_srand();
        Auth::forgetUser();
    }

    /** @return User the finance controller */
    private function users(string $slug, int $orgId, array $bank): User
    {
        $domain = $bank['domain'];
        $specs = [
            'finance' => ['Finance', 'IT Finance Controller', ['IT Finance Controller']],
            'procurement' => ['Procurement', 'Procurement Officer', ['Procurement Officer']],
            'cfo' => ['Finance', 'Chief Financial Officer', ['Finance Approver', 'Investment Sponsor', 'IT Steering Committee']],
            'cio' => ['Technology', 'Chief Information Officer', ['Finance Approver', 'IT Steering Committee']],
        ];
        $made = [];
        foreach ($specs as $key => [$dept, $title, $roles]) {
            $user = User::updateOrCreate(['email' => "{$key}@{$domain}"], [
                'name' => self::NEW_USERS[$slug][$key], 'organization_id' => $orgId, 'job_title' => $title, 'department' => $dept,
                'password' => Hash::make('password'), 'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles($roles);
            $made[$key] = $user;
        }

        $users = User::where('organization_id', $orgId)->get();
        $by = fn (string $needle) => $users->first(fn (User $u) => stripos((string) $u->job_title, $needle) !== false);
        if ($itops = $by('Head of IT')) {
            $itops->assignRole('IT Budget Owner');
            $itops->removeRole('Finance Approver'); // SoD: budget owners never approve
        }
        // Admins approve via Organization Admin; the CFO and CIO hold Finance Approver (SoD with budget owners).

        return $made['finance'];
    }

    private function people(int $orgId, User $admin): array
    {
        $users = User::where('organization_id', $orgId)->orderBy('id')->get();
        $by = fn (string $needle) => $users->first(fn (User $u) => stripos((string) $u->job_title, $needle) !== false) ?? $admin;

        return [
            'itops' => $by('Head of IT'), 'sysadmin' => $by('Platform Admin'), 'network' => $by('Network Engineer'),
            'finacle' => $by('Finacle'), 'digital' => $by('Head of Digital Channels'), 'treasury' => $by('Treasury IT'),
            'ciso' => $by('CISO'), 'soc' => $by('Security Operations Analyst'),
        ];
    }

    private function settingsTweaks(int $orgId, bool $isFirst): void
    {
        $setting = app(FinanceSettings::class)->for($orgId);
        $setting->update($isFirst
            ? ['overbudget_mode' => 'block', 'variance_abs_floor' => 5000000, 'match_tolerance_pct' => 1, 'escalation_role' => 'Chief Information Officer']
            : ['overbudget_mode' => 'warn', 'variance_abs_floor' => 1000000]);
        app(FinanceSettings::class)->flush();
    }

    private function fiscalYears(int $orgId, User $admin, User $finance): void
    {
        $y = now()->year;
        $svc = app(FiscalCalendarService::class);
        $fy = $svc->createYear($orgId, ['year_label' => "FY{$y}", 'starts_on' => "{$y}-01-01", 'period_scheme' => 'monthly', 'status' => 'open', 'is_current' => true], $admin);
        $svc->createYear($orgId, ['year_label' => 'FY'.($y + 1), 'starts_on' => ($y + 1).'-01-01', 'period_scheme' => 'monthly', 'status' => 'planning'], $admin);

        $monthStart = now()->startOfMonth();
        $lockCutoff = Carbon::create($y, 6, 30)->endOfDay();
        foreach ($fy->periods as $p) {
            if ($p->ends_on->lt($monthStart)) {
                $p->forceFill(['status' => 'closed', 'closed_by_id' => $finance->id, 'closed_at' => $p->ends_on->copy()->addDays(5)->setTime(10, 0)]);
                if ($p->ends_on->lte($lockCutoff)) {
                    $p->forceFill(['status' => 'locked', 'locked_by_id' => $finance->id, 'locked_at' => Carbon::create($y, 7, 15, 10)]);
                }
                $p->save();
            }
        }
    }

    private function costCentres(string $slug, int $orgId, User $admin): void
    {
        $people = $this->people($orgId, $admin);
        $entities = LegalEntity::query()->pluck('id', 'code');
        $ids = [];
        foreach (self::COST_CENTRES[$slug] as [$code, $name, $parent, $ownerKey, $entity, $unit]) {
            $owner = $ownerKey ? $people[$ownerKey] : null;
            $cc = CostCentre::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $admin->id, 'code' => $code, 'name' => $name,
                'parent_id' => $parent ? $ids[$parent] : null, 'owner_id' => $owner?->id,
                'legal_entity_id' => $entity ? ($entities[$entity] ?? null) : null, 'business_unit' => $unit, 'is_active' => true,
            ]);
            $ids[$code] = $cc->id;
            if ($owner && ! $owner->hasRole('IT Budget Owner')) {
                $owner->assignRole('IT Budget Owner');
            }
        }
    }

    private function customCategories(int $orgId, bool $isFirst): void
    {
        $find = fn (string $code) => Category::withoutGlobalScopes()->where('organization_id', $orgId)->where('code', $code)->value('id');
        $custom = $isFirst
            ? [['FBN-RECHARGE', 'Group shared-services recharge', 'CP-OTH'], ['FBN-SWIFT', 'SWIFT & correspondent connectivity', 'TW-NET']]
            : [['KHB-REGTECH', 'CBN regulatory technology', 'TW-SEC']];
        foreach ($custom as $i => [$code, $name, $parent]) {
            Category::withoutGlobalScopes()->firstOrCreate(['organization_id' => $orgId, 'code' => $code], [
                'name' => $name, 'kind' => 'sub_category', 'parent_id' => $find($parent), 'default_capex' => false,
                'is_system' => false, 'is_active' => true, 'sort_order' => 1000 + $i,
            ]);
        }

        if ($isFirst) {
            GlAccount::withoutGlobalScopes()->firstOrCreate(['organization_id' => $orgId, 'code' => '6999'], ['name' => 'Legacy suspense (do not use)', 'type' => 'opex', 'is_active' => false]);
        } else {
            GlAccount::withoutGlobalScopes()->firstOrCreate(['organization_id' => $orgId, 'code' => '6205'], ['name' => 'Core banking AMC (Finacle)', 'type' => 'opex', 'is_active' => true]);
        }
    }

    private function fx(int $orgId, bool $isFirst): void
    {
        $pairs = ['USD' => 1540, 'GBP' => 2060, 'EUR' => 1790];
        if ($isFirst) {
            Currency::withoutGlobalScopes()->firstOrCreate(['organization_id' => $orgId, 'code' => 'GHS'], ['name' => 'Ghanaian cedi', 'symbol' => 'GH₵', 'decimals' => 2, 'is_base' => false, 'is_active' => true]);
            $pairs['GHS'] = 128;
        }

        $dates = [];
        for ($m = 1; $m <= now()->month; $m++) {
            $dates[] = Carbon::create(now()->year, $m, 1);
        }
        $dates[] = now()->startOfDay();

        $n = 0;
        foreach ($pairs as $from => $base) {
            foreach (array_unique(array_map(fn ($d) => $d->toDateString(), $dates)) as $date) {
                $rate = round($base * (1 + (mt_rand(-250, 250) / 10000)), 4);
                FxRate::withoutGlobalScopes()->updateOrCreate(
                    ['organization_id' => $orgId, 'from_currency' => $from, 'to_currency' => 'NGN', 'rate_date' => $date],
                    ['rate' => $rate, 'source' => $n++ % 2 === 0 ? 'manual' : 'import'],
                );
            }
        }
    }

    private function drivers(int $orgId, bool $isFirst): void
    {
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_current', true)->first();
        if (! $fy) {
            return;
        }
        foreach (self::DRIVERS as [$code, $name, $unit, $bases]) {
            $driver = Driver::withoutGlobalScopes()->firstOrCreate(['organization_id' => $orgId, 'code' => $code], ['name' => $name, 'unit' => $unit, 'is_active' => true]);
            $value = $bases[$isFirst ? 1 : 0];
            foreach ($fy->periods as $p) {
                $driver->values()->create(['organization_id' => $orgId, 'period_id' => $p->id, 'value' => round($value, 4)]);
                $value *= 1 + (mt_rand(0, 40) / 10000);
            }
        }
    }
}

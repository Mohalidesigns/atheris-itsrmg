<?php

namespace Database\Seeders;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetLedger;
use App\Services\Finance\BudgetPhasing;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\SupplementaryBudgetService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * IT Financial Management P1 demo data (ATH-FIN-001 §13, plan §5.1): per-bank DoA bands with versions,
 * budget FX rates, three budget cycles, FY baselines (history, approved under the unconfigured default),
 * a mid-year revision, Workflow Studio default stages, transfers and supplementary requests in every
 * state, FY+1 ceilings and FY+1 budgets in every lifecycle state. Runs after FinanceFoundationSeeder.
 *
 * Deliberate gaps are kept: an unowned cost centre with an ownerless draft, a discretionary line with
 * no justification, one breached ceiling per bank. Nothing here hardcodes a year: FY{y} is the current
 * fiscal year and FY{y+1} the planning year.
 */
class FinanceDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['prefix' => 'KHB', 'admin' => 'admin@kanoheritage.ng', 'domain' => 'kanoheritage.ng', 'seed' => 8111, 'first' => false],
        'first-bank-nigeria' => ['prefix' => 'FBN', 'admin' => 'admin@firstbanknigeria.ng', 'domain' => 'firstbanknigeria.ng', 'seed' => 8112, 'first' => true],
    ];

    /** New demo users: the MD/CEO (final stage of large budgets) and a Head of IT who holds no cost centre. */
    private const NEW_USERS = [
        'kano-heritage-bank' => ['md' => 'Aminu Lawal', 'headofit' => 'Hassan Abubakar'],
        'first-bank-nigeria' => ['md' => 'Babatunde Okoro', 'headofit' => 'Obinna Eze'],
    ];

    /** Delegation-of-authority bands in naira: label, min, max (null = open). */
    private const DOA = [
        'kano-heritage-bank' => [
            ['Up to 5m', 0, 5000000], ['5m to 25m', 5000000, 25000000], ['25m to 100m', 25000000, 100000000],
            ['100m to 500m', 100000000, 500000000], ['Above 500m', 500000000, null],
        ],
        'first-bank-nigeria' => [
            ['Up to 10m', 0, 10000000], ['10m to 100m', 10000000, 100000000], ['100m to 500m', 100000000, 500000000],
            ['500m to 2bn', 500000000, 2000000000], ['Above 2bn', 2000000000, null],
        ],
    ];

    private const RATES = ['USD' => 1520, 'GBP' => 2050, 'EUR' => 1770, 'GHS' => 130];

    /**
     * FY{y} baseline lines per cost centre. Tuple: description, pool, tower[/category], annual amount, currency,
     * capex (1/0), priority (M mandatory, R regulatory, C committed, D discretionary), run/grow/transform,
     * phasing, recurring (1/0).
     */
    private const LINES = [
        'kano-heritage-bank' => [
            'KHB-IT-INF' => [
                ['Dell PowerEdge and storage hardware maintenance (3-year support)', 'CP-OS', 'TW-CMP', 18500000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Microsoft Enterprise Agreement (M365, Windows Server, SQL Server)', 'CP-SW', 'TW-EU/TW-EU-COL', 96000, 'USD', 0, 'C', 'r', 'front', 1],
                ['Server refresh: 24 rack servers for branch data hubs', 'CP-HW', 'TW-CMP/TW-CMP-SRV', 85000000, 'NGN', 1, 'D', 'g', 'custom', 0],
                ['Workstation refresh: 400 branch desktops', 'CP-HW', 'TW-EU/TW-EU-WKS', 62000000, 'NGN', 1, 'D', 'g', 'back', 0],
                ['Service desk outsourcing (Tier-1)', 'CP-OS', 'TW-EU/TW-EU-SD', 24000000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['Branch UPS and battery replacement', 'CP-HW', 'TW-DC/TW-DC-OTH', 14800000, 'NGN', 1, 'M', 'r', 'back', 0],
                ['IT operations staff costs', 'CP-LAB', 'TW-DC/TW-DC-ENT', 96000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['IT training and certification (ITIL, CCNA, cloud)', 'CP-OS', 'TW-ITM/TW-ITM-STR', 8400000, 'NGN', 0, 'D', 'g', 'seasonal', 0],
            ],
            'KHB-IT-DC' => [
                ['Data-centre power and diesel (Kano DC and DR site)', 'CP-FAC', 'TW-DC', 38400000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Oracle Database support and licences', 'CP-SW', 'TW-PLT/TW-PLT-DB', 62000, 'USD', 0, 'C', 'r', 'front', 1],
                ['AWS and Azure cloud hosting (digital channels)', 'CP-CLD', 'TW-APP', 84000, 'USD', 0, 'D', 'g', 'seasonal', 1],
                ['Precision cooling replacement', 'CP-HW', 'TW-DC', 27000000, 'NGN', 1, 'M', 'r', 'back', 0],
                ['DR site connectivity and replication', 'CP-TEL', 'TW-DC', 12600000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Backup library and Veeam licences', 'CP-SW', 'TW-STO/TW-STO-BKP', 9800000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['Hyperconverged cluster expansion', 'CP-HW', 'TW-CMP', 70000, 'USD', 1, 'D', 'g', 'custom', 0],
                ['DR drill and business continuity testing', 'CP-OS', 'TW-SEC/TW-SEC-DR', 5800000, 'NGN', 0, 'R', 'r', 'equal', 0],
            ],
            'KHB-IT-NET' => [
                ['MTN, Airtel and Glo branch WAN links (74 branches)', 'CP-TEL', 'TW-NET/TW-NET-LAN', 46800000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Internet and VSAT backup connectivity', 'CP-TEL', 'TW-NET/TW-NET-INT', 14400000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['Cisco SmartNet support', 'CP-OS', 'TW-NET', 38000, 'USD', 0, 'C', 'r', 'front', 1],
                ['Core network refresh (Cisco Catalyst 9K)', 'CP-HW', 'TW-NET', 120000, 'USD', 1, 'D', 'g', 'custom', 0],
                ['Fortinet FortiGate firewall renewal', 'CP-SW', 'TW-SEC/TW-SEC-SEC', 54000, 'USD', 0, 'M', 'r', 'front', 1],
                ['Branch Wi-Fi rollout, phase 2', 'CP-HW', 'TW-NET', 22500000, 'NGN', 1, 'D', 't', 'back', 0],
                ['Voice and PBX maintenance', 'CP-OS', 'TW-NET/TW-NET-VOI', 4200000, 'NGN', 0, 'D', 'r', 'equal', 1],
            ],
            'KHB-IT-APP' => [
                ['Finacle core banking AMC (Infosys)', 'CP-SW', 'TW-APP/TW-APP-SUP', 310000, 'USD', 0, 'M', 'r', 'front', 1],
                ['Finacle customisation for CBN returns and charges', 'CP-OS', 'TW-APP/TW-APP-DEV', 56000000, 'NGN', 0, 'R', 'g', 'equal', 0],
                ['Oracle WebLogic and middleware support', 'CP-SW', 'TW-PLT/TW-PLT-MW', 28000, 'USD', 0, 'C', 'r', 'equal', 1],
                ['NIBSS NIP, NQR and Interswitch switching fees', 'CP-OS', 'TW-APP', 41000000, 'NGN', 0, 'M', 'r', 'seasonal', 1],
                ['Core banking performance testing tools', 'CP-SW', 'TW-APP/TW-APP-DEV', 8500000, 'NGN', 0, 'D', 'g', 'equal', 0],
                ['Application support contractors', 'CP-LAB', 'TW-APP/TW-APP-SUP', 33600000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['Finacle 11 upgrade programme', 'CP-OS', 'TW-APP', 145000000, 'NGN', 1, 'D', 't', 'custom', 0],
            ],
            'KHB-IT-DIG' => [
                ['Mobile banking app licences and store fees', 'CP-SW', 'TW-APP/TW-APP-SAAS', 12000000, 'NGN', 0, 'C', 'g', 'equal', 1],
                ['Open banking API gateway', 'CP-SW', 'TW-APP', 66000, 'USD', 0, 'D', 't', 'front', 0],
                ['USSD shortcode and aggregator fees', 'CP-TEL', 'TW-NET', 19200000, 'NGN', 0, 'M', 'r', 'seasonal', 1],
                ['Azure hosting for digital channels', 'CP-CLD', 'TW-APP', 42000, 'USD', 0, 'C', 'g', 'seasonal', 1],
                ['Digital product squad (contract engineers)', 'CP-LAB', 'TW-APP/TW-APP-DEV', 54000000, 'NGN', 0, 'D', 'g', 'equal', 0],
                ['Chatbot and virtual assistant', 'CP-SW', 'TW-APP', 24000, 'USD', 0, 'D', 't', 'back', 0],
                ['Customer analytics platform', 'CP-SW', 'TW-APP', 36000, 'USD', 0, 'D', 'g', 'equal', 1],
                ['Mobile penetration testing and code review', 'CP-OS', 'TW-SEC/TW-SEC-SEC', 7500000, 'NGN', 0, 'R', 'r', 'equal', 0],
            ],
            'KHB-IT-TRY' => [
                ['Treasury management system support', 'CP-SW', 'TW-APP', 48000, 'USD', 0, 'M', 'r', 'front', 1],
                ['Bloomberg and Refinitiv terminals', 'CP-SW', 'TW-APP', 31000, 'USD', 0, 'C', 'r', 'equal', 1],
                ['SWIFT Alliance Access and connectivity', 'CP-SW', 'TW-NET', 26000, 'USD', 0, 'M', 'r', 'equal', 1],
                ['SWIFT CSP attestation and assurance', 'CP-OS', 'TW-SEC/TW-SEC-CMP', 4800000, 'NGN', 0, 'R', 'r', 'equal', 0],
                ['FX trading platform upgrade', 'CP-SW', 'TW-APP', 55000, 'USD', 1, 'D', 'g', 'custom', 0],
                ['Treasury IT contractors', 'CP-LAB', 'TW-APP/TW-APP-SUP', 12600000, 'NGN', 0, 'D', 'r', 'equal', 0],
                ['Regulatory FX returns data feed (CBN)', 'CP-OS', 'TW-APP', 6200000, 'NGN', 0, 'R', 'r', 'equal', 1],
            ],
            'KHB-SEC' => [
                ['Splunk SIEM and log retention', 'CP-SW', 'TW-SEC', 88000, 'USD', 0, 'M', 'r', 'front', 1],
                ['CrowdStrike Falcon endpoint protection', 'CP-SW', 'TW-SEC', 64000, 'USD', 0, 'M', 'r', 'front', 1],
                ['24x7 managed SOC retainer', 'CP-OS', 'TW-SEC/TW-SEC-SEC', 47000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Annual penetration test and red team', 'CP-OS', 'TW-SEC', 9800000, 'NGN', 0, 'R', 'r', 'equal', 0],
                ['CBN CSAT assessment support and regtech', 'CP-OS', 'TW-SEC/KHB-REGTECH', 14500000, 'NGN', 0, 'R', 'r', 'equal', 0],
                ['Privileged access management and MFA rollout', 'CP-SW', 'TW-SEC', 41000, 'USD', 0, 'D', 't', 'back', 0],
                ['Security awareness and phishing simulation', 'CP-OS', 'TW-SEC', 3600000, 'NGN', 0, 'R', 'g', 'equal', 1],
            ],
            'KHB-SEC-SOC' => [
                ['SOC analyst staff costs', 'CP-LAB', 'TW-SEC/TW-SEC-SEC', 42000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Threat intelligence feeds', 'CP-SW', 'TW-SEC', 9000, 'USD', 0, 'C', 'r', 'equal', 1],
                ['SOAR automation licences', 'CP-SW', 'TW-SEC', 9000, 'USD', 0, 'D', 't', 'back', 0],
                ['SOC console and video wall refresh', 'CP-HW', 'TW-SEC', 6400000, 'NGN', 1, 'D', 'g', 'back', 0],
                ['Incident response retainer (DFIR)', 'CP-OS', 'TW-SEC', 8200000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['SOC training and certifications', 'CP-OS', 'TW-SEC', 3900000, 'NGN', 0, 'D', 'g', 'equal', 0],
            ],
        ],
        'first-bank-nigeria' => [
            'FBN-GT-INF' => [
                ['Dell and HPE server and storage maintenance', 'CP-OS', 'TW-CMP', 168000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Microsoft Enterprise Agreement (11,500 seats)', 'CP-SW', 'TW-EU/TW-EU-COL', 640000, 'USD', 0, 'C', 'r', 'front', 1],
                ['Branch server consolidation programme', 'CP-HW', 'TW-CMP/TW-CMP-SRV', 520000000, 'NGN', 1, 'D', 't', 'custom', 0],
                ['Workstation refresh: 3,000 branch desktops', 'CP-HW', 'TW-EU/TW-EU-WKS', 410000000, 'NGN', 1, 'D', 'g', 'back', 0],
                ['Service desk and field engineering outsourcing', 'CP-OS', 'TW-EU/TW-EU-SD', 215000000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['Branch UPS and inverter replacement (750 branches)', 'CP-HW', 'TW-DC/TW-DC-OTH', 230000000, 'NGN', 1, 'M', 'r', 'back', 0],
                ['Infrastructure staff costs', 'CP-LAB', 'TW-DC/TW-DC-ENT', 640000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['IT training and certification', 'CP-OS', 'TW-ITM/TW-ITM-STR', 48000000, 'NGN', 0, 'D', 'g', 'seasonal', 0],
            ],
            'FBN-GT-DC' => [
                ['Lagos DC and DR power, diesel and cooling', 'CP-FAC', 'TW-DC', 360000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Oracle Exadata support and licences', 'CP-SW', 'TW-PLT/TW-PLT-DB', 410000, 'USD', 0, 'C', 'r', 'front', 1],
                ['AWS and Azure cloud hosting (digital and analytics)', 'CP-CLD', 'TW-APP', 520000, 'USD', 0, 'D', 'g', 'seasonal', 1],
                ['Tier-3 uplift: UPS and generator replacement', 'CP-HW', 'TW-DC', 480000000, 'NGN', 1, 'M', 'r', 'custom', 0],
                ['DC interconnect and replication links', 'CP-TEL', 'TW-DC', 96000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Enterprise backup (Commvault)', 'CP-SW', 'TW-STO/TW-STO-BKP', 118000000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['All-flash storage expansion', 'CP-HW', 'TW-STO/TW-STO-ONL', 380000, 'USD', 1, 'D', 'g', 'back', 0],
            ],
            'FBN-GT-NET' => [
                ['Branch WAN and MPLS (MTN, Airtel, Glo, Ipnx)', 'CP-TEL', 'TW-NET/TW-NET-LAN', 430000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Internet breakout and VSAT backup', 'CP-TEL', 'TW-NET/TW-NET-INT', 120000000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['Cisco SmartNet and DNA subscriptions', 'CP-OS', 'TW-NET', 290000, 'USD', 0, 'C', 'r', 'front', 1],
                ['SD-WAN rollout to 750 branches', 'CP-HW', 'TW-NET', 920000, 'USD', 1, 'D', 't', 'custom', 0],
                ['Fortinet and Palo Alto firewall renewals', 'CP-SW', 'TW-SEC/TW-SEC-SEC', 380000, 'USD', 0, 'M', 'r', 'front', 1],
                ['Branch Wi-Fi refresh', 'CP-HW', 'TW-NET', 185000000, 'NGN', 1, 'D', 'g', 'back', 0],
                ['Voice and contact-centre telephony', 'CP-OS', 'TW-NET/TW-NET-VOI', 64000000, 'NGN', 0, 'C', 'r', 'equal', 1],
            ],
            'FBN-GT-CBS' => [
                ['Finacle core banking AMC (Infosys)', 'CP-SW', 'TW-APP/TW-APP-SUP', 1350000, 'USD', 0, 'M', 'r', 'front', 1],
                ['Finacle customisation for CBN circulars', 'CP-OS', 'TW-APP/TW-APP-DEV', 380000000, 'NGN', 0, 'R', 'g', 'equal', 0],
                ['Middleware and integration platform support', 'CP-SW', 'TW-PLT/TW-PLT-MW', 160000, 'USD', 0, 'C', 'r', 'equal', 1],
                ['NIBSS and Interswitch switching fees', 'CP-OS', 'TW-APP', 540000000, 'NGN', 0, 'M', 'r', 'seasonal', 1],
                ['Core banking test automation', 'CP-SW', 'TW-APP/TW-APP-DEV', 74000000, 'NGN', 0, 'D', 'g', 'equal', 0],
                ['Application support contractors', 'CP-LAB', 'TW-APP/TW-APP-SUP', 260000000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['Finacle upgrade and decoupling programme', 'CP-OS', 'TW-APP', 1300000000, 'NGN', 1, 'D', 't', 'custom', 0],
                ['Temenos support for the UK subsidiary', 'CP-SW', 'TW-APP/TW-APP-SUP', 95000, 'GBP', 0, 'C', 'r', 'equal', 1],
            ],
            'FBN-GT-DIG' => [
                ['Mobile banking app licences and store fees', 'CP-SW', 'TW-APP/TW-APP-SAAS', 88000000, 'NGN', 0, 'C', 'g', 'equal', 1],
                ['Open banking API gateway', 'CP-SW', 'TW-APP', 410000, 'USD', 0, 'D', 't', 'front', 0],
                ['USSD (*894#) shortcode and aggregator fees', 'CP-TEL', 'TW-NET', 142000000, 'NGN', 0, 'M', 'r', 'seasonal', 1],
                ['Azure hosting for digital channels', 'CP-CLD', 'TW-APP', 360000, 'USD', 0, 'C', 'g', 'seasonal', 1],
                ['Digital product engineering squads', 'CP-LAB', 'TW-APP/TW-APP-DEV', 480000000, 'NGN', 0, 'D', 'g', 'equal', 0],
                ['Conversational banking assistant', 'CP-SW', 'TW-APP', 150000, 'USD', 0, 'D', 't', 'back', 0],
                ['Fraud analytics for mobile channels', 'CP-SW', 'TW-SEC/TW-SEC-SEC', 210000, 'USD', 0, 'R', 'g', 'equal', 1],
                ['Mobile application security testing', 'CP-OS', 'TW-SEC/TW-SEC-SEC', 36000000, 'NGN', 0, 'R', 'r', 'equal', 0],
            ],
            'FBN-GT-PAY' => [
                ['Payments switch support and licences', 'CP-SW', 'TW-APP', 320000, 'USD', 0, 'M', 'r', 'front', 1],
                ['NIBSS NIP and NQR connectivity', 'CP-TEL', 'TW-NET', 58000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['SWIFT Alliance and CSP assurance', 'CP-SW', 'TW-NET', 140000, 'USD', 0, 'R', 'r', 'equal', 1],
                ['Card scheme (Verve, Mastercard, Visa) certification', 'CP-OS', 'TW-SEC/TW-SEC-CMP', 72000000, 'NGN', 0, 'R', 'r', 'equal', 0],
                ['HSM replacement', 'CP-HW', 'TW-SEC', 260000, 'USD', 1, 'M', 'r', 'custom', 0],
                ['Instant payments capacity expansion', 'CP-HW', 'TW-CMP', 190000000, 'NGN', 1, 'D', 'g', 'back', 0],
                ['Payments engineering contractors', 'CP-LAB', 'TW-APP/TW-APP-SUP', 118000000, 'NGN', 0, 'D', 'r', 'equal', 0],
            ],
            'FBN-GT-GH' => [
                ['Ghana core banking support', 'CP-SW', 'TW-APP/TW-APP-SUP', 480000, 'GHS', 0, 'M', 'r', 'equal', 1],
                ['Ghana branch network links', 'CP-TEL', 'TW-NET', 360000, 'GHS', 0, 'M', 'r', 'equal', 1],
                ['Local data centre colocation (Accra)', 'CP-FAC', 'TW-DC', 300000, 'GHS', 0, 'C', 'r', 'equal', 1],
                ['Bank of Ghana regulatory reporting tools', 'CP-SW', 'TW-SEC/TW-SEC-CMP', 124000, 'GHS', 0, 'R', 'r', 'equal', 1],
                ['ATM and POS terminal refresh', 'CP-HW', 'TW-EU', 640000, 'GHS', 1, 'D', 'g', 'back', 0],
                ['Ghana IT operations staff', 'CP-LAB', 'TW-DC/TW-DC-ENT', 560000, 'GHS', 0, 'M', 'r', 'equal', 1],
                ['Endpoint security renewals', 'CP-SW', 'TW-SEC', 76000, 'GHS', 0, 'M', 'r', 'front', 1],
            ],
            'FBN-GT-UK' => [
                ['Temenos Transact hosting (UK)', 'CP-CLD', 'TW-APP', 410000, 'GBP', 0, 'M', 'r', 'equal', 1],
                ['UK data centre colocation', 'CP-FAC', 'TW-DC', 160000, 'GBP', 0, 'C', 'r', 'equal', 1],
                ['FCA and PRA regulatory reporting platform', 'CP-SW', 'TW-SEC/TW-SEC-CMP', 120000, 'GBP', 0, 'R', 'r', 'equal', 1],
                ['UK IT operations staff', 'CP-LAB', 'TW-DC/TW-DC-ENT', 520000, 'GBP', 0, 'M', 'r', 'equal', 1],
                ['Cyber Essentials Plus and penetration test', 'CP-OS', 'TW-SEC/TW-SEC-SEC', 46000, 'GBP', 0, 'R', 'r', 'equal', 0],
                ['Microsoft 365 licences (UK)', 'CP-SW', 'TW-EU/TW-EU-COL', 58000, 'GBP', 0, 'C', 'r', 'front', 1],
                ['Network refresh (London office)', 'CP-HW', 'TW-NET', 85000, 'GBP', 1, 'D', 'g', 'back', 0],
            ],
            'FBN-GIS' => [
                ['Splunk SIEM and log retention', 'CP-SW', 'TW-SEC', 560000, 'USD', 0, 'M', 'r', 'front', 1],
                ['CrowdStrike Falcon endpoint protection', 'CP-SW', 'TW-SEC', 430000, 'USD', 0, 'M', 'r', 'front', 1],
                ['Identity governance and PAM', 'CP-SW', 'TW-SEC', 380000, 'USD', 0, 'C', 't', 'equal', 1],
                ['Annual penetration test and red team', 'CP-OS', 'TW-SEC', 96000000, 'NGN', 0, 'R', 'r', 'equal', 0],
                ['CBN CSAT and NDPC assessments', 'CP-OS', 'TW-SEC/TW-SEC-CMP', 82000000, 'NGN', 0, 'R', 'r', 'equal', 0],
                ['Data loss prevention rollout', 'CP-SW', 'TW-SEC', 290000, 'USD', 0, 'D', 't', 'back', 0],
                ['Security awareness programme', 'CP-OS', 'TW-SEC', 24000000, 'NGN', 0, 'R', 'g', 'equal', 1],
            ],
            'FBN-GIS-SOC' => [
                ['Cyber Defence Centre staff costs', 'CP-LAB', 'TW-SEC/TW-SEC-SEC', 310000000, 'NGN', 0, 'M', 'r', 'equal', 1],
                ['Threat intelligence and brand protection', 'CP-SW', 'TW-SEC', 140000, 'USD', 0, 'C', 'r', 'equal', 1],
                ['SOAR and detection engineering', 'CP-SW', 'TW-SEC', 210000, 'USD', 0, 'D', 't', 'back', 0],
                ['CDC facility fit-out', 'CP-HW', 'TW-SEC', 140000000, 'NGN', 1, 'D', 'g', 'custom', 0],
                ['Incident response retainer (DFIR)', 'CP-OS', 'TW-SEC', 64000000, 'NGN', 0, 'C', 'r', 'equal', 1],
                ['SOC maturity assessment and training', 'CP-OS', 'TW-SEC', 28000000, 'NGN', 0, 'D', 'g', 'equal', 0],
                ['Deception technology pilot', 'CP-SW', 'TW-SEC', 95000, 'USD', 0, 'D', 't', 'back', 0],
            ],
        ],
    ];

    /** FY{y+1} pipeline: cost centre => [scenario, increment %]. */
    private const PIPELINE = [
        'kano-heritage-bank' => [
            'KHB-IT-INF' => ['draft', 8], 'KHB-IT-DC' => ['draft_breach', 12], 'KHB-IT-NET' => ['under_review', 9], 'KHB-IT-APP' => ['returned', 14],
            'KHB-IT-DIG' => ['submitted', 18], 'KHB-IT-TRY' => ['approved', 6], 'KHB-SEC' => ['rejected', 16], 'KHB-SEC-SOC' => ['scenarios', 10],
            'KHB-IT-ITSM' => ['unowned', 0],
        ],
        'first-bank-nigeria' => [
            'FBN-GT-INF' => ['draft', 7], 'FBN-GT-DC' => ['draft_breach', 11], 'FBN-GT-NET' => ['under_review', 8], 'FBN-GT-CBS' => ['returned', 13],
            'FBN-GT-DIG' => ['submitted', 17], 'FBN-GT-PAY' => ['approved', 5], 'FBN-GT-UK' => ['rejected', 15], 'FBN-GIS-SOC' => ['scenarios', 10],
            'FBN-GT-GH' => ['draft', 9], 'FBN-GIS' => ['draft', 12], 'FBN-GT-DATA' => ['unowned', 0],
        ],
    ];

    private BudgetService $budgets;

    private BudgetCycleService $cycles;

    private BudgetTransferService $transfers;

    private SupplementaryBudgetService $supplementary;

    private BudgetPhasing $phasing;

    private int $orgId;

    private string $prefix;

    private array $cat = [];

    /** @var array<string, User> */
    private array $who = [];

    private FiscalYear $fy;

    private FiscalYear $next;

    private ?FiscalYear $fyForAmounts = null;

    public function run(): void
    {
        $this->budgets = app(BudgetService::class);
        $this->cycles = app(BudgetCycleService::class);
        $this->transfers = app(BudgetTransferService::class);
        $this->supplementary = app(SupplementaryBudgetService::class);
        $this->phasing = app(BudgetPhasing::class);

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin) {
                continue;
            }
            $this->orgId = $org->id;
            $this->prefix = $bank['prefix'];
            $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_current', true)->first();
            $next = $fy ? FiscalYear::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('starts_on', '>', $fy->starts_on->toDateString())->orderBy('starts_on')->first() : null;
            if (! $fy || ! $next || ! CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                continue; // FinanceFoundationSeeder has not run for this bank
            }
            if (Budget::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                continue; // already seeded; guard is scoped to this bank
            }
            $this->fy = $fy;
            $this->next = $next;
            Auth::setUser($admin);
            mt_srand($bank['seed']);
            app(FinanceSettings::class)->ensureDefaults($this->orgId);

            $this->cat = Category::withoutGlobalScopes()->where('organization_id', $this->orgId)->pluck('id', 'code')->all();
            $this->users($slug, $bank);
            $this->doaBands($slug);
            $this->fxRates($bank['first']);

            Event::fakeFor(function () use ($slug) {
                $cycle1 = $this->cycle('FY'.$this->fy->starts_on->year.' Annual IT Budget', 'annual', $this->fy, ($this->fy->starts_on->year - 1).'-09-01', ($this->fy->starts_on->year - 1).'-11-30', 'Annual IT budget for the current year. Submit by cost centre; CapEx and OpEx are shown separately.');
                $cycle2 = $this->cycle('FY'.$this->fy->starts_on->year.' Mid-year reforecast', 'mid_year_reforecast', $this->fy, $this->fy->starts_on->year.'-06-01', $this->fy->starts_on->year.'-07-31', 'Mid-year reforecast: revise only the lines that moved.');
                $this->baselines($slug, $cycle1);
                $this->midYearRevision($slug, $cycle2);
                foreach ([$cycle1, $cycle2] as $c) {
                    $c = $c->refresh();
                    $this->cycles->transition($c, 'review', $this->who['finance']);
                    $this->cycles->transition($c->refresh(), 'close', $this->who['finance']);
                }
                FinancePolicySeeder::ensureDefaults($this->orgId);
                $this->historicTransfers($slug);
                $this->historicSupplementary($slug);
            }, [FinanceApprovalRequested::class, FinanceApprovalDecided::class]);

            $this->pendingTransfersAndSupplementary($slug);
            $this->nextYear($slug, $bank);
        }

        mt_srand();
        Auth::forgetUser();
    }

    /* ------------------------------------------------------------------ people, bands, rates */

    private function users(string $slug, array $bank): void
    {
        $specs = [
            // No IT Steering Committee role: stage 3 would let the MD pre-empt it and then be barred from the board stage (one stage per person).
            'md' => ['Executive', 'Managing Director/CEO', ['Finance Approver']],
            'headofit' => ['Technology', 'Head of IT Governance', ['Finance Approver']],
        ];
        foreach ($specs as $key => [$dept, $title, $roles]) {
            $user = User::updateOrCreate(['email' => "{$key}@{$bank['domain']}"], [
                'name' => self::NEW_USERS[$slug][$key], 'organization_id' => $this->orgId, 'job_title' => $title, 'department' => $dept,
                'password' => Hash::make('password'), 'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles($roles);
        }
        foreach (['finance', 'procurement', 'cfo', 'cio', 'md', 'headofit'] as $key) {
            $this->who[$key] = User::where('email', "{$key}@{$bank['domain']}")->firstOrFail();
        }
    }

    private function doaBands(string $slug): void
    {
        $svc = app(DoaBandService::class);
        $bands = array_map(fn ($b) => ['label' => $b[0], 'min' => $b[1], 'max' => $b[2]], self::DOA[$slug]);
        $y = $this->fy->starts_on->year;

        // Version 1 is the generic default created on first use; version 2 is the bank's own bands, maker finance / checker CFO.
        $v1 = $svc->active($this->orgId);
        $v1->forceFill(['effective_from' => ($y - 1).'-01-01'])->save();
        $v2 = $svc->propose($this->orgId, $bands, today()->toDateString(), 'Delegation of authority aligned to the board-approved limits', $this->who['finance']);
        $svc->approve($v2, $this->who['cfo'], 'Aligned to the board-approved limits');
        $v2->refresh()->forceFill(['effective_from' => "{$y}-01-01"])->save();
        app(FinanceSettings::class)->flush();

        if ($slug === 'kano-heritage-bank') { // pending uplift for the maker-checker demo: thresholds x1.5
            $uplift = [['Up to 7.5m', 0, 7500000], ['7.5m to 37.5m', 7500000, 37500000], ['37.5m to 150m', 37500000, 150000000], ['150m to 750m', 150000000, 750000000], ['Above 750m', 750000000, null]];
            $svc->propose($this->orgId, array_map(fn ($b) => ['label' => $b[0], 'min' => $b[1], 'max' => $b[2]], $uplift), ($y + 1).'-01-01', 'FY'.($y + 1).' inflation uplift', $this->who['finance']);
        }
    }

    private function fxRates(bool $isFirst): void
    {
        $y = $this->fy->starts_on->year;
        $codes = $isFirst ? ['USD', 'GBP', 'EUR', 'GHS'] : ['USD', 'GBP', 'EUR'];
        $upsert = function (string $ccy, string $date, string $type, float $rate) {
            FxRate::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $this->orgId, 'from_currency' => $ccy, 'to_currency' => 'NGN', 'rate_date' => $date, 'rate_type' => $type],
                ['rate' => round($rate, 4), 'source' => 'manual'],
            );
        };
        foreach ($codes as $ccy) {
            $base = self::RATES[$ccy];
            $upsert($ccy, ($y - 1).'-12-31', 'budget', $base);
            $upsert($ccy, "{$y}-09-30", 'budget', $base * 1.04);
            foreach ([3, 6, 9] as $q) { // closing rates at each closed quarter end
                $end = Carbon::create($y, $q, 1)->endOfMonth();
                if ($end->lt(now()->startOfMonth())) {
                    $upsert($ccy, $end->toDateString(), 'closing', $base * (1 + $q / 300 + mt_rand(-40, 40) / 10000));
                }
            }
        }
    }

    /* ------------------------------------------------------------------ cycles and baselines */

    private function cycle(string $name, string $type, FiscalYear $fy, string $opens, string $closes, string $instructions, bool $open = true): BudgetCycle
    {
        $cycle = $this->cycles->create($this->orgId, [
            'fiscal_year_id' => $fy->id, 'name' => $name, 'type' => $type, 'opens_on' => $opens, 'closes_on' => $closes,
            'instructions' => $instructions, 'coordinator_id' => $this->who['finance']->id,
        ], $this->who['finance']);
        $this->recode($cycle, 'cycle_code', 'BC-');
        $open && $this->cycles->transition($cycle, 'open', $this->who['finance']);

        return $cycle->refresh();
    }

    /** Re-prefix the first code of a bank's series ("BUD-0001" => "KHB-BUD-0001"); the trait continues the prefix after that. */
    private function recode(Model $m, string $column, string $default): void
    {
        $code = (string) $m->{$column};
        if (str_starts_with($code, $default)) {
            $m->forceFill([$column => "{$this->prefix}-{$code}"])->saveQuietly();
        }
    }

    private function cc(string $code): CostCentre
    {
        return CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', $code)->firstOrFail();
    }

    private function owner(CostCentre $cc): ?User
    {
        return $cc->owner_id ? User::find($cc->owner_id) : null;
    }

    /** @return array<string,mixed> */
    private function lineData(array $l): array
    {
        [$desc, $pool, $tower, $amount, $ccy, $capex, $pri, $rgt, $phasing, $rec] = $l;
        $towerParts = explode('/', $tower);
        $data = [
            'description' => $desc, 'cost_pool_id' => $this->cat[$pool], 'tower_id' => $this->cat[$towerParts[0]],
            'category_id' => isset($towerParts[1]) ? $this->cat[$towerParts[1]] : null, 'annual_amount' => (string) $amount, 'currency' => $ccy,
            'is_capex' => (bool) $capex, 'is_recurring' => (bool) $rec,
            'priority' => ['M' => 'mandatory', 'R' => 'regulatory', 'C' => 'committed', 'D' => 'discretionary'][$pri],
            'rgt' => ['r' => 'run', 'g' => 'grow', 't' => 'transform'][$rgt], 'phasing_method' => $phasing,
            'justification' => $pri === 'D' ? 'Supports the digital and efficiency agenda; sized from vendor quotations.' : 'Contractual or regulatory run cost.',
        ];
        if ($phasing === 'custom') {
            $data['amounts'] = $this->customAmounts((string) $amount);
        }

        return $data;
    }

    /** Random but exact monthly split of an annual amount (heavier in the second half). @return array<int,string> */
    private function customAmounts(string $annual): array
    {
        $periods = $this->phasing->periodsFor($this->fyForAmounts ?? $this->fy);
        $weights = [];
        foreach ($periods->values() as $i => $p) {
            $weights[] = (string) (($i >= 5 ? 6 : 3) + mt_rand(0, 4));
        }
        $spread = $this->phasing->spread($this->phasing->round($annual, 2), $weights, 2);

        return $periods->values()->mapWithKeys(fn ($p, $i) => [$p->id => $spread[$i]])->all();
    }

    private function baselines(string $slug, BudgetCycle $cycle): void
    {
        $this->fyForAmounts = $this->fy;
        $first = true;
        foreach (self::LINES[$slug] as $ccCode => $lines) {
            $cc = $this->cc($ccCode);
            $owner = $this->owner($cc) ?? $this->who['finance'];
            $b = $this->budgets->createVersion($cycle, $cc, 'zero_based', $owner, [
                'title' => 'FY'.$this->fy->starts_on->year.' IT budget: '.$cc->name,
                'executive_summary' => "Run-the-bank costs for {$cc->name}, with growth and transformation items prioritised by regulatory and contractual commitments.",
            ]);
            $first && $this->recode($b, 'budget_code', 'BUD-');
            $first = false;
            foreach ($lines as $l) {
                $this->budgets->addLine($b->refresh(), $this->lineData($l), $owner);
            }
            $this->budgets->submit($b->refresh(), $owner);
            $this->budgets->approve($b->refresh(), $this->who['cfo'], 'Within the guidance issued to cost centres.'); // unconfigured default path
            $this->budgets->baseline($b->refresh(), $this->who['finance']);
        }
        $this->fyForAmounts = null;
    }

    private function midYearRevision(string $slug, BudgetCycle $reforecast): void
    {
        $code = $slug === 'kano-heritage-bank' ? 'KHB-IT-DIG' : 'FBN-GT-DIG';
        $cc = $this->cc($code);
        $owner = $this->owner($cc);
        $v1 = Budget::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('baseline_slot', "{$this->fy->id}:{$cc->id}")->firstOrFail();
        $v2 = $this->budgets->revise($v1, $owner);
        $v2->forceFill(['cycle_id' => $reforecast->id, 'title' => $v1->title.' (mid-year reforecast)'])->saveQuietly();
        $lines = BudgetLine::withoutGlobalScopes()->where('budget_id', $v2->id)->orderBy('line_no')->get();
        $changes = [[1, '1.12'], [4, '0.85'], [5, '1.20']];
        foreach ($changes as [$no, $factor]) {
            $line = $lines->firstWhere('line_no', $no);
            if ($line) {
                $annual = $this->phasing->round(bcmul((string) $line->annual_amount, $factor, 8), 2);
                $this->budgets->updateLine($line, ['annual_amount' => $annual, 'phasing_method' => 'equal', 'justification' => 'Reforecast: updated vendor quotation and CBN-driven scope change.'], $owner);
            }
        }
        $this->budgets->submit($v2->refresh(), $owner);
        $this->budgets->approve($v2->refresh(), $this->who['cfo'], 'Reforecast accepted.');
        $this->budgets->baseline($v2->refresh(), $this->who['finance']);
    }

    /* ------------------------------------------------------------------ transfers & supplementary */

    private function baselineBudget(string $ccCode): Budget
    {
        $cc = $this->cc($ccCode);

        return Budget::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('baseline_slot', "{$this->fy->id}:{$cc->id}")->firstOrFail();
    }

    private function line(string $ccCode, string $needle): BudgetLine
    {
        return BudgetLine::withoutGlobalScopes()->where('budget_id', $this->baselineBudget($ccCode)->id)->where('description', 'ilike', "%{$needle}%")->orderBy('line_no')->firstOrFail();
    }

    private function openPeriods()
    {
        return app(BudgetLedger::class)->openPeriods($this->orgId, $this->fy->id);
    }

    private function transfer(array $data, User $by, string $stage): BudgetTransfer
    {
        $t = $this->transfers->create($data + ['fiscal_year_id' => $this->fy->id], $by);
        $this->recode($t, 'transfer_code', 'VIR-');
        if ($stage === 'draft') {
            return $t->refresh();
        }
        $t = $this->transfers->submit($t->refresh(), $by);
        if ($stage === 'submitted') {
            return $t->refresh();
        }
        if ($stage === 'rejected') {
            return $this->transfers->reject($t->refresh(), $this->who['finance'], 'Defer until the Q4 vendor invoices are confirmed.');
        }
        foreach (['finance', 'cfo', 'cio'] as $key) { // Finance Controller review, the CFO for cross cost centre moves, the CIO from the high-value band
            if ($t->refresh()->status === 'approved') {
                break;
            }
            try {
                $this->transfers->approve($t->refresh(), $this->who[$key], 'Reviewed.');
            } catch (ValidationException) {
            }
        }
        $t->refresh();
        if ($stage === 'applied' && $t->status === 'approved') {
            $t = $this->transfers->apply($t, $this->who['finance']);
        }

        return $t->refresh();
    }

    private function historicTransfers(string $slug): void
    {
        $k = $slug === 'kano-heritage-bank';
        $periods = $this->openPeriods();
        // 1) applied, within a cost centre between periods
        $svc = $this->line($k ? 'KHB-IT-INF' : 'FBN-GT-INF', 'Service desk');
        $by = $this->owner($this->cc($k ? 'KHB-IT-INF' : 'FBN-GT-INF'));
        if ($periods->count() >= 2) {
            $from = $periods[0];
            $to = $periods[1];
            $amount = $this->round(bcmul(app(BudgetLedger::class)->available($svc, $from), '0.2', 2));
            $this->transfer(['from_line_id' => $svc->id, 'to_line_id' => $svc->id, 'from_period_id' => $from->id, 'to_period_id' => $to->id, 'amount' => $amount,
                'reason' => 'Move the service desk true-up from the current period to next, matching the vendor invoice schedule.'], $by, 'applied');
        } else {
            $to = $this->line($k ? 'KHB-IT-INF' : 'FBN-GT-INF', 'training');
            $this->transfer(['from_line_id' => $svc->id, 'to_line_id' => $to->id, 'amount' => $this->open($svc, '0.05'), 'reason' => 'Fund certification from service desk savings.'], $by, 'applied');
        }

        // 2) applied, across cost centres (opex to opex): data centre cloud to network connectivity
        $cloud = $this->line($k ? 'KHB-IT-DC' : 'FBN-GT-DC', 'cloud hosting');
        $links = $this->line($k ? 'KHB-IT-NET' : 'FBN-GT-NET', 'Internet');
        $this->transfer(['from_line_id' => $cloud->id, 'to_line_id' => $links->id, 'amount' => $this->open($cloud, '0.08'),
            'reason' => 'Cloud consumption is below plan; connectivity needs capacity for the new branch cutovers.'], $this->owner($this->cc($k ? 'KHB-IT-DC' : 'FBN-GT-DC')), 'applied');

        // 3) approved, not yet applied (within a cost centre, different lines)
        $try = $k ? 'KHB-IT-TRY' : 'FBN-GT-PAY';
        $a = $this->line($try, $k ? 'contractors' : 'engineering contractors');
        $b = $this->line($try, $k ? 'Bloomberg' : 'NIBSS');
        $this->transfer(['from_line_id' => $a->id, 'to_line_id' => $b->id, 'amount' => $this->open($a, '0.1'),
            'reason' => 'Contractor budget under-used after a conversion to permanent staff; terminals and connectivity need top-up.'], $this->owner($this->cc($try)), 'approved');

        // 4) rejected
        $net = $this->line($k ? 'KHB-IT-NET' : 'FBN-GT-NET', 'Voice');
        $app = $this->line($k ? 'KHB-IT-APP' : 'FBN-GT-CBS', $k ? 'performance testing' : 'test automation');
        $this->transfer(['from_line_id' => $net->id, 'to_line_id' => $app->id, 'amount' => $this->open($net, '0.25'),
            'reason' => 'Move PBX maintenance saving into application testing tools.'], $this->owner($this->cc($k ? 'KHB-IT-NET' : 'FBN-GT-NET')), 'rejected');
    }

    private function open(BudgetLine $line, string $share): string
    {
        return $this->round(bcmul(app(BudgetLedger::class)->availableInOpenPeriods($line, $this->fy->id), $share, 2));
    }

    private function round(string $v): string
    {
        return bcadd(bcmul($v, '1', 0), '0', 2);
    }

    private function historicSupplementary(string $slug): void
    {
        $k = $slug === 'kano-heritage-bank';
        $socCode = $k ? 'KHB-SEC-SOC' : 'FBN-GIS-SOC';
        $soc = $this->owner($this->cc($socCode));
        $r = $this->supplementary->create([
            'fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc($socCode)->id, 'cost_pool_id' => $this->cat['CP-SW'], 'tower_id' => $this->cat['TW-SEC'],
            'category_id' => $this->cat['TW-SEC-SEC'], 'title' => 'CBN directive: enhanced fraud monitoring', 'is_capex' => false, 'currency' => 'NGN',
            'amount' => $k ? '35000000' : '240000000',
            'justification' => 'CBN circular requires real-time transaction monitoring for card-not-present and mobile transfers within the year; not in the approved baseline.',
        ], $soc);
        $this->recode($r, 'request_code', 'SUP-');
        $this->supplementary->submit($r->refresh(), $soc);
        foreach (['headofit', 'cfo', 'cio'] as $key) {
            if ($r->refresh()->status === 'approved') {
                break;
            }
            try {
                $this->supplementary->approve($r->refresh(), $this->who[$key], 'Supported: regulatory driver.');
            } catch (ValidationException) {
            }
        }
        $this->supplementary->apply($r->refresh(), $this->who['finance']);
    }

    private function pendingTransfersAndSupplementary(string $slug): void
    {
        $k = $slug === 'kano-heritage-bank';
        // Pending at "Finance Controller review": a cross-cost-centre transfer from Applications to Digital Channels (real events)
        $appCode = $k ? 'KHB-IT-APP' : 'FBN-GT-CBS';
        $digCode = $k ? 'KHB-IT-DIG' : 'FBN-GT-DIG';
        $from = $this->line($appCode, $k ? 'switching fees' : 'switching fees');
        $to = $this->line($digCode, 'USSD');
        $this->transfer(['from_line_id' => $from->id, 'to_line_id' => $to->id, 'amount' => $this->open($from, '0.06'),
            'reason' => 'USSD volumes are 14% above plan after the agent-banking launch; switching fees are tracking under budget.'], $this->owner($this->cc($appCode)), 'submitted');

        // Draft transfer
        $dig = $this->line($digCode, $k ? 'Customer analytics' : 'Open banking');
        $bot = $this->line($digCode, $k ? 'Chatbot' : 'Conversational');
        $this->transfer(['from_line_id' => $dig->id, 'to_line_id' => $bot->id, 'amount' => $this->open($dig, '0.1'), 'reason' => 'Shift analytics licences budget to the assistant pilot.'], $this->owner($this->cc($digCode)), 'draft');

        // Supplementary: one pending at Head of IT review, one draft
        $infCode = $k ? 'KHB-IT-INF' : 'FBN-GT-INF';
        $owner = $this->owner($this->cc($infCode));
        $pending = $this->supplementary->create([
            'fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc($infCode)->id, 'cost_pool_id' => $this->cat['CP-HW'], 'tower_id' => $this->cat['TW-NET'],
            'category_id' => $this->cat['TW-NET-LAN'], 'title' => 'Emergency core switch replacement', 'is_capex' => true, 'currency' => 'USD',
            'amount' => $k ? '18000' : '95000', 'justification' => 'Core switch failed its hardware health check twice; vendor end-of-support was brought forward.',
        ], $owner);
        $this->supplementary->submit($pending->refresh(), $owner);

        $netCode = $k ? 'KHB-IT-NET' : 'FBN-GT-NET';
        $this->supplementary->create([
            'fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc($netCode)->id, 'cost_pool_id' => $this->cat['CP-TEL'], 'tower_id' => $this->cat['TW-NET'],
            'category_id' => $this->cat['TW-NET-INT'], 'title' => 'Branch connectivity upgrade for agent banking', 'is_capex' => false, 'currency' => 'NGN',
            'amount' => $k ? '9600000' : '64000000', 'justification' => 'Additional bandwidth for 120 agent-banking locations.',
        ], $this->owner($this->cc($netCode)));
    }

    /* ------------------------------------------------------------------ FY{y+1} */

    private function nextYear(string $slug, array $bank): void
    {
        $y = $this->next->starts_on->year;
        $cycle = $this->cycle("FY{$y} Annual IT Budget", 'annual', $this->next, ($y - 1).'-09-01', ($y - 1).'-11-30',
            "Submit FY{$y} budgets by cost centre. Start from the current baseline; justify every discretionary line. Ceilings are set by Finance per cost centre and tower.");
        $this->fyForAmounts = $this->next;
        $bankIsFirst = $bank['first'];

        foreach (self::PIPELINE[$slug] as $ccCode => [$scenario, $pct]) {
            $cc = $this->cc($ccCode);
            $owner = $this->owner($cc);
            $b = $scenario === 'unowned'
                ? $this->unownedDraft($cycle, $cc)
                : $this->budgets->createVersion($cycle, $cc, 'incremental', $owner, ['increment_pct' => $pct, 'title' => "FY{$y} IT budget: {$cc->name}",
                    'executive_summary' => "Incremental on the FY{$this->fy->starts_on->year} baseline (+{$pct}%), with growth driven by digital volumes and regulatory change."]);
            $b = $b->refresh();

            switch ($scenario) {
                case 'draft_breach':
                    // a discretionary line with no vendor or justification: deliberate gap
                    $this->budgets->addLine($b, ['description' => 'AI-assisted SOC triage pilot', 'cost_pool_id' => $this->cat['CP-SW'], 'tower_id' => $this->cat['TW-SEC'],
                        'annual_amount' => $bankIsFirst ? '95000000' : '28000000', 'currency' => 'NGN', 'priority' => 'discretionary', 'rgt' => 'transform', 'phasing_method' => 'back'], $owner);
                    break;
                case 'submitted':
                    $this->budgets->submit($b, $owner);
                    break;
                case 'under_review':
                    $this->budgets->submit($b, $owner);
                    $this->budgets->approve($b->refresh(), $this->who['headofit'], 'Within guidance; pass to the CFO.');
                    break;
                case 'approved':
                    $this->budgets->submit($b, $owner);
                    $this->approveThrough($b);
                    break;
                case 'rejected':
                    $this->budgets->submit($b, $owner);
                    $this->budgets->approve($b->refresh(), $this->who['headofit'], 'Technically sound.');
                    $this->budgets->reject($b->refresh(), $this->who['cfo'], 'The uplift is above the 12% guidance and the PAM and DLP items duplicate funded work. Resubmit with a lower ask.');
                    break;
                case 'returned':
                    $this->budgets->submit($b, $owner);
                    $lines = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->orderBy('line_no')->get();
                    $this->budgets->returnToDraft($b->refresh(), $this->who['headofit'], 'Returned: please tighten the justification on the transformation items.', [
                        ['line_id' => $lines[0]->id, 'body' => 'Show the vendor quotation behind this uplift.'],
                        ['line_id' => $lines[count($lines) - 1]->id, 'body' => 'Split the programme into funded phases before resubmitting.'],
                    ]);
                    break;
                case 'scenarios':
                    $this->scenarios($b, $owner);
                    break;
            }
        }

        $this->targets($cycle, $slug);
        $this->fyForAmounts = null;
    }

    private function unownedDraft(BudgetCycle $cycle, CostCentre $cc): Budget
    {
        $b = $this->budgets->createVersion($cycle, $cc, 'zero_based', $this->who['finance'], ['owner_id' => null, 'title' => 'FY'.$this->next->starts_on->year.' IT budget: '.$cc->name]);
        foreach ([
            ['ITSM platform licences (ServiceNow)', 'CP-SW', 'TW-DEL/TW-DEL-ITSM', 18000000, 'NGN', 0, 'C', 'r', 'equal', 1],
            ['Configuration management database clean-up', 'CP-OS', 'TW-DEL/TW-DEL-ITSM', 6500000, 'NGN', 0, 'D', 'g', 'equal', 0],
        ] as $l) {
            $this->budgets->addLine($b->refresh(), $this->lineData($l), $this->who['finance']);
        }

        return $b->refresh();
    }

    private function approveThrough(Budget $b): void
    {
        for ($i = 0; $i < 6 && $b->refresh()->status !== 'approved'; $i++) {
            foreach (['headofit', 'cfo', 'cio', 'md'] as $key) {
                try {
                    $this->budgets->approve($b->refresh(), $this->who[$key], 'Approved.');

                    continue 2;
                } catch (ValidationException) {
                }
            }
            break;
        }
    }

    private function scenarios(Budget $b, ?User $owner): void
    {
        $cut = $this->budgets->scenarioSave($b, ['name' => 'Cut 10 %', 'adjustment_pct' => -10]);
        $this->budgets->scenarioSave($b, ['name' => 'Growth +8 %', 'adjustment_pct' => 8]);
        $this->budgets->promoteScenario($cut, $owner ?? $this->who['finance']);
    }

    private function targets(BudgetCycle $cycle, string $slug): void
    {
        $k = $slug === 'kano-heritage-bank';
        $specs = $k
            ? [['KHB-IT', null, 1.20, 1.15], ['KHB-SEC', null, 1.25, 1.20], ['KHB-IT-DC', null, 1.30, 0.90], ['KHB-IT-NET', null, 1.10, 1.08], ['KHB-IT-APP', null, 1.15, 1.10], ['KHB-SEC-SOC', null, 1.20, 1.10], [null, 'TW-SEC', 1.30, 1.25]]
            : [['FBN-GT', null, 1.20, 1.15], ['FBN-GIS', null, 1.25, 1.20], ['FBN-GT-DC', null, 1.30, 0.90], ['FBN-GT-NET', null, 1.10, 1.08], ['FBN-GT-CBS', null, 1.15, 1.10], ['FBN-GIS-SOC', null, 1.20, 1.10], [null, 'TW-SEC', 1.30, 1.25]];

        foreach ($specs as [$ccCode, $catCode, $capexFactor, $opexFactor]) {
            $scope = ['cost_centre_id' => $ccCode ? $this->cc($ccCode)->id : null, 'category_id' => $catCode ? $this->cat[$catCode] : null];
            $probe = $this->cycles->saveTarget($cycle, $scope + ['capex_ceiling' => '9000000000000', 'opex_ceiling' => '9000000000000'], $this->who['finance']);
            $usage = $this->cycles->usageRow($probe, $this->cycles->cycleLines($cycle));
            $ceil = fn (string $requested, float $factor) => $this->round(bcmul(bccomp($requested, '0', 2) === 0 ? '1000000' : $requested, (string) $factor, 2));
            $this->cycles->saveTarget($cycle, $scope + [
                'capex_ceiling' => $ceil($usage['capex_requested'], $capexFactor), 'opex_ceiling' => $ceil($usage['opex_requested'], $opexFactor),
                'notes' => $opexFactor < 1 ? 'Finance ceiling set below last year\'s run-rate plus inflation.' : 'Guidance ceiling for the cycle.',
            ], $this->who['finance'], $probe);
        }
    }
}

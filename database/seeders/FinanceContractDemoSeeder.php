<?php

namespace Database\Seeders;

use App\Events\Finance\ContractRenewed;
use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FxRate;
use App\Models\Finance\Renewal;
use App\Models\Finance\Termination;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Ea\PdfWriter;
use App\Services\Finance\ContractService;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\RenewalService;
use App\Services\Finance\TerminationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * IT Financial Management P2 demo data (ATH-FIN-001 §13, plan §6.1): the contract register of each demo bank with
 * every status, a contract in every calendar lane (window open, notice deadline passed, lapsed), an auto-renewing
 * contract and one that already rolled, a terminated and a replaced contract, renewals in every stage with workflow
 * approvals that always have someone able to decide, team, links, documents and past T-minus notification history.
 *
 * Every date is relative to the finance "today". Deliberate gaps are kept: an unowned contract, an over-licensed
 * one, a processor contract with its DPA still pending, a draft. Guarded per bank on the contract register, so it
 * also fills databases that were seeded before P2 existed.
 */
class FinanceContractDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => [
            'prefix' => 'KHB', 'admin' => 'admin@kanoheritage.ng', 'domain' => 'kanoheritage.ng', 'seed' => 8121, 'first' => false,
            'users' => ['ops' => 'itops', 'net' => 'network', 'cbs' => 'finacle', 'dig' => 'digital', 'try' => 'treasury.it', 'soc' => 'soc.analyst', 'sec' => 'admin', 'sys' => 'sysadmin'],
            'cc' => ['INF' => 'KHB-IT-INF', 'DC' => 'KHB-IT-DC', 'NET' => 'KHB-IT-NET', 'APP' => 'KHB-IT-APP', 'DIG' => 'KHB-IT-DIG', 'TRY' => 'KHB-IT-TRY', 'SEC' => 'KHB-SEC', 'SOC' => 'KHB-SEC-SOC'],
        ],
        'first-bank-nigeria' => [
            'prefix' => 'FBN', 'admin' => 'admin@firstbanknigeria.ng', 'domain' => 'firstbanknigeria.ng', 'seed' => 8122, 'first' => true,
            'users' => ['ops' => 'itops', 'net' => 'network', 'cbs' => 'finacle.app', 'dig' => 'digital', 'try' => 'treasury.it', 'soc' => 'soc', 'sec' => 'ciso', 'sys' => 'sysadmin'],
            'cc' => ['INF' => 'FBN-GT-INF', 'DC' => 'FBN-GT-DC', 'NET' => 'FBN-GT-NET', 'APP' => 'FBN-GT-CBS', 'DIG' => 'FBN-GT-DIG', 'TRY' => 'FBN-GT-PAY', 'SEC' => 'FBN-GIS', 'SOC' => 'FBN-GIS-SOC', 'GH' => 'FBN-GT-GH', 'UK' => 'FBN-GT-UK'],
        ],
    ];

    /** Spot rates against NGN, struck a week ago (contracts convert at the spot rate; P1 only seeded budget and closing rates). */
    private const SPOT = ['USD' => 1528, 'GBP' => 2061, 'EUR' => 1779, 'GHS' => 131];

    /** Scale from Kano's figures to First Bank's (a bigger estate), unless the spec gives an explicit `fv`. */
    private const SCALE = ['NGN' => 6, 'USD' => 4.3, 'GBP' => 2.5, 'GHS' => 2.5];

    /**
     * The register. k key; ti title (string or [kano, fbn]); ty type; v vendor LIKE pattern (vn = name to create when missing);
     * cc/o/to cost-centre, owner and technical owner aliases; end days from today; term months; n notice days; ccy; val (Kano,
     * fv First Bank); b billing; rt renewal type; esc escalation %; scn scenario; only = single-bank contract.
     * Link needles (asset, risk, ea, bs) and `line` (budget line) are matched case-insensitively.
     */
    private function specs(): array
    {
        return [
            ['k' => 'finacle', 'ti' => 'Finacle core banking AMC (Infosys)', 'ty' => 'support_maintenance', 'v' => '%infosys%', 'cc' => 'APP', 'o' => 'cbs', 'to' => 'ops', 'end' => 80, 'term' => 12, 'n' => 45, 'ccy' => 'USD', 'val' => 310000, 'fv' => 1350000, 'b' => 'quarterly', 'esc' => 4, 'scn' => 'pending_cfo', 'major' => 1,
                'line' => 'finacle core banking amc', 'asset' => 'finacle core banking (prod)', 'risk' => 'finacle', 'ea' => 'finacle core banking (prod)', 'bs' => 'finacle', 'ref' => 'PROC/CBS/2024/011'],
            ['k' => 'msea', 'ti' => 'Microsoft Enterprise Agreement (M365, Windows Server, SQL Server)', 'ty' => 'licence', 'v' => '%microsoft%', 'cc' => 'INF', 'o' => 'ops', 'to' => 'sys', 'end' => 75, 'term' => 36, 'n' => 30, 'ccy' => 'USD', 'val' => 96000, 'fv' => 640000, 'b' => 'annual', 'esc' => 3, 'scn' => 'window_no_renewal', 'major' => 1,
                'lm' => 'user', 'lq' => [1200, 11500], 'dq' => [1342, 12380], 'dp' => ['processor', 'signed'], 'line' => 'microsoft enterprise agreement', 'asset' => 'microsoft 365', 'risk' => 'active directory', 'ea' => 'microsoft', 'bs' => 'entra', 'ref' => 'PROC/LIC/2023/004'],
            ['k' => 'oracle', 'ti' => 'Oracle Database support and licences', 'ty' => 'support_maintenance', 'v' => '%oracle%', 'cc' => 'DC', 'o' => 'ops', 'to' => 'sys', 'end' => 210, 'term' => 12, 'n' => 60, 'ccy' => 'USD', 'val' => 62000, 'fv' => 410000, 'b' => 'quarterly', 'esc' => 8, 'major' => 1,
                'lm' => 'core', 'lq' => [64, 256], 'dq' => [64, 240], 'line' => 'oracle', 'asset' => 'oracle', 'risk' => 'database', 'ea' => 'flexcube', 'bs' => 'core deposits'],
            ['k' => 'cisco', 'ti' => 'Cisco SmartNet support', 'ty' => 'support_maintenance', 'v' => '%cisco%', 'cc' => 'NET', 'o' => 'net', 'to' => 'ops', 'end' => 60, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 38000, 'fv' => 290000, 'b' => 'annual', 'scn' => 'draft_recommendation',
                'line' => 'cisco smartnet', 'asset' => 'core switch', 'risk' => 'network', 'ea' => 'network'],
            ['k' => 'firewall', 'ti' => ['Fortinet FortiGate firewall support', 'Palo Alto Networks firewall support'], 'ty' => 'support_maintenance', 'v' => ['%fortinet%', '%palo alto%'], 'vn' => 'Fortinet', 'cc' => 'NET', 'o' => 'net', 'to' => 'soc', 'end' => 20, 'term' => 12, 'n' => 60, 'ccy' => 'USD', 'val' => 54000, 'fv' => 380000, 'b' => 'annual',
                'line' => ['fortinet', 'palo alto'], 'asset' => 'firewall', 'risk' => 'perimeter', 'ea' => 'firewall', 'bs' => 'firewall'],
            ['k' => 'crowdstrike', 'ti' => 'CrowdStrike Falcon endpoint protection', 'ty' => 'subscription', 'v' => '%crowdstrike%', 'vn' => 'CrowdStrike', 'cc' => 'SEC', 'o' => 'sec', 'to' => 'soc', 'end' => 300, 'term' => 12, 'n' => 60, 'ccy' => 'USD', 'val' => 64000, 'fv' => 430000, 'b' => 'annual', 'dp' => ['processor', 'signed'], 'major' => 1,
                'line' => 'crowdstrike', 'asset' => 'windows server', 'risk' => 'ransomware', 'ea' => 'endpoint'],
            ['k' => 'interswitch', 'ti' => 'Interswitch switching and settlement services', 'ty' => 'managed_service', 'v' => '%interswitch%', 'cc' => 'APP', 'o' => 'dig', 'to' => 'cbs', 'end' => 95, 'term' => 12, 'n' => 30, 'ccy' => 'NGN', 'val' => 41000000, 'b' => 'usage', 'scn' => 'pending_head', 'major' => 1,
                'line' => 'interswitch', 'asset' => 'payments hub', 'risk' => 'interswitch', 'ea' => 'payments hub', 'bs' => 'card switching'],
            ['k' => 'nibss', 'ti' => 'NIBSS NIP and NQR connectivity', 'ty' => 'managed_service', 'v' => '%nibss%', 'cc' => 'APP', 'o' => 'cbs', 'to' => 'ops', 'end' => 365, 'term' => 24, 'n' => 90, 'ccy' => 'NGN', 'val' => 18000000, 'b' => 'quarterly',
                'line' => 'nibss', 'asset' => 'payments hub', 'risk' => 'nibss', 'ea' => 'payments hub', 'bs' => 'nip'],
            ['k' => 'mtn', 'ti' => 'MTN MPLS branch WAN', 'ty' => 'telecom', 'v' => '%mtn%', 'cc' => 'NET', 'o' => 'net', 'to' => 'ops', 'end' => 120, 'term' => 12, 'n' => 30, 'ccy' => 'NGN', 'val' => 46800000, 'b' => 'monthly', 'rt' => 'auto', 'esc' => 5, 'major' => 1,
                'line' => 'wan', 'asset' => 'vpn', 'risk' => 'network', 'ea' => 'network', 'bs' => 'branch'],
            ['k' => 'airtel', 'ti' => 'Airtel internet and backup links', 'ty' => 'telecom', 'v' => '%airtel%', 'cc' => 'NET', 'o' => 'net', 'to' => 'ops', 'end' => -10, 'term' => 12, 'n' => 30, 'ccy' => 'NGN', 'val' => 7200000, 'b' => 'monthly', 'rt' => 'auto', 'esc' => 5, 'scn' => 'auto_rolled',
                'line' => 'internet', 'asset' => 'vpn', 'risk' => 'network', 'ea' => 'network', 'bs' => 'internet'],
            ['k' => 'glo', 'ti' => 'Globacom branch connectivity', 'ty' => 'telecom', 'v' => '%globacom%', 'cc' => 'NET', 'o' => 'net', 'to' => 'ops', 'end' => 330, 'term' => 24, 'n' => 60, 'ccy' => 'NGN', 'val' => 14400000, 'b' => 'monthly', 'line' => 'wan', 'asset' => 'vpn', 'risk' => 'network', 'ea' => 'network', 'bs' => 'branch'],
            ['k' => 'aws', 'ti' => 'AWS cloud commitment (digital channels)', 'ty' => 'cloud_commitment', 'v' => '%amazon%', 'cc' => 'DC', 'o' => 'ops', 'to' => 'dig', 'end' => 260, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 84000, 'fv' => 520000, 'b' => 'monthly', 'dp' => ['processor', 'signed'], 'major' => 1,
                'line' => 'aws and azure', 'asset' => 'aws production', 'risk' => 'cloud', 'ea' => 'mobile', 'bs' => 'mobile'],
            ['k' => 'azure', 'ti' => 'Azure cloud commitment', 'ty' => 'cloud_commitment', 'v' => '%microsoft%', 'cc' => 'DIG', 'o' => 'dig', 'to' => 'ops', 'end' => 180, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 42000, 'fv' => 360000, 'b' => 'monthly', 'dp' => ['processor', 'signed'],
                'line' => 'azure hosting', 'asset' => 'microsoft 365', 'risk' => 'cloud', 'ea' => 'internet', 'bs' => 'internet'],
            ['k' => 'rack', 'ti' => ['Rack Centre colocation (DR site)', 'Rack Centre colocation (Lagos DR)'], 'ty' => 'lease', 'v' => '%rack centre%', 'cc' => 'DC', 'o' => 'ops', 'to' => 'sys', 'end' => 540, 'term' => 36, 'n' => 90, 'ccy' => 'NGN', 'val' => 38400000, 'b' => 'monthly', 'esc' => 7,
                'line' => 'power', 'asset' => 'data centre', 'risk' => 'data-centre', 'ea' => 'dr', 'bs' => 'data cent'],
            ['k' => 'qualys', 'ti' => 'Qualys vulnerability management subscription', 'ty' => 'subscription', 'v' => '%qualys%', 'cc' => 'SEC', 'o' => 'sec', 'to' => 'soc', 'end' => -12, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 8000, 'fv' => 34400, 'b' => 'annual',
                'asset' => 'windows server', 'risk' => 'vulnerab', 'ea' => 'endpoint'],
            ['k' => 'pwc', 'ti' => 'PwC IT governance advisory retainer', 'ty' => 'professional_services', 'v' => '%pwc%', 'cc' => 'SEC', 'o' => 'sec', 'to' => 'soc', 'end' => -58, 'term' => 12, 'n' => 30, 'ccy' => 'NGN', 'val' => 9800000, 'b' => 'one_off', 'risk' => 'governance'],
            ['k' => 'kpmg', 'ti' => 'KPMG IT audit and CSAT advisory', 'ty' => 'professional_services', 'v' => '%kpmg%', 'cc' => 'SEC', 'o' => 'sec', 'to' => 'soc', 'end' => 200, 'term' => 12, 'n' => 30, 'ccy' => 'NGN', 'val' => 14500000, 'b' => 'one_off', 'risk' => 'csat'],
            ['k' => 'infobip', 'ti' => 'Infobip SMS gateway (legacy)', 'ty' => 'saas', 'v' => '%infobip%', 'cc' => 'DIG', 'o' => 'dig', 'to' => 'ops', 'end' => 45, 'term' => 12, 'n' => 30, 'ccy' => 'NGN', 'val' => 5400000, 'b' => 'usage', 'scn' => 'terminated', 'dp' => ['processor', 'signed'],
                'asset' => 'ussd', 'risk' => 'sms', 'ea' => 'ussd', 'bs' => 'ussd'],
            ['k' => 'swift', 'ti' => 'SWIFT Alliance Access licence and connectivity', 'ty' => 'licence', 'v' => '%swift%', 'cc' => 'TRY', 'o' => 'try', 'to' => 'ops', 'end' => 170, 'term' => 12, 'n' => 60, 'ccy' => 'USD', 'val' => 26000, 'fv' => 140000, 'b' => 'annual', 'lm' => 'instance', 'lq' => [2, 4], 'dq' => [2, 4],
                'line' => 'swift', 'asset' => 'swift', 'risk' => 'swift', 'ea' => 'swift', 'bs' => 'swift'],
            ['k' => 'dell', 'ti' => ['Dell PowerEdge hardware maintenance (3-year)', 'Dell and HPE server and storage maintenance'], 'ty' => 'hardware_warranty', 'v' => '%dell%', 'vn' => 'Dell Technologies', 'cc' => 'INF', 'o' => 'ops', 'to' => 'sys', 'end' => 270, 'term' => 36, 'n' => 60, 'ccy' => 'NGN', 'val' => 18500000, 'b' => 'annual',
                'line' => 'maintenance', 'asset' => 'aix', 'risk' => 'eol', 'ea' => 'core banking'],
            ['k' => 'cloudflare', 'ti' => 'Cloudflare WAF and DDoS protection', 'ty' => 'subscription', 'v' => '%cloudflare%', 'cc' => 'DIG', 'o' => 'dig', 'to' => 'soc', 'end' => 35, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 24000, 'fv' => 110000, 'b' => 'annual', 'dp' => ['processor', 'pending'],
                'asset' => 'internet banking', 'risk' => 'ddos', 'ea' => 'internet', 'bs' => 'internet'],
            ['k' => 'africastalking', 'ti' => "Africa's Talking USSD and SMS aggregator", 'ty' => 'saas', 'v' => '%talking%', 'cc' => 'DIG', 'o' => 'dig', 'to' => 'ops', 'end' => 470, 'term' => 24, 'n' => 60, 'ccy' => 'NGN', 'val' => 19200000, 'b' => 'usage', 'dp' => ['processor', 'signed'],
                'line' => 'ussd', 'asset' => 'ussd', 'risk' => 'sms', 'ea' => 'ussd', 'bs' => 'ussd'],
            ['k' => 'galaxy', 'ti' => 'Galaxy Backbone government connectivity', 'ty' => 'telecom', 'v' => '%galaxy%', 'cc' => 'NET', 'o' => 'net', 'to' => 'ops', 'end' => 230, 'term' => 12, 'n' => 60, 'ccy' => 'NGN', 'val' => 9600000, 'b' => 'monthly', 'scn' => 'unowned', 'asset' => 'vpn', 'risk' => 'network', 'ea' => 'network', 'bs' => 'branch'],
            ['k' => 'tenable', 'ti' => 'Tenable.io vulnerability scanning', 'ty' => 'subscription', 'v' => '%tenable%', 'cc' => 'SEC', 'o' => 'sec', 'to' => 'soc', 'end' => 30, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 5500, 'fv' => 5500, 'b' => 'annual', 'scn' => 'executed',
                'asset' => 'windows server', 'risk' => 'vulnerab', 'ea' => 'endpoint'],
            ['k' => 'proofpoint', 'ti' => 'Proofpoint email security', 'ty' => 'subscription', 'v' => '%proofpoint%', 'cc' => 'SEC', 'o' => 'sec', 'to' => 'ops', 'end' => 7, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 6000, 'fv' => 6500, 'b' => 'annual', 'scn' => 'approved', 'dp' => ['processor', 'signed'],
                'lm' => 'user', 'lq' => [900, 8000], 'dq' => [960, 8400], 'asset' => 'microsoft 365', 'risk' => 'phishing', 'ea' => 'email', 'bs' => 'email'],
            ['k' => 'thales', 'ti' => 'Thales Luna HSM support', 'ty' => 'support_maintenance', 'v' => '%thales%', 'cc' => 'SEC', 'o' => 'sec', 'to' => 'sys', 'end' => 55, 'term' => 12, 'n' => 30, 'ccy' => 'USD', 'val' => 12000, 'fv' => 60000, 'b' => 'annual', 'scn' => 'rejected',
                'asset' => 'hsm', 'risk' => 'key', 'ea' => 'hsm', 'bs' => 'hsm'],
            ['k' => 'etranzact', 'ti' => 'eTranzact card processing services', 'ty' => 'managed_service', 'v' => '%tranzact%', 'cc' => 'APP', 'o' => 'cbs', 'to' => 'ops', 'end' => 15, 'term' => 12, 'n' => 30, 'ccy' => 'NGN', 'val' => 12000000, 'b' => 'quarterly', 'scn' => 'replaced',
                'asset' => 'atm', 'risk' => 'atm', 'ea' => 'atm', 'bs' => 'atm'],
            ['k' => 'draft', 'ti' => ['Palo Alto zero-trust access (proposal)', 'CyberArk privileged access (proposal)'], 'ty' => 'subscription', 'v' => ['%palo alto%', '%cyberark%'], 'cc' => 'SEC', 'o' => 'sec', 'to' => 'soc', 'end' => 395, 'term' => 12, 'n' => 60, 'ccy' => 'USD', 'val' => 41000, 'fv' => 380000, 'b' => 'annual', 'scn' => 'draft',
                'asset' => 'privileged', 'risk' => 'privileged', 'ea' => 'identity'],
            // First Bank only
            ['k' => 'temenos', 'ti' => 'Temenos T24 licence and support', 'ty' => 'licence', 'v' => '%temenos%', 'cc' => 'APP', 'o' => 'cbs', 'to' => 'ops', 'end' => 140, 'term' => 36, 'n' => 90, 'ccy' => 'USD', 'fv' => 520000, 'b' => 'quarterly', 'only' => 'first-bank-nigeria', 'esc' => 6, 'major' => 1,
                'lm' => 'enterprise', 'lq' => [1, 1], 'dq' => [1, 1], 'ea' => 'temenos', 'bs' => 'core deposits', 'asset' => 'core'],
            ['k' => 'temenos_uk', 'ti' => 'Temenos Transact hosting (UK)', 'ty' => 'cloud_commitment', 'v' => '%temenos%', 'cc' => 'UK', 'o' => 'ops', 'to' => 'sys', 'end' => 190, 'term' => 12, 'n' => 60, 'ccy' => 'GBP', 'fv' => 410000, 'b' => 'monthly', 'only' => 'first-bank-nigeria', 'dp' => ['processor', 'signed'],
                'line' => 'temenos', 'ea' => 'temenos', 'bs' => 'core deposits'],
            ['k' => 'equinix', 'ti' => 'London colocation (Equinix)', 'ty' => 'lease', 'v' => '%equinix%', 'cc' => 'UK', 'o' => 'ops', 'to' => 'sys', 'end' => 400, 'term' => 24, 'n' => 90, 'ccy' => 'GBP', 'fv' => 160000, 'b' => 'monthly', 'only' => 'first-bank-nigeria', 'line' => 'colocation', 'asset' => 'data centre', 'bs' => 'data cent'],
            ['k' => 'adc', 'ti' => 'Accra colocation (Africa Data Centres)', 'ty' => 'lease', 'v' => '%africa data%', 'cc' => 'GH', 'o' => 'ops', 'to' => 'sys', 'end' => 285, 'term' => 24, 'n' => 90, 'ccy' => 'GHS', 'fv' => 300000, 'b' => 'quarterly', 'only' => 'first-bank-nigeria', 'line' => 'colocation', 'asset' => 'data centre', 'bs' => 'data cent'],
            ['k' => 'gloghana', 'ti' => 'Ghana branch network links (Glo)', 'ty' => 'telecom', 'v' => '%glo mobile%', 'cc' => 'GH', 'o' => 'net', 'to' => 'ops', 'end' => 100, 'term' => 12, 'n' => 30, 'ccy' => 'GHS', 'fv' => 360000, 'b' => 'monthly', 'only' => 'first-bank-nigeria', 'line' => 'links', 'asset' => 'vpn', 'bs' => 'branch'],
            ['k' => 'mastercard', 'ti' => 'Mastercard scheme licence and certification', 'ty' => 'licence', 'v' => '%mastercard%', 'cc' => 'TRY', 'o' => 'try', 'to' => 'ops', 'end' => 450, 'term' => 36, 'n' => 90, 'ccy' => 'USD', 'fv' => 180000, 'b' => 'annual', 'only' => 'first-bank-nigeria', 'line' => 'card scheme', 'ea' => 'payments', 'bs' => 'pos', 'asset' => 'pos'],
        ];
    }

    private ContractService $contracts;

    private RenewalService $renewals;

    private int $orgId;

    private string $prefix;

    private string $slug;

    private array $bank;

    /** @var array<string, User> */
    private array $who = [];

    private array $pool = [];

    private int $vendorSeq = 900;

    private CarbonImmutable $today;

    public function run(): void
    {
        $this->contracts = app(ContractService::class);
        $this->renewals = app(RenewalService::class);

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin) {
                continue;
            }
            $this->orgId = $org->id;
            $this->prefix = $bank['prefix'];
            $this->slug = $slug;
            $this->bank = $bank;
            if (! CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                continue; // FinanceFoundationSeeder has not run for this bank
            }
            $this->today = FinanceClock::today();
            Auth::setUser($admin);
            if (Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                // Already seeded; the guard is scoped to this bank. A second pass only adds the business-service links,
                // because AssetManagementDemoSeeder creates those services after this seeder first runs.
                $this->people($bank);
                $this->topUpServiceLinks();
                $this->seedP21(); // banks seeded before P2.1 get the thresholds, the termination workflow stages and a pending request

                continue;
            }
            mt_srand($bank['seed']);
            app(FinanceSettings::class)->ensureDefaults($this->orgId);
            FinancePolicySeeder::ensureDefaults($this->orgId);
            $this->people($bank);
            $this->spotRates($bank['first']);

            $this->seedBank();
        }

        mt_srand();
        Auth::forgetUser();
    }

    /* ------------------------------------------------------------------ people and rates */

    private function people(array $bank): void
    {
        foreach (['finance', 'procurement', 'cfo', 'cio', 'headofit'] as $key) {
            $this->who[$key] = User::where('email', "{$key}@{$bank['domain']}")->firstOrFail();
        }
        foreach ($bank['users'] as $alias => $local) {
            $this->who[$alias] = User::where('email', "{$local}@{$bank['domain']}")->firstOrFail();
        }
        $this->pool = array_values(array_filter(['ops', 'net', 'cbs', 'dig', 'try', 'soc', 'sec', 'sys'], fn ($a) => isset($this->who[$a])));
    }

    private function spotRates(bool $isFirst): void
    {
        $date = $this->today->subDays(7)->toDateString();
        foreach (self::SPOT as $ccy => $rate) {
            if ($ccy === 'GHS' && ! $isFirst) {
                continue;
            }
            FxRate::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $this->orgId, 'from_currency' => $ccy, 'to_currency' => 'NGN', 'rate_date' => $date, 'rate_type' => 'spot'],
                ['rate' => $rate, 'source' => 'manual'],
            );
        }
    }

    /* ------------------------------------------------------------------ the register */

    private function seedBank(): void
    {
        $first = $this->bank['first'];
        /** @var array<string, Contract> $made */
        $made = [];
        $i = 0;
        foreach ($this->specs() as $spec) {
            if (isset($spec['only']) && $spec['only'] !== $this->slug) {
                continue;
            }
            $c = $this->createContract($spec, $first, $i++);
            $made[$spec['k']] = $c;
            $this->team($c, $spec, $i);
            $this->linksFor($c, $spec);
            ! empty($spec['major']) && $this->documentsFor($c, $spec);
            if ($spec['k'] === 'galaxy') {
                $c->forceFill(['owner_id' => null])->saveQuietly(); // deliberate gap: an unowned contract
            }
        }

        // Renewals in progress and history. Historic outcomes run with the notification events faked.
        foreach ($this->specs() as $spec) {
            if (! isset($made[$spec['k']]) || empty($spec['scn'])) {
                continue;
            }
            $this->scenario($made[$spec['k']]->refresh(), $spec['scn'], $spec);
        }

        $this->history();
        $this->normaliseCodes();
        $this->seedP21();
    }

    /**
     * P2.1 demo data, idempotent per bank: per-bank CFO thresholds (M5), the termination workflow stages (H2) and one
     * early-termination request awaiting the Head of IT and the CFO (KPMG advisory, above the CFO threshold).
     */
    private function seedP21(): void
    {
        $settings = app(FinanceSettings::class);
        $row = $settings->for($this->orgId);
        $row->renewal_cfo_from ??= $this->bank['first'] ? '50000000' : '5000000';
        $row->renewal_cfo_uplift_pct ??= $this->bank['first'] ? '10' : '8';
        $row->save();
        $settings->flush();
        FinancePolicySeeder::ensureDefaults($this->orgId);

        if (Termination::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
            return;
        }
        $c = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('title', 'like', 'KPMG IT audit%')->whereIn('status', Contract::RENEWABLE)->orderBy('id')->first();
        if (! $c) {
            return;
        }
        $t = app(TerminationService::class)->request($c, [
            'reason' => 'The IT audit scope moves to the Big Four firm already contracted for the external audit; the advisory retainer duplicates it.',
            'terminated_on' => $this->today->addDays(30)->toDateString(),
        ], $this->who['cio']);
        $t->forceFill(['termination_code' => "{$this->prefix}-TRM-0001"])->saveQuietly();
    }

    private function createContract(array $spec, bool $first, int $index): Contract
    {
        $pick = fn ($v) => is_array($v) && array_is_list($v) && count($v) === 2 && ! is_numeric($v[0]) ? $v[$first ? 1 : 0] : $v;
        $ccy = $spec['ccy'];
        $ends = $this->today->addDays($spec['end']);
        $term = (int) $spec['term'];
        $commenced = $ends->subMonthsNoOverflow($term)->addDay();
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', $this->bank['cc'][$spec['cc']])->firstOrFail();
        $value = $first ? ($spec['fv'] ?? $this->scaled($spec['val'] ?? 0, $ccy)) : ($spec['val'] ?? 0);
        $lq = isset($spec['lq']) ? $spec['lq'][$first ? 1 : 0] : null;
        $dq = isset($spec['dq']) ? $spec['dq'][$first ? 1 : 0] : null;
        [$role, $dpa] = $spec['dp'] ?? ['none', 'not_required'];
        $title = $pick($spec['ti']);
        $vendor = $this->vendor($pick($spec['v']), $spec['vn'] ?? null);
        $draft = ($spec['scn'] ?? null) === 'draft';
        if ($draft) {
            $commenced = $this->today->addDays(30);
            $ends = $commenced->addYear()->subDay();
        }

        $data = [
            'title' => $title, 'type' => $spec['ty'], 'vendor_id' => $vendor->id, 'legal_entity_id' => $cc->legal_entity_id, 'cost_centre_id' => $cc->id,
            'budget_line_id' => $this->budgetLine($pick($spec['line'] ?? null), $cc),
            'owner_id' => $this->who[$spec['o']]->id, 'technical_owner_id' => $this->who[$spec['to']]->id, 'procurement_contact_id' => $this->who['procurement']->id,
            'description' => "{$title} for {$this->bank['prefix']} IT. Covers the production estate and the disaster-recovery site, with support hours as per the schedule.",
            'commenced_on' => $commenced->toDateString(), 'ends_on' => $ends->toDateString(), 'term_months' => $term, 'notice_period_days' => (int) $spec['n'],
            'renewal_type' => $spec['rt'] ?? 'manual', 'auto_renew' => ($spec['rt'] ?? 'manual') === 'auto', 'currency' => $ccy, 'contract_value' => (string) $value,
            'billing_frequency' => $spec['b'], 'payment_terms_days' => 30, 'price_escalation_pct' => isset($spec['esc']) ? (string) $spec['esc'] : null,
            'data_processing_role' => $role, 'dpa_status' => $dpa, 'external_reference' => $spec['ref'] ?? sprintf('%s/PROC/%d/%03d', $this->prefix, $this->today->year - 1, 20 + $index),
            'activate' => ! $draft,
        ];
        if ($lq !== null) {
            $data += ['licence_metric' => $spec['lm'], 'licence_quantity' => (string) $lq, 'deployed_quantity' => (string) $dq];
        }
        if (! empty($spec['major'])) {
            $data += [
                'sla_summary' => 'Priority-1 incidents acknowledged within 30 minutes and restored within 4 hours; monthly availability of 99.9% with service credits below target.',
                'exit_terms' => '90 days written notice; data return and certified deletion within 30 days of exit; reasonable transition assistance at day rates.',
                'key_clauses' => [
                    ['key' => 'termination', 'label' => 'Termination for convenience', 'value' => '90 days written notice after the first year, no penalty.'],
                    ['key' => 'liability', 'label' => 'Liability cap', 'value' => '150% of annual fees; unlimited for data protection breach.'],
                    ['key' => 'audit', 'label' => 'Regulatory audit right', 'value' => 'CBN and the bank may audit the vendor on 10 working days notice.'],
                ],
            ];
        }
        if ($spec['k'] === 'cisco') {
            $data['notification_offsets'] = [120, 60, 30, 14, 7, 0]; // a per-contract override of the bank default
        }

        $c = $this->contracts->create($data, Auth::user()); // the bank admin sees every cost centre (procurement officers see only their own)
        if ($index === 0) {
            $this->recode($c, 'contract_code', 'CON-');
        }

        return $c->refresh();
    }

    private function scaled(int|float $v, string $ccy): string
    {
        $scaled = $v * (self::SCALE[$ccy] ?? 1);
        $step = $ccy === 'NGN' ? 100000 : 1000;

        return (string) (int) (round($scaled / $step) * $step);
    }

    private function vendor(string $like, ?string $name): Vendor
    {
        $v = Vendor::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereRaw('lower(name) like ?', [strtolower($like)])->orderBy('id')->first();
        if ($v) {
            return $v;
        }

        return Vendor::withoutGlobalScopes()->create([
            'organization_id' => $this->orgId, 'vendor_code' => $this->prefix.'-VEN-'.(++$this->vendorSeq), 'name' => $name ?? trim($like, '%'),
            'category' => 'Technology', 'risk_level' => 'medium', 'status' => 'active', 'country' => 'NG', 'contract_currency' => 'NGN',
        ]);
    }

    private function budgetLine(string|array|null $needle, CostCentre $cc): ?int
    {
        if (! $needle) {
            return null;
        }

        return BudgetLine::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereRaw('lower(description) like ?', ['%'.strtolower($needle).'%'])
            ->orderByRaw('case when cost_centre_id = ? then 0 else 1 end', [$cc->id])->orderBy('id')->value('id');
    }

    /** Team: the technical owner leads, then one to three colleagues from the IT pool (deterministic rotation). */
    private function team(Contract $c, array $spec, int $position): void
    {
        $lead = $this->who[$spec['to']];
        $this->contracts->addTeamMember($c, $lead->id, 'project_lead', $this->who['procurement']);
        $exclude = [$lead->id, $this->who[$spec['o']]->id];
        $extra = 1 + ($position % 3);
        for ($k = 0, $added = 0; $k < count($this->pool) && $added < $extra; $k++) {
            $user = $this->who[$this->pool[($position + $k) % count($this->pool)]];
            if (in_array($user->id, $exclude, true)) {
                continue;
            }
            $exclude[] = $user->id;
            $this->contracts->addTeamMember($c, $user->id, $added === 0 && $position % 2 === 0 ? 'approver' : 'member', $this->who['procurement']);
            $added++;
        }
    }

    /** Kano links assets and risks; First Bank links EA applications, business services and assets. */
    private function linksFor(Contract $c, array $spec): void
    {
        $kinds = $this->bank['first'] ? ['ea' => 'ea_application', 'bs' => 'business_service', 'asset' => 'asset'] : ['asset' => 'asset', 'risk' => 'risk'];
        $linked = 0;
        foreach ($kinds as $needleKey => $type) {
            if (empty($spec[$needleKey])) {
                continue;
            }
            $id = $this->find($type, $spec[$needleKey]);
            if ($id) {
                try {
                    $this->contracts->addLink($c, $type, $id, null, $this->who['procurement']);
                    $linked++;
                } catch (ValidationException) {
                }
            }
        }
        if ($linked === 0) { // fall back to the first asset so every contract is anchored to the estate
            $id = Asset::withoutGlobalScopes()->where('organization_id', $this->orgId)->orderBy('id')->skip(abs(crc32($spec['k'])) % 20)->value('id');
            $id && $this->contracts->addLink($c, 'asset', $id, 'General IT estate', $this->who['procurement']);
        }
    }

    /** First Bank's contracts link business services, which exist only once AssetManagementDemoSeeder has run. */
    private function topUpServiceLinks(): void
    {
        if (! $this->bank['first']) {
            return;
        }
        foreach ($this->specs() as $spec) {
            if (empty($spec['bs']) || (isset($spec['only']) && $spec['only'] !== $this->slug)) {
                continue;
            }
            $title = is_array($spec['ti']) ? $spec['ti'][1] : $spec['ti'];
            $c = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('title', $title)->whereNull('renewed_from_id')->orderBy('id')->first();
            $id = $c ? $this->find('business_service', $spec['bs']) : null;
            if ($c && $id) {
                try {
                    $this->contracts->addLink($c, 'business_service', $id, null, $this->who['procurement']);
                } catch (ValidationException) { // already linked
                }
            }
        }
    }

    private function find(string $type, string $needle): ?int
    {
        $like = '%'.strtolower($needle).'%';
        $q = match ($type) {
            'asset' => Asset::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereRaw('lower(name) like ?', [$like]),
            'risk' => Risk::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereRaw('lower(title) like ?', [$like]),
            'ea_application' => EaApplication::withoutGlobalScopes()->where(fn ($w) => $w->where('organization_id', $this->orgId)->orWhereNull('organization_id'))->whereRaw('lower(name) like ?', [$like]),
            'business_service' => BusinessService::withoutGlobalScopes()->where(fn ($w) => $w->where('organization_id', $this->orgId)->orWhereNull('organization_id'))->whereRaw('lower(name) like ?', [$like]),
        };

        return $q->orderBy('id')->value('id');
    }

    private function documentsFor(Contract $c, array $spec): void
    {
        $docs = [['contract', 'Signed '.$c->title.' agreement'], ['sla', 'Service level schedule']];
        foreach (array_slice($docs, 0, 1 + (crc32($spec['k']) % 2)) as [$kind, $title]) {
            $pdf = new PdfWriter;
            $pdf->newPage();
            $pdf->heading($title, 16);
            $pdf->kv('Contract', $c->contract_code.' '.$c->title);
            $pdf->kv('Vendor', (string) $c->vendor?->name);
            $pdf->kv('Term', $c->commenced_on->toDateString().' to '.$c->ends_on->toDateString());
            $pdf->paragraph('Demo document generated for the IT Financial Management contract register. It stands in for the executed agreement held by Procurement.');
            $tmp = tempnam(sys_get_temp_dir(), 'fincon');
            file_put_contents($tmp, $pdf->render());
            $file = new UploadedFile($tmp, strtolower(str_replace(' ', '-', $c->contract_code.'-'.$kind)).'.pdf', 'application/pdf', null, true);
            $this->contracts->addDocument($c, [
                'kind' => $kind, 'title' => $title, 'signed_on' => $c->commenced_on->toDateString(), 'expires_on' => $kind === 'contract' ? $c->ends_on->toDateString() : null,
            ], $file, $this->who['procurement']);
            @unlink($tmp);
        }
    }

    /* ------------------------------------------------------------------ renewals */

    private function scenario(Contract $c, string $scn, array $spec): void
    {
        $historic = fn (callable $fn) => Event::fakeFor($fn, [FinanceApprovalRequested::class, FinanceApprovalDecided::class, ContractRenewed::class]);
        $p = $this->who['procurement'];

        match ($scn) {
            'draft_recommendation' => $this->recommended($this->open($c), $c, 'renew_as_is', 12, 1.03, 'Business-critical network support; the vendor held the price for a second year.'),
            'pending_head' => $this->submit($this->recommended($this->open($c), $c, 'renew_as_is', 12, 1.04, 'Switching and settlement for every card and transfer channel; there is no alternative switch with the same scheme coverage.')),
            'pending_cfo' => $this->pendingCfo($c),
            'auto_rolled' => $historic(fn () => $this->renewals->autoRenew($c)),
            'terminated' => $historic(function () use ($c) {
                $r = $this->open($c);
                $this->renewals->recommend($r, ['recommendation' => 'terminate', 'justification' => 'Traffic has moved to the aggregator contracted last year; the legacy gateway is no longer used.', 'alternatives_considered' => 'Keep as a failover route (rejected: duplicate cost).'], $this->who['procurement']);
                $this->submit($r->refresh());
                $this->approveThrough($r);
                $this->renewals->execute($r->refresh(), $this->who['procurement'], ['terminated_on' => $this->today->toDateString()]);
            }),
            'replaced' => $historic(function () use ($c) {
                $r = $this->recommended($this->open($c), $c, 'replace', 24, 2.3, 'Card processing is moving to a scheme-certified processor with real-time settlement; run both until cutover.');
                $this->submit($r);
                $this->approveThrough($r);
                $this->renewals->execute($r->refresh(), $this->who['procurement']);
            }),
            'executed' => $historic(function () use ($c) {
                $r = $this->recommended($this->open($c), $c, 'renew_as_is', 12, 1.03, 'Scanning coverage is mandated by the CBN risk-based supervision framework.');
                $this->submit($r);
                $this->approveThrough($r);
                $this->renewals->execute($r->refresh(), $this->who['procurement']);
            }),
            'approved' => $historic(function () use ($c) {
                $r = $this->recommended($this->open($c), $c, 'renew_as_is', 12, 1.03, 'Email security is a control in the CBN CSAT baseline.');
                $this->submit($r);
                $this->approveThrough($r);
            }),
            'rejected' => $historic(function () use ($c) {
                $r = $this->recommended($this->open($c), $c, 'renew_renegotiated', 12, 1.18, 'The vendor asked for an 18% uplift on the support tier.');
                $this->submit($r);
                $this->renewals->reject($r->refresh(), $this->who['headofit'], 'The uplift is not justified by the support volume. Renegotiate towards the CPI-linked cap in the master agreement and resubmit.');
            }),
            default => null,
        };
    }

    private function open(Contract $c): Renewal
    {
        $r = $this->renewals->open($c, $this->who['procurement']);
        $this->recode($r, 'renewal_code', 'REN-');

        return $r->refresh();
    }

    private function recommended(Renewal $r, Contract $c, string $rec, int $term, float $factor, string $why): Renewal
    {
        $factor = (string) $factor;
        $value = bcadd(bcmul((string) $c->contract_value, $factor, 8), '0', 0);

        return $this->renewals->recommend($r, [
            'recommendation' => $rec, 'proposed_term_months' => $term, 'proposed_value' => $value, 'proposed_currency' => $c->currency,
            'justification' => $why, 'benchmark_notes' => 'Compared with two peer-bank quotations and the vendor price list; inside the market range.',
            'alternatives_considered' => 'Re-tender (rejected: migration risk inside the notice period); month-to-month extension (rejected: loses the multi-year price).',
        ], $this->who['procurement']);
    }

    private function submit(Renewal $r): Renewal
    {
        return $this->renewals->submit($r->refresh(), $this->who['procurement']);
    }

    /** Finacle AMC renegotiated at a 12% uplift: Head of IT approved, now waiting for the CFO. */
    private function pendingCfo(Contract $c): void
    {
        $r = $this->recommended($this->open($c), $c, 'renew_renegotiated', 12, 1.12, 'Infosys asked for a 12% uplift for the Finacle 11 support tier; we are asking the CFO to approve the exception while we negotiate a multi-year cap.');
        $this->submit($r);
        $this->renewals->approve($r->refresh(), $this->who['headofit'], 'Technically required; the uplift needs CFO approval.');
    }

    /** Walks a submitted renewal through the configured stages (Head of IT, then the CFO when required) until it is approved. */
    private function approveThrough(Renewal $r): void
    {
        for ($i = 0; $i < 3 && $r->refresh()->status === 'submitted'; $i++) {
            foreach (['headofit', 'cfo'] as $key) {
                try {
                    $this->renewals->approve($r->refresh(), $this->who[$key], 'Approved.');

                    continue 2;
                } catch (ValidationException) {
                }
            }
            break;
        }
    }

    /* ------------------------------------------------------------------ T-minus history */

    /**
     * Offsets already crossed in the current term are logged as sent (without sending), so the Timeline tab shows
     * history and the next sweep does not repeat them. Passed deadlines are logged once as well.
     */
    private function history(): void
    {
        $today = $this->today->toDateString();
        $contracts = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereNull('deleted_at')
            ->whereIn('status', array_merge(Contract::LIVE, ['expired']))->whereNull('decision')->orderBy('id')->get();
        $now = now();

        foreach ($contracts as $c) {
            $recipients = $this->contracts->recipients($c);
            if ($recipients->isEmpty()) {
                continue;
            }
            $smallest = null;
            foreach (['end' => $c->ends_on, 'notice' => $c->notice_deadline_on] as $series => $date) {
                if ($date->toDateString() < $today) {
                    $key = 'deadline_passed.'.($series === 'end' ? 'end' : 'notice').'.'.$date->format('Y-m-d');
                    $this->log($c, $key, 0, $recipients->pluck('id')->all(), CarbonImmutable::parse($date->toDateString())->addDay()->setTime(6, 30), $now);

                    continue;
                }
                foreach ($c->effective_offsets as $offset) {
                    $day = CarbonImmutable::parse($date->toDateString())->subDays($offset);
                    if ($day->toDateString() >= $today || $day->toDateString() < $c->commenced_on->toDateString()) {
                        continue;
                    }
                    $this->log($c, "renewal_due.{$series}.".$date->format('Y-m-d'), (int) $offset, $recipients->pluck('id')->all(), $day->setTime(6, 30), $now);
                    $this->contracts->event($c, 'notified', ['series' => $series, 'offset' => (int) $offset, 'recipients' => $recipients->pluck('id')->all()], null, $day->setTime(6, 30));
                    $smallest = $smallest === null ? $offset : min($smallest, $offset);
                }
            }
            $smallest !== null && $c->forceFill(['last_notified_offset' => $smallest])->saveQuietly();
        }
    }

    private function log(Model $subject, string $key, int $offset, array $userIds, CarbonImmutable $at, $now): void
    {
        foreach ($userIds as $uid) {
            DB::table('fin_notification_logs')->insertOrIgnore([
                'organization_id' => $this->orgId, 'notifiable_type' => $subject::class, 'notifiable_id' => $subject->getKey(), 'notification_key' => $key,
                'offset_days' => $offset, 'sent_to_user_id' => $uid, 'sent_at' => $at->utc(), 'channel' => 'database', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /* ------------------------------------------------------------------ codes */

    /** Re-prefix the first code of a bank's series ("CON-0001" => "KHB-CON-0001"); the trait continues the prefix after that. */
    private function recode(Model $m, string $column, string $default): void
    {
        $code = (string) $m->{$column};
        if (str_starts_with($code, $default)) {
            $m->forceFill([$column => "{$this->prefix}-{$code}"])->saveQuietly();
        }
    }

    /** Anything created before the first code was re-prefixed (an auto-rolled term, say) joins the bank's series. */
    private function normaliseCodes(): void
    {
        foreach ([[Contract::class, 'contract_code', 'CON-'], [Renewal::class, 'renewal_code', 'REN-']] as [$class, $column, $default]) {
            $class::withoutGlobalScopes()->where('organization_id', $this->orgId)->where($column, 'like', $default.'%')->get()
                ->each(fn ($m) => $this->recode($m, $column, $default));
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class IntegrationsController extends Controller
{
    public static function catalog(): array
    {
        return [
            /* Identity */
            'ad' => [
                'name' => 'Active Directory',
                'category' => 'Identity',
                'vendor' => 'Microsoft',
                'description' => 'On-prem AD — pulls users, groups, computers, OUs for asset/IAM context.',
                'capabilities' => ['User sync', 'Group sync', 'Computer inventory', 'OU tree'],
                'credentials_required' => ['Service account DN', 'Password', 'LDAP endpoint'],
                'schema' => ['sAMAccountName', 'userPrincipalName', 'memberOf', 'lastLogonTimestamp', 'pwdLastSet'],
            ],
            'entra-id' => [
                'name' => 'Microsoft Entra ID', 'category' => 'Identity', 'vendor' => 'Microsoft',
                'description' => 'Cloud identity — users, groups, conditional access, sign-in telemetry.',
                'capabilities' => ['User sync', 'Group sync', 'Conditional Access policy export', 'Sign-in logs'],
                'credentials_required' => ['App registration (Client ID + Secret)', 'Tenant ID'],
                'schema' => ['id', 'userPrincipalName', 'accountEnabled', 'signInActivity', 'riskLevel'],
            ],
            'okta' => [
                'name' => 'Okta', 'category' => 'Identity', 'vendor' => 'Okta',
                'description' => 'Identity provider — SSO + SCIM + workflow automations.',
                'capabilities' => ['SCIM provisioning', 'SSO (SAML/OIDC)', 'MFA enforcement', 'Sign-in logs'],
                'credentials_required' => ['API token', 'Org URL'],
                'schema' => ['id', 'profile.email', 'status', 'lastLogin', 'mfaFactors'],
            ],
            'ping' => [
                'name' => 'Ping Identity', 'category' => 'Identity', 'vendor' => 'Ping',
                'description' => 'Enterprise identity provider supporting SAML and OIDC.',
                'capabilities' => ['SAML SSO', 'OIDC SSO', 'User provisioning'],
                'credentials_required' => ['Environment ID', 'Client ID', 'Client secret'],
                'schema' => ['id', 'username', 'email', 'population.id'],
            ],

            /* Scanners */
            'tenable' => [
                'name' => 'Tenable Vulnerability Management', 'category' => 'Scanner', 'vendor' => 'Tenable',
                'description' => 'Pull vulnerabilities, assets, plugin findings from Tenable.io.',
                'capabilities' => ['Vulnerabilities', 'Asset inventory', 'Compliance findings', 'Scan schedules'],
                'credentials_required' => ['Access key', 'Secret key'],
                'schema' => ['asset.uuid', 'plugin.id', 'severity', 'cve', 'last_seen'],
            ],
            'qualys' => [
                'name' => 'Qualys VMDR', 'category' => 'Scanner', 'vendor' => 'Qualys',
                'description' => 'Qualys Vulnerability Management, Detection & Response.',
                'capabilities' => ['Vulns', 'Assets', 'Patch orchestration', 'Policy compliance'],
                'credentials_required' => ['Username', 'Password', 'API gateway URL'],
                'schema' => ['QID', 'CVSS', 'CVE', 'HOST_ID', 'SEVERITY'], 'error' => 'Authentication failed (HTTP 401)',
            ],
            'nessus' => [
                'name' => 'Nessus', 'category' => 'Scanner', 'vendor' => 'Tenable',
                'description' => 'Nessus Professional / Manager scanner.',
                'capabilities' => ['Scan results', 'Host inventory'],
                'credentials_required' => ['Access key', 'Secret key'],
                'schema' => ['plugin_id', 'severity', 'cve'],
            ],
            'rapid7' => [
                'name' => 'Rapid7 InsightVM', 'category' => 'Scanner', 'vendor' => 'Rapid7',
                'description' => 'Ingest InsightVM asset and vulnerability data.',
                'capabilities' => ['Vulnerabilities', 'Asset groups', 'Remediation projects'],
                'credentials_required' => ['Console URL', 'API key'],
                'schema' => ['id', 'vulnerabilityId', 'severity', 'solution'],
            ],
            'defender-endpoint' => [
                'name' => 'Microsoft Defender for Endpoint', 'category' => 'Scanner', 'vendor' => 'Microsoft',
                'description' => 'Endpoint vulnerabilities, TVM + EDR alerts.',
                'capabilities' => ['Device inventory', 'Software inventory', 'Vulnerabilities', 'Alerts'],
                'credentials_required' => ['Tenant ID', 'App ID', 'App secret'],
                'schema' => ['deviceName', 'cveId', 'severity', 'exploitabilityLevel'],
            ],

            /* Threat Intel */
            'misp' => [
                'name' => 'MISP', 'category' => 'Threat Intel', 'vendor' => 'MISP Project',
                'description' => 'Threat-intel sharing platform — IOCs + events.',
                'capabilities' => ['IOCs', 'Events', 'Feeds', 'STIX export'],
                'credentials_required' => ['API key', 'MISP URL'],
                'schema' => ['event.id', 'attribute.value', 'attribute.type'],
            ],
            'stix-taxii' => [
                'name' => 'STIX / TAXII', 'category' => 'Threat Intel', 'vendor' => 'Generic',
                'description' => 'Standard threat-intel feed via TAXII 2.1.',
                'capabilities' => ['Collections', 'Objects', 'Indicator filtering'],
                'credentials_required' => ['Discovery URL', 'Username', 'Password'],
                'schema' => ['id', 'type', 'pattern', 'valid_from'],
            ],
            'ngcert' => [
                'name' => 'ngCERT Advisories', 'category' => 'Threat Intel', 'vendor' => 'Nigerian CERT',
                'description' => 'Nigerian Computer Emergency Response Team advisories.',
                'capabilities' => ['Advisory feed', 'IOCs', 'CVEs'],
                'credentials_required' => ['Email subscription / RSS'],
                'schema' => ['advisoryId', 'title', 'cve[]', 'iocs'],
            ],
            'nitda' => [
                'name' => 'NITDA Advisories', 'category' => 'Threat Intel', 'vendor' => 'NITDA',
                'description' => 'National IT Development Agency advisories.',
                'capabilities' => ['Advisory feed'],
                'credentials_required' => ['RSS subscription'],
                'schema' => ['advisoryId', 'title', 'publishedDate'],
            ],

            /* SIEM / SOAR */
            'sentinel' => [
                'name' => 'Microsoft Sentinel', 'category' => 'SIEM', 'vendor' => 'Microsoft',
                'description' => 'Azure-native SIEM — ingest analytics rules and incidents.',
                'capabilities' => ['Incidents', 'Alerts', 'Watchlists', 'Hunting queries'],
                'credentials_required' => ['Workspace ID', 'Shared key'],
                'schema' => ['IncidentId', 'Severity', 'Status', 'CreatedTimeUtc'],
            ],
            'splunk-es' => [
                'name' => 'Splunk Enterprise Security', 'category' => 'SIEM', 'vendor' => 'Splunk',
                'description' => 'Splunk ES notable events and correlation searches.',
                'capabilities' => ['Notable events', 'Searches', 'Adaptive Response'],
                'credentials_required' => ['REST API host', 'Token'],
                'schema' => ['event_id', 'severity', 'src', 'dest'],
            ],
            'qradar' => [
                'name' => 'IBM QRadar', 'category' => 'SIEM', 'vendor' => 'IBM',
                'description' => 'QRadar offense and rule ingestion.',
                'capabilities' => ['Offenses', 'Rules', 'Custom actions'],
                'credentials_required' => ['SEC Token', 'Console IP'],
                'schema' => ['offense_id', 'magnitude', 'severity', 'status'],
            ],
            'wazuh' => [
                'name' => 'Wazuh (on-prem)', 'category' => 'SIEM', 'vendor' => 'Wazuh',
                'description' => 'Open-source SIEM — first-class for air-gapped deployments.',
                'capabilities' => ['Agents', 'Alerts', 'FIM', 'Rootkit checks'],
                'credentials_required' => ['Manager URL', 'Username', 'Password'],
                'schema' => ['agent.id', 'rule.id', 'level', 'decoder.name'],
            ],
            'xsoar' => [
                'name' => 'Palo Alto XSOAR', 'category' => 'SOAR', 'vendor' => 'Palo Alto',
                'description' => 'SOAR playbook orchestration.',
                'capabilities' => ['Playbook run', 'Incident sync'],
                'credentials_required' => ['API key', 'Server URL'],
                'schema' => ['id', 'name', 'status'],
            ],

            /* CMDB */
            'servicenow-cmdb' => [
                'name' => 'ServiceNow CMDB', 'category' => 'CMDB', 'vendor' => 'ServiceNow',
                'description' => 'CI register + relationships from ServiceNow CMDB.',
                'capabilities' => ['CIs', 'Relationships', 'Dependency map'],
                'credentials_required' => ['Instance URL', 'Basic auth user/password'],
                'schema' => ['sys_id', 'name', 'category', 'environment', 'business_service'],
            ],
            'device42' => [
                'name' => 'Device42', 'category' => 'CMDB', 'vendor' => 'Device42',
                'description' => 'Infrastructure DCIM + CMDB with affinity map.',
                'capabilities' => ['Devices', 'Racks', 'Dependencies'],
                'credentials_required' => ['API endpoint', 'Username', 'Password'],
                'schema' => ['device_id', 'name', 'os', 'ip_addresses'],
            ],
            'lansweeper' => [
                'name' => 'Lansweeper', 'category' => 'CMDB', 'vendor' => 'Lansweeper',
                'description' => 'Asset discovery across Windows/Linux/network.',
                'capabilities' => ['Assets', 'Software inventory', 'Patch state'],
                'credentials_required' => ['API token'],
                'schema' => ['assetId', 'assetName', 'ipAddress'],
            ],

            /* Ticketing */
            'jira' => [
                'name' => 'Atlassian Jira', 'category' => 'Ticketing', 'vendor' => 'Atlassian',
                'description' => 'Two-way sync of Issues between Atheris and Jira.',
                'capabilities' => ['Outbound ticket create', 'Webhook receive', 'Status mirror'],
                'credentials_required' => ['Jira URL', 'API token', 'Default project'],
                'schema' => ['key', 'summary', 'status.name', 'priority.name'],
            ],
            'servicenow-itsm' => [
                'name' => 'ServiceNow ITSM', 'category' => 'Ticketing', 'vendor' => 'ServiceNow',
                'description' => 'Incident / Change / Problem ticket sync.',
                'capabilities' => ['Ticket create', 'Ticket update', 'SLA sync'],
                'credentials_required' => ['Instance URL', 'OAuth client'],
                'schema' => ['sys_id', 'number', 'state', 'priority'],
            ],
            'freshservice' => [
                'name' => 'Freshservice', 'category' => 'Ticketing', 'vendor' => 'Freshworks',
                'description' => 'Freshservice ITSM sync.',
                'capabilities' => ['Tickets', 'Changes', 'Assets'],
                'credentials_required' => ['API key', 'Subdomain'],
                'schema' => ['id', 'subject', 'status'],
            ],

            /* TPRM */
            'securityscorecard' => [
                'name' => 'SecurityScorecard', 'category' => 'TPRM', 'vendor' => 'SecurityScorecard',
                'description' => 'Continuous vendor security ratings and breach-news watch.',
                'capabilities' => ['Scorecard', 'Deltas', 'Breaches', 'Factor analysis'],
                'credentials_required' => ['API token'],
                'schema' => ['domain', 'grade', 'score', 'last_30d_change'],
            ],
            'bitsight' => [
                'name' => 'Bitsight', 'category' => 'TPRM', 'vendor' => 'Bitsight',
                'description' => 'Bitsight vendor ratings.',
                'capabilities' => ['Rating', 'Risk vectors', 'Portfolio'],
                'credentials_required' => ['API token'],
                'schema' => ['company', 'rating', 'vector_breakdown'],
            ],
            'upguard' => [
                'name' => 'UpGuard', 'category' => 'TPRM', 'vendor' => 'UpGuard',
                'description' => 'Vendor risk and Breach Sight.',
                'capabilities' => ['Rating', 'Questionnaires', 'Breach alerts'],
                'credentials_required' => ['API token'],
                'schema' => ['vendor_id', 'ratingChange'],
            ],

            /* Cloud posture */
            'aws-config' => [
                'name' => 'AWS Config + Security Hub', 'category' => 'Cloud Posture', 'vendor' => 'AWS',
                'description' => 'Cloud posture findings, config rules and compliance packs.',
                'capabilities' => ['Rules', 'Findings', 'Compliance packs'],
                'credentials_required' => ['IAM role ARN', 'External ID'],
                'schema' => ['configRuleName', 'compliance.complianceType', 'resourceType'],
            ],
            'defender-cloud' => [
                'name' => 'Microsoft Defender for Cloud', 'category' => 'Cloud Posture', 'vendor' => 'Microsoft',
                'description' => 'Azure + multi-cloud CSPM.',
                'capabilities' => ['Recommendations', 'Secure Score', 'Regulatory compliance'],
                'credentials_required' => ['Subscription ID', 'App registration'],
                'schema' => ['id', 'properties.status', 'properties.severity'],
            ],
            'gcp-scc' => [
                'name' => 'GCP Security Command Center', 'category' => 'Cloud Posture', 'vendor' => 'Google',
                'description' => 'Google Cloud Security Command Center.',
                'capabilities' => ['Findings', 'Sources', 'Mute rules'],
                'credentials_required' => ['Service account JSON'],
                'schema' => ['name', 'category', 'state', 'severity'],
            ],

            /* Evidence vault */
            'sharepoint' => [
                'name' => 'SharePoint Online', 'category' => 'Evidence Vault', 'vendor' => 'Microsoft',
                'description' => 'Fetch evidence documents from SharePoint sites.',
                'capabilities' => ['List drives', 'Sync files', 'Metadata index'],
                'credentials_required' => ['App registration', 'Site URL'],
                'schema' => ['driveId', 'itemId', 'name', 'createdDateTime'],
            ],
            'box' => [
                'name' => 'Box', 'category' => 'Evidence Vault', 'vendor' => 'Box',
                'description' => 'Box enterprise file storage for evidence vault.',
                'capabilities' => ['Folders', 'Files', 'Retention labels'],
                'credentials_required' => ['JWT app config'],
                'schema' => ['id', 'name', 'parent.id'],
            ],
            's3-object-lock' => [
                'name' => 'AWS S3 Object Lock (WORM)', 'category' => 'Evidence Vault', 'vendor' => 'AWS',
                'description' => 'S3 WORM storage for CBN/NDPA-grade evidence retention.',
                'capabilities' => ['Object Lock', 'Legal Hold', 'Retention'],
                'credentials_required' => ['IAM role', 'Bucket name'],
                'schema' => ['Bucket', 'Key', 'ObjectLockRetainUntilDate'],
            ],
            'minio' => [
                'name' => 'MinIO (air-gapped)', 'category' => 'Evidence Vault', 'vendor' => 'MinIO',
                'description' => 'S3-compatible object storage for on-prem / air-gapped deployments.',
                'capabilities' => ['Object Lock', 'Replication', 'Retention'],
                'credentials_required' => ['Endpoint', 'Access key', 'Secret key'],
                'schema' => ['Bucket', 'Key', 'Retention'],
            ],

            /* Messaging */
            'infobip' => [
                'name' => 'Infobip', 'category' => 'Messaging', 'vendor' => 'Infobip',
                'description' => 'SMS / WhatsApp / email for Nigerian staff attestation + customer notifications.',
                'capabilities' => ['SMS send', 'WhatsApp', 'Delivery receipts'],
                'credentials_required' => ['API key', 'Base URL'],
                'schema' => ['messageId', 'to', 'status.id'],
            ],
            'africas-talking' => [
                'name' => 'Africa\'s Talking', 'category' => 'Messaging', 'vendor' => 'Africa\'s Talking',
                'description' => 'Pan-African SMS + USSD gateway.',
                'capabilities' => ['SMS', 'USSD app-server', 'Voice'],
                'credentials_required' => ['Username', 'API key'],
                'schema' => ['messageId', 'to', 'status'],
            ],

            /* Core banking */
            'finacle' => [
                'name' => 'Finacle (Infosys)', 'category' => 'Core Banking', 'vendor' => 'Infosys',
                'description' => 'Finacle Connect REST — read-only technical metadata. Customer data strictly embargoed.',
                'capabilities' => ['Product catalogue', 'Branch list', 'Technical health'],
                'credentials_required' => ['Base URL', 'Client ID + secret'],
                'schema' => ['productId', 'productCode', 'status'],
            ],
            'flexcube' => [
                'name' => 'Oracle Flexcube', 'category' => 'Core Banking', 'vendor' => 'Oracle',
                'description' => 'Flexcube read-only adapter.',
                'capabilities' => ['Branch structure', 'Batch schedules'],
                'credentials_required' => ['Base URL', 'Username', 'Password'],
                'schema' => ['branchCode', 'branchName'],
            ],
            't24' => [
                'name' => 'Temenos T24', 'category' => 'Core Banking', 'vendor' => 'Temenos',
                'description' => 'Temenos Transact / TAFJ read adapter.',
                'capabilities' => ['Companies', 'Products', 'Batch routines'],
                'credentials_required' => ['TAFJ endpoint', 'Service user'],
                'schema' => ['companyId', 'productId'],
            ],
            'bankone' => [
                'name' => 'BankOne (Appzone)', 'category' => 'Core Banking', 'vendor' => 'Appzone',
                'description' => 'Nigerian MFB core — BankOne technical metadata.',
                'capabilities' => ['Product catalogue', 'Branch list'],
                'credentials_required' => ['API endpoint', 'API key'],
                'schema' => ['productCode', 'branchCode'],
            ],

            /* Switches */
            'interswitch' => [
                'name' => 'Interswitch', 'category' => 'Switch / Network', 'vendor' => 'Interswitch',
                'description' => 'Switch status and daily counters. No customer PII.',
                'capabilities' => ['Switch status', 'Daily counters'],
                'credentials_required' => ['Merchant ID', 'API key'],
                'schema' => ['timestamp', 'transactions_count', 'status'],
            ],
            'nibss-nip' => [
                'name' => 'NIBSS NIP', 'category' => 'Switch / Network', 'vendor' => 'NIBSS',
                'description' => 'NIBSS NIP status endpoint for channel dashboards.',
                'capabilities' => ['Status', 'Heartbeat'],
                'credentials_required' => ['Participant ID', 'Cert'],
                'schema' => ['timestamp', 'status'],
            ],
        ];
    }

    /** Catalog merged with this bank's own connection state (default: not configured). */
    public static function catalogFor(): array
    {
        $rows = IntegrationConnection::all()->keyBy('connector_key');
        $out = [];
        foreach (self::catalog() as $key => $c) {
            $row = $rows->get($key);
            $out[$key] = $c + [
                'status' => $row?->status ?? 'not_configured',
                'last_tested_at' => $row?->last_tested_at?->toIso8601String(),
                'last_sync' => $row?->last_tested_at?->diffForHumans(),
                'last_test_result' => $row?->last_test_result,
                'last_test_message' => $row?->last_test_message,
            ];
        }

        return $out;
    }

    public function index()
    {
        $catalog = self::catalogFor();
        $byCategory = collect($catalog)->map(fn ($c, $k) => ['key' => $k] + $c)->groupBy('category');
        $summary = [
            'total' => count($catalog),
            'connected' => collect($catalog)->where('status', 'connected')->count(),
            'configured' => collect($catalog)->where('status', 'configured')->count(),
            'failed' => collect($catalog)->where('status', 'failed')->count(),
            'not_configured' => collect($catalog)->where('status', 'not_configured')->count(),
        ];

        return Inertia::render('Integrations/Index', compact('byCategory', 'summary'));
    }

    public function show(string $key)
    {
        $catalog = self::catalogFor();
        abort_unless(isset($catalog[$key]), 404);
        $row = IntegrationConnection::where('connector_key', $key)->first();
        $org = auth()->user()->organization;
        $connector = array_merge(['key' => $key], $catalog[$key]);

        return Inertia::render('Integrations/Show', [
            'connector' => $connector,
            'config' => $row?->maskedConfig() ?? [],
            'webhookBase' => 'https://api.atheris.ng/api/v1/webhooks/'.$key.'/'.($org?->slug ?: 'org-'.$org?->id),
            'canEdit' => auth()->user()->can('edit platform'),
        ]);
    }

    public function save(Request $request, string $key)
    {
        abort_unless(isset(self::catalog()[$key]), 404);
        $data = $request->validate([
            'base_url' => ['nullable', 'url', 'max:255'],
            'access_key' => ['nullable', 'string', 'max:500'],
            'secret_key' => ['nullable', 'string', 'max:500'],
            'scope' => ['required', Rule::in(['read-only', 'read-write', 'admin'])],
            'sync_frequency' => ['required', Rule::in(['real-time', '5min', '15min', 'hourly', 'daily'])],
        ]);
        $row = IntegrationConnection::firstOrNew(['connector_key' => $key, 'organization_id' => $request->user()->organization_id]);
        $config = $row->config ?? [];
        foreach ($data as $k => $v) {
            // A blank secret keeps the stored one; secrets are never echoed back.
            if (in_array($k, IntegrationConnection::SECRET_FIELDS, true) && blank($v)) {
                continue;
            }
            $config[$k] = $v;
        }
        $row->config = $config;
        $row->status = 'configured';
        $row->updated_by = $request->user()->id;
        $row->save();

        return back()->with('success', 'Configuration saved. Run a connection test to verify it.');
    }

    /** Deterministic sandbox probe: validates the saved configuration; no live call is made. */
    public function testConnection(Request $request, string $key)
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$key]), 404);
        $name = $catalog[$key]['name'];
        $row = IntegrationConnection::firstOrNew(['connector_key' => $key, 'organization_id' => $request->user()->organization_id]);
        $config = $row->config ?? [];

        $missing = [];
        if (blank($config['base_url'] ?? null)) {
            $missing[] = 'API base URL';
        }
        if (blank($config['access_key'] ?? null)) {
            $missing[] = 'API key / token';
        }
        $ok = $missing === [];
        $message = $ok
            ? "[Sandbox] Configuration for {$name} is complete; sandbox probe passed. No live call was made to the vendor."
            : "[Sandbox] {$name} test failed: missing ".implode(', ', $missing).'.';

        $row->fill([
            'status' => $ok ? 'connected' : 'failed',
            'last_tested_at' => now(), 'last_test_result' => $ok ? 'pass' : 'fail',
            'last_test_message' => $message, 'updated_by' => $request->user()->id,
        ])->save();

        return back()->with($ok ? 'success' : 'error', $message);
    }

    public function downloadSample(string $key)
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$key]), 404);
        $c = $catalog[$key];
        $headers = $c['schema'];
        $csv = implode(',', $headers)."\n";
        for ($i = 0; $i < 5; $i++) {
            $csv .= implode(',', array_map(fn ($h) => 'sample-'.substr(md5($h.$i), 0, 6), $headers))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$key.'-sample.csv"',
        ]);
    }
}

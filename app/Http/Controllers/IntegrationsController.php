<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
                'status' => 'connected', 'last_sync' => '2 hours ago',
            ],
            'entra-id' => [
                'name' => 'Microsoft Entra ID', 'category' => 'Identity', 'vendor' => 'Microsoft',
                'description' => 'Cloud identity — users, groups, conditional access, sign-in telemetry.',
                'capabilities' => ['User sync', 'Group sync', 'Conditional Access policy export', 'Sign-in logs'],
                'credentials_required' => ['App registration (Client ID + Secret)', 'Tenant ID'],
                'schema' => ['id', 'userPrincipalName', 'accountEnabled', 'signInActivity', 'riskLevel'],
                'status' => 'connected', 'last_sync' => '18 minutes ago',
            ],
            'okta' => [
                'name' => 'Okta', 'category' => 'Identity', 'vendor' => 'Okta',
                'description' => 'Identity provider — SSO + SCIM + workflow automations.',
                'capabilities' => ['SCIM provisioning', 'SSO (SAML/OIDC)', 'MFA enforcement', 'Sign-in logs'],
                'credentials_required' => ['API token', 'Org URL'],
                'schema' => ['id', 'profile.email', 'status', 'lastLogin', 'mfaFactors'],
                'status' => 'available',
            ],
            'ping' => [
                'name' => 'Ping Identity', 'category' => 'Identity', 'vendor' => 'Ping',
                'description' => 'Enterprise identity provider supporting SAML and OIDC.',
                'capabilities' => ['SAML SSO', 'OIDC SSO', 'User provisioning'],
                'credentials_required' => ['Environment ID', 'Client ID', 'Client secret'],
                'schema' => ['id', 'username', 'email', 'population.id'],
                'status' => 'not_connected',
            ],

            /* Scanners */
            'tenable' => [
                'name' => 'Tenable Vulnerability Management', 'category' => 'Scanner', 'vendor' => 'Tenable',
                'description' => 'Pull vulnerabilities, assets, plugin findings from Tenable.io.',
                'capabilities' => ['Vulnerabilities', 'Asset inventory', 'Compliance findings', 'Scan schedules'],
                'credentials_required' => ['Access key', 'Secret key'],
                'schema' => ['asset.uuid', 'plugin.id', 'severity', 'cve', 'last_seen'],
                'status' => 'connected', 'last_sync' => '4 hours ago',
            ],
            'qualys' => [
                'name' => 'Qualys VMDR', 'category' => 'Scanner', 'vendor' => 'Qualys',
                'description' => 'Qualys Vulnerability Management, Detection & Response.',
                'capabilities' => ['Vulns', 'Assets', 'Patch orchestration', 'Policy compliance'],
                'credentials_required' => ['Username', 'Password', 'API gateway URL'],
                'schema' => ['QID', 'CVSS', 'CVE', 'HOST_ID', 'SEVERITY'],
                'status' => 'error', 'last_sync' => '2 days ago', 'error' => 'Authentication failed (HTTP 401)',
            ],
            'nessus' => [
                'name' => 'Nessus', 'category' => 'Scanner', 'vendor' => 'Tenable',
                'description' => 'Nessus Professional / Manager scanner.',
                'capabilities' => ['Scan results', 'Host inventory'],
                'credentials_required' => ['Access key', 'Secret key'],
                'schema' => ['plugin_id', 'severity', 'cve'],
                'status' => 'available',
            ],
            'rapid7' => [
                'name' => 'Rapid7 InsightVM', 'category' => 'Scanner', 'vendor' => 'Rapid7',
                'description' => 'Ingest InsightVM asset and vulnerability data.',
                'capabilities' => ['Vulnerabilities', 'Asset groups', 'Remediation projects'],
                'credentials_required' => ['Console URL', 'API key'],
                'schema' => ['id', 'vulnerabilityId', 'severity', 'solution'],
                'status' => 'not_connected',
            ],
            'defender-endpoint' => [
                'name' => 'Microsoft Defender for Endpoint', 'category' => 'Scanner', 'vendor' => 'Microsoft',
                'description' => 'Endpoint vulnerabilities, TVM + EDR alerts.',
                'capabilities' => ['Device inventory', 'Software inventory', 'Vulnerabilities', 'Alerts'],
                'credentials_required' => ['Tenant ID', 'App ID', 'App secret'],
                'schema' => ['deviceName', 'cveId', 'severity', 'exploitabilityLevel'],
                'status' => 'connected', 'last_sync' => '25 minutes ago',
            ],

            /* Threat Intel */
            'misp' => [
                'name' => 'MISP', 'category' => 'Threat Intel', 'vendor' => 'MISP Project',
                'description' => 'Threat-intel sharing platform — IOCs + events.',
                'capabilities' => ['IOCs', 'Events', 'Feeds', 'STIX export'],
                'credentials_required' => ['API key', 'MISP URL'],
                'schema' => ['event.id', 'attribute.value', 'attribute.type'],
                'status' => 'connected', 'last_sync' => '1 hour ago',
            ],
            'stix-taxii' => [
                'name' => 'STIX / TAXII', 'category' => 'Threat Intel', 'vendor' => 'Generic',
                'description' => 'Standard threat-intel feed via TAXII 2.1.',
                'capabilities' => ['Collections', 'Objects', 'Indicator filtering'],
                'credentials_required' => ['Discovery URL', 'Username', 'Password'],
                'schema' => ['id', 'type', 'pattern', 'valid_from'],
                'status' => 'available',
            ],
            'ngcert' => [
                'name' => 'ngCERT Advisories', 'category' => 'Threat Intel', 'vendor' => 'Nigerian CERT',
                'description' => 'Nigerian Computer Emergency Response Team advisories.',
                'capabilities' => ['Advisory feed', 'IOCs', 'CVEs'],
                'credentials_required' => ['Email subscription / RSS'],
                'schema' => ['advisoryId', 'title', 'cve[]', 'iocs'],
                'status' => 'connected', 'last_sync' => '3 hours ago',
            ],
            'nitda' => [
                'name' => 'NITDA Advisories', 'category' => 'Threat Intel', 'vendor' => 'NITDA',
                'description' => 'National IT Development Agency advisories.',
                'capabilities' => ['Advisory feed'],
                'credentials_required' => ['RSS subscription'],
                'schema' => ['advisoryId', 'title', 'publishedDate'],
                'status' => 'connected', 'last_sync' => '1 day ago',
            ],

            /* SIEM / SOAR */
            'sentinel' => [
                'name' => 'Microsoft Sentinel', 'category' => 'SIEM', 'vendor' => 'Microsoft',
                'description' => 'Azure-native SIEM — ingest analytics rules and incidents.',
                'capabilities' => ['Incidents', 'Alerts', 'Watchlists', 'Hunting queries'],
                'credentials_required' => ['Workspace ID', 'Shared key'],
                'schema' => ['IncidentId', 'Severity', 'Status', 'CreatedTimeUtc'],
                'status' => 'connected', 'last_sync' => '12 minutes ago',
            ],
            'splunk-es' => [
                'name' => 'Splunk Enterprise Security', 'category' => 'SIEM', 'vendor' => 'Splunk',
                'description' => 'Splunk ES notable events and correlation searches.',
                'capabilities' => ['Notable events', 'Searches', 'Adaptive Response'],
                'credentials_required' => ['REST API host', 'Token'],
                'schema' => ['event_id', 'severity', 'src', 'dest'],
                'status' => 'connected', 'last_sync' => '34 minutes ago',
            ],
            'qradar' => [
                'name' => 'IBM QRadar', 'category' => 'SIEM', 'vendor' => 'IBM',
                'description' => 'QRadar offense and rule ingestion.',
                'capabilities' => ['Offenses', 'Rules', 'Custom actions'],
                'credentials_required' => ['SEC Token', 'Console IP'],
                'schema' => ['offense_id', 'magnitude', 'severity', 'status'],
                'status' => 'error', 'error' => 'Token expired',
            ],
            'wazuh' => [
                'name' => 'Wazuh (on-prem)', 'category' => 'SIEM', 'vendor' => 'Wazuh',
                'description' => 'Open-source SIEM — first-class for air-gapped deployments.',
                'capabilities' => ['Agents', 'Alerts', 'FIM', 'Rootkit checks'],
                'credentials_required' => ['Manager URL', 'Username', 'Password'],
                'schema' => ['agent.id', 'rule.id', 'level', 'decoder.name'],
                'status' => 'connected', 'last_sync' => '5 minutes ago',
            ],
            'xsoar' => [
                'name' => 'Palo Alto XSOAR', 'category' => 'SOAR', 'vendor' => 'Palo Alto',
                'description' => 'SOAR playbook orchestration.',
                'capabilities' => ['Playbook run', 'Incident sync'],
                'credentials_required' => ['API key', 'Server URL'],
                'schema' => ['id', 'name', 'status'],
                'status' => 'not_connected',
            ],

            /* CMDB */
            'servicenow-cmdb' => [
                'name' => 'ServiceNow CMDB', 'category' => 'CMDB', 'vendor' => 'ServiceNow',
                'description' => 'CI register + relationships from ServiceNow CMDB.',
                'capabilities' => ['CIs', 'Relationships', 'Dependency map'],
                'credentials_required' => ['Instance URL', 'Basic auth user/password'],
                'schema' => ['sys_id', 'name', 'category', 'environment', 'business_service'],
                'status' => 'connected', 'last_sync' => '6 hours ago',
            ],
            'device42' => [
                'name' => 'Device42', 'category' => 'CMDB', 'vendor' => 'Device42',
                'description' => 'Infrastructure DCIM + CMDB with affinity map.',
                'capabilities' => ['Devices', 'Racks', 'Dependencies'],
                'credentials_required' => ['API endpoint', 'Username', 'Password'],
                'schema' => ['device_id', 'name', 'os', 'ip_addresses'],
                'status' => 'not_connected',
            ],
            'lansweeper' => [
                'name' => 'Lansweeper', 'category' => 'CMDB', 'vendor' => 'Lansweeper',
                'description' => 'Asset discovery across Windows/Linux/network.',
                'capabilities' => ['Assets', 'Software inventory', 'Patch state'],
                'credentials_required' => ['API token'],
                'schema' => ['assetId', 'assetName', 'ipAddress'],
                'status' => 'not_connected',
            ],

            /* Ticketing */
            'jira' => [
                'name' => 'Atlassian Jira', 'category' => 'Ticketing', 'vendor' => 'Atlassian',
                'description' => 'Two-way sync of Issues between Atheris and Jira.',
                'capabilities' => ['Outbound ticket create', 'Webhook receive', 'Status mirror'],
                'credentials_required' => ['Jira URL', 'API token', 'Default project'],
                'schema' => ['key', 'summary', 'status.name', 'priority.name'],
                'status' => 'connected', 'last_sync' => 'Just now',
            ],
            'servicenow-itsm' => [
                'name' => 'ServiceNow ITSM', 'category' => 'Ticketing', 'vendor' => 'ServiceNow',
                'description' => 'Incident / Change / Problem ticket sync.',
                'capabilities' => ['Ticket create', 'Ticket update', 'SLA sync'],
                'credentials_required' => ['Instance URL', 'OAuth client'],
                'schema' => ['sys_id', 'number', 'state', 'priority'],
                'status' => 'connected', 'last_sync' => '2 hours ago',
            ],
            'freshservice' => [
                'name' => 'Freshservice', 'category' => 'Ticketing', 'vendor' => 'Freshworks',
                'description' => 'Freshservice ITSM sync.',
                'capabilities' => ['Tickets', 'Changes', 'Assets'],
                'credentials_required' => ['API key', 'Subdomain'],
                'schema' => ['id', 'subject', 'status'],
                'status' => 'available',
            ],

            /* TPRM */
            'securityscorecard' => [
                'name' => 'SecurityScorecard', 'category' => 'TPRM', 'vendor' => 'SecurityScorecard',
                'description' => 'Continuous vendor security ratings and breach-news watch.',
                'capabilities' => ['Scorecard', 'Deltas', 'Breaches', 'Factor analysis'],
                'credentials_required' => ['API token'],
                'schema' => ['domain', 'grade', 'score', 'last_30d_change'],
                'status' => 'connected', 'last_sync' => '6 hours ago',
            ],
            'bitsight' => [
                'name' => 'Bitsight', 'category' => 'TPRM', 'vendor' => 'Bitsight',
                'description' => 'Bitsight vendor ratings.',
                'capabilities' => ['Rating', 'Risk vectors', 'Portfolio'],
                'credentials_required' => ['API token'],
                'schema' => ['company', 'rating', 'vector_breakdown'],
                'status' => 'connected', 'last_sync' => '3 hours ago',
            ],
            'upguard' => [
                'name' => 'UpGuard', 'category' => 'TPRM', 'vendor' => 'UpGuard',
                'description' => 'Vendor risk and Breach Sight.',
                'capabilities' => ['Rating', 'Questionnaires', 'Breach alerts'],
                'credentials_required' => ['API token'],
                'schema' => ['vendor_id', 'ratingChange'],
                'status' => 'not_connected',
            ],

            /* Cloud posture */
            'aws-config' => [
                'name' => 'AWS Config + Security Hub', 'category' => 'Cloud Posture', 'vendor' => 'AWS',
                'description' => 'Cloud posture findings, config rules and compliance packs.',
                'capabilities' => ['Rules', 'Findings', 'Compliance packs'],
                'credentials_required' => ['IAM role ARN', 'External ID'],
                'schema' => ['configRuleName', 'compliance.complianceType', 'resourceType'],
                'status' => 'connected', 'last_sync' => '45 minutes ago',
            ],
            'defender-cloud' => [
                'name' => 'Microsoft Defender for Cloud', 'category' => 'Cloud Posture', 'vendor' => 'Microsoft',
                'description' => 'Azure + multi-cloud CSPM.',
                'capabilities' => ['Recommendations', 'Secure Score', 'Regulatory compliance'],
                'credentials_required' => ['Subscription ID', 'App registration'],
                'schema' => ['id', 'properties.status', 'properties.severity'],
                'status' => 'connected', 'last_sync' => '1 hour ago',
            ],
            'gcp-scc' => [
                'name' => 'GCP Security Command Center', 'category' => 'Cloud Posture', 'vendor' => 'Google',
                'description' => 'Google Cloud Security Command Center.',
                'capabilities' => ['Findings', 'Sources', 'Mute rules'],
                'credentials_required' => ['Service account JSON'],
                'schema' => ['name', 'category', 'state', 'severity'],
                'status' => 'not_connected',
            ],

            /* Evidence vault */
            'sharepoint' => [
                'name' => 'SharePoint Online', 'category' => 'Evidence Vault', 'vendor' => 'Microsoft',
                'description' => 'Fetch evidence documents from SharePoint sites.',
                'capabilities' => ['List drives', 'Sync files', 'Metadata index'],
                'credentials_required' => ['App registration', 'Site URL'],
                'schema' => ['driveId', 'itemId', 'name', 'createdDateTime'],
                'status' => 'connected', 'last_sync' => '4 hours ago',
            ],
            'box' => [
                'name' => 'Box', 'category' => 'Evidence Vault', 'vendor' => 'Box',
                'description' => 'Box enterprise file storage for evidence vault.',
                'capabilities' => ['Folders', 'Files', 'Retention labels'],
                'credentials_required' => ['JWT app config'],
                'schema' => ['id', 'name', 'parent.id'],
                'status' => 'not_connected',
            ],
            's3-object-lock' => [
                'name' => 'AWS S3 Object Lock (WORM)', 'category' => 'Evidence Vault', 'vendor' => 'AWS',
                'description' => 'S3 WORM storage for CBN/NDPA-grade evidence retention.',
                'capabilities' => ['Object Lock', 'Legal Hold', 'Retention'],
                'credentials_required' => ['IAM role', 'Bucket name'],
                'schema' => ['Bucket', 'Key', 'ObjectLockRetainUntilDate'],
                'status' => 'connected', 'last_sync' => '15 minutes ago',
            ],
            'minio' => [
                'name' => 'MinIO (air-gapped)', 'category' => 'Evidence Vault', 'vendor' => 'MinIO',
                'description' => 'S3-compatible object storage for on-prem / air-gapped deployments.',
                'capabilities' => ['Object Lock', 'Replication', 'Retention'],
                'credentials_required' => ['Endpoint', 'Access key', 'Secret key'],
                'schema' => ['Bucket', 'Key', 'Retention'],
                'status' => 'connected', 'last_sync' => '1 minute ago',
            ],

            /* Messaging */
            'infobip' => [
                'name' => 'Infobip', 'category' => 'Messaging', 'vendor' => 'Infobip',
                'description' => 'SMS / WhatsApp / email for Nigerian staff attestation + customer notifications.',
                'capabilities' => ['SMS send', 'WhatsApp', 'Delivery receipts'],
                'credentials_required' => ['API key', 'Base URL'],
                'schema' => ['messageId', 'to', 'status.id'],
                'status' => 'connected', 'last_sync' => 'Live',
            ],
            'africas-talking' => [
                'name' => 'Africa\'s Talking', 'category' => 'Messaging', 'vendor' => 'Africa\'s Talking',
                'description' => 'Pan-African SMS + USSD gateway.',
                'capabilities' => ['SMS', 'USSD app-server', 'Voice'],
                'credentials_required' => ['Username', 'API key'],
                'schema' => ['messageId', 'to', 'status'],
                'status' => 'connected', 'last_sync' => 'Live',
            ],

            /* Core banking */
            'finacle' => [
                'name' => 'Finacle (Infosys)', 'category' => 'Core Banking', 'vendor' => 'Infosys',
                'description' => 'Finacle Connect REST — read-only technical metadata. Customer data strictly embargoed.',
                'capabilities' => ['Product catalogue', 'Branch list', 'Technical health'],
                'credentials_required' => ['Base URL', 'Client ID + secret'],
                'schema' => ['productId', 'productCode', 'status'],
                'status' => 'connected', 'last_sync' => '2 hours ago',
            ],
            'flexcube' => [
                'name' => 'Oracle Flexcube', 'category' => 'Core Banking', 'vendor' => 'Oracle',
                'description' => 'Flexcube read-only adapter.',
                'capabilities' => ['Branch structure', 'Batch schedules'],
                'credentials_required' => ['Base URL', 'Username', 'Password'],
                'schema' => ['branchCode', 'branchName'],
                'status' => 'connected', 'last_sync' => '4 hours ago',
            ],
            't24' => [
                'name' => 'Temenos T24', 'category' => 'Core Banking', 'vendor' => 'Temenos',
                'description' => 'Temenos Transact / TAFJ read adapter.',
                'capabilities' => ['Companies', 'Products', 'Batch routines'],
                'credentials_required' => ['TAFJ endpoint', 'Service user'],
                'schema' => ['companyId', 'productId'],
                'status' => 'available',
            ],
            'bankone' => [
                'name' => 'BankOne (Appzone)', 'category' => 'Core Banking', 'vendor' => 'Appzone',
                'description' => 'Nigerian MFB core — BankOne technical metadata.',
                'capabilities' => ['Product catalogue', 'Branch list'],
                'credentials_required' => ['API endpoint', 'API key'],
                'schema' => ['productCode', 'branchCode'],
                'status' => 'connected', 'last_sync' => '1 hour ago',
            ],

            /* Switches */
            'interswitch' => [
                'name' => 'Interswitch', 'category' => 'Switch / Network', 'vendor' => 'Interswitch',
                'description' => 'Switch status and daily counters. No customer PII.',
                'capabilities' => ['Switch status', 'Daily counters'],
                'credentials_required' => ['Merchant ID', 'API key'],
                'schema' => ['timestamp', 'transactions_count', 'status'],
                'status' => 'connected', 'last_sync' => '3 minutes ago',
            ],
            'nibss-nip' => [
                'name' => 'NIBSS NIP', 'category' => 'Switch / Network', 'vendor' => 'NIBSS',
                'description' => 'NIBSS NIP status endpoint for channel dashboards.',
                'capabilities' => ['Status', 'Heartbeat'],
                'credentials_required' => ['Participant ID', 'Cert'],
                'schema' => ['timestamp', 'status'],
                'status' => 'connected', 'last_sync' => '1 minute ago',
            ],
        ];
    }

    public function index()
    {
        $catalog = self::catalog();
        $byCategory = collect($catalog)->groupBy('category');
        $summary = [
            'total' => count($catalog),
            'connected' => collect($catalog)->where('status', 'connected')->count(),
            'error' => collect($catalog)->where('status', 'error')->count(),
            'available' => collect($catalog)->where('status', 'available')->count(),
            'not_connected' => collect($catalog)->where('status', 'not_connected')->count(),
        ];
        return Inertia::render('Integrations/Index', compact('byCategory', 'summary'));
    }

    public function show(string $key)
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$key]), 404);
        $connector = array_merge(['key' => $key], $catalog[$key]);
        return Inertia::render('Integrations/Show', ['connector' => $connector]);
    }

    public function testConnection(string $key)
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$key]), 404);
        // Simulated result: 80% pass
        $ok = rand(1, 10) <= 8;
        return back()->with($ok ? 'success' : 'error',
            $ok ? "Test connection to {$catalog[$key]['name']} succeeded."
                : "Test connection to {$catalog[$key]['name']} FAILED. Check credentials and firewall."
        );
    }

    public function downloadSample(string $key)
    {
        $catalog = self::catalog();
        abort_unless(isset($catalog[$key]), 404);
        $c = $catalog[$key];
        $headers = $c['schema'];
        $csv = implode(',', $headers)."\n";
        for ($i = 0; $i < 5; $i++) {
            $csv .= implode(',', array_map(fn($h) => 'sample-'.substr(md5($h.$i), 0, 6), $headers))."\n";
        }
        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$key.'-sample.csv"',
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\Ea\ArbSubmission;
use App\Models\Ea\Capability;
use App\Models\Ea\ControlMapping;
use App\Models\Ea\EaApi;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\Exception as EaException;
use App\Models\Ea\Principle;
use App\Models\Ea\Process;
use App\Models\Ea\Zone;
use App\Models\Ea\ZoneAssignment;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EaPhase2Seeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Seeding EA-Studio Phase 2 demo data…');
        $tenantId = Organization::where('slug', 'first-bank-nigeria')->value('id') ?? 1;

        $this->interfaces($tenantId);
        $this->apis($tenantId);
        $this->zones($tenantId);
        $this->controlMappings($tenantId);
        $this->processes($tenantId);
        $this->principles($tenantId);
        $this->arb($tenantId);
        $this->exceptions($tenantId);
    }

    private function interfaces(int $tenantId): void
    {
        if (EaInterface::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $apps = EaApplication::where('organization_id', $tenantId)->get();
        if ($apps->count() < 5) {
            return;
        }

        $patterns = [['sync', 'REST'], ['async', 'Kafka'], ['batch', 'SFTP'], ['event', 'AMQP'], ['sync', 'SOAP'], ['file', 'SFTP'], ['sync', 'JDBC']];
        $n = 0;
        foreach ($apps as $a) {
            foreach ($apps->random(min(3, $apps->count() - 1)) as $b) {
                if ($a->id === $b->id) {
                    continue;
                }
                [$pattern, $protocol] = $patterns[array_rand($patterns)];
                $pii = in_array($a->name, ['FirstMobile App', 'FirstOnline Internet Banking', 'KYC Onboarding Portal'])
                    || in_array($b->name, ['Customer PII Vault', 'BVN-Linked Account Store']);
                $n++;
                EaInterface::create([
                    'organization_id' => $tenantId,
                    'code' => 'FBN-IFC-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                    'name' => $a->name.' → '.$b->name,
                    'source_app_id' => $a->id,
                    'target_app_id' => $b->id,
                    'protocol' => $protocol,
                    'pattern' => $pattern,
                    'classification' => $pii ? 'Personal' : 'Internal',
                    'pii_carrying' => $pii,
                    'status' => ['active', 'active', 'active', 'active', 'deprecated', 'proposed', 'retired'][rand(0, 6)],
                ]);
                if ($n >= 90) {
                    break 2;
                }
            }
        }
    }

    private function apis(int $tenantId): void
    {
        if (EaApi::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $interfaces = EaInterface::where('organization_id', $tenantId)->take(30)->get();
        foreach ($interfaces as $i => $if) {
            EaApi::create([
                'organization_id' => $tenantId,
                'interface_id' => $if->id,
                'code' => 'FBN-API-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $if->name,
                'base_url' => 'https://api.firstbanknigeria.ng/v1/'.Str::slug($if->name, '-'),
                'version' => 'v1',
                'status' => 'active',
                'provider_role' => $if->sourceApp?->name,
                'consumer_role' => $if->targetApp?->name,
                'auth_method' => ['oauth2', 'oauth2', 'oauth2', 'mtls', 'api_key'][rand(0, 4)],
            ]);
        }
    }

    private function zones(int $tenantId): void
    {
        if (Zone::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $zones = [
            ['Z-INET', 'Internet / Untrusted', 1, 'Public-facing zone — WAF + DDoS only.'],
            ['Z-DMZ', 'DMZ', 2, 'Reverse-proxies, edge services, public APIs.'],
            ['Z-APP', 'Application', 3, 'Standard business applications.'],
            ['Z-DATA', 'Data', 4, 'Databases, data lakes, HSMs.'],
            ['Z-CORE', 'Core Banking', 5, 'Highest-trust. Dedicated network segment.'],
            ['Z-PAY', 'Payment', 5, 'Card + payments isolation — PCI-DSS scoped.'],
            ['Z-MGMT', 'Management', 4, 'OOB network for admin tooling.'],
        ];
        $zoneIds = [];
        foreach ($zones as [$code, $name, $trust, $desc]) {
            $zoneIds[$code] = Zone::create([
                'organization_id' => $tenantId,
                'code' => $code, 'name' => $name, 'trust_level' => $trust, 'description' => $desc,
            ])->id;
        }
        $apps = EaApplication::where('organization_id', $tenantId)->get();
        foreach ($apps as $app) {
            $zone = match (true) {
                str_contains($app->name, 'Online') || str_contains($app->name, 'Mobile') || str_contains($app->name, 'USSD') => 'Z-DMZ',
                str_contains($app->name, 'Core Banking') || str_contains($app->name, 'Flexcube') => 'Z-CORE',
                str_contains($app->name, 'Card') || str_contains($app->name, 'NIBSS') || str_contains($app->name, 'Interswitch') || str_contains($app->name, 'SWIFT') => 'Z-PAY',
                str_contains($app->name, 'Data') || str_contains($app->name, 'Lakehouse') || str_contains($app->name, 'Warehouse') => 'Z-DATA',
                str_contains($app->name, 'Sentinel') || str_contains($app->name, 'CyberArk') || str_contains($app->name, 'ServiceNow') => 'Z-MGMT',
                default => 'Z-APP',
            };
            ZoneAssignment::create([
                'organization_id' => $tenantId,
                'zone_id' => $zoneIds[$zone],
                'application_id' => $app->id,
                'assignment_type' => 'assigned',
            ]);
        }
    }

    /**
     * ATH-EAR-002 WS 2.4 — "No `rand()` into foreign keys anywhere."
     *
     * §2.5 named this exact line: "`ea_control_mappings.control_id` is an
     * unconstrained bigint that `EaPhase2Seeder` populates with `rand(1,80)`.
     * `ControlInheritanceService` then computes control posture over IDs that
     * may not resolve. **Any coverage figure the module currently produces is
     * arithmetic over noise, and must not be shown to a regulator.**"
     *
     * Mappings are now drawn from real `controls.id` values, and the framework
     * is taken from the control itself rather than picked at random, so a
     * mapping claims the framework its control actually belongs to.
     */
    private function controlMappings(int $tenantId): void
    {
        if (ControlMapping::where('organization_id', $tenantId)->count() > 0) {
            return;
        }

        $apps = EaApplication::where('organization_id', $tenantId)->pluck('id')->toArray();

        // Real controls only. Without a populated Compliance module there is
        // nothing honest to map to, so seed nothing rather than invent ids.
        $controls = Control::withoutGlobalScopes()
            ->get(['id', 'control_code', 'domain', 'category'])
            ->all();

        if (empty($controls) || empty($apps)) {
            return;
        }

        $coverage = ['full' => 55, 'partial' => 25, 'planned' => 12, 'gap' => 8];

        foreach ($apps as $i => $appId) {
            // Deterministic spread across the real control set rather than a
            // random draw: re-seeding produces the same demo, and every id
            // resolves by construction.
            foreach (range(0, 1) as $n) {
                $control = $controls[($i * 2 + $n) % count($controls)];

                ControlMapping::create([
                    'organization_id' => $tenantId,
                    'component_type' => 'application',
                    'component_id' => $appId,
                    'control_id' => $control->id,
                    'framework' => $this->frameworkFor($control),
                    'coverage' => $this->weighted($coverage),
                    'notes' => 'Mapped via Phase 2 control-auto-suggestion rules engine.',
                ]);
            }
        }
    }

    /** Derive the framework from the control rather than guessing. */
    private function frameworkFor($control): string
    {
        $haystack = strtolower(($control->domain ?? '').' '.($control->category ?? '').' '.($control->control_code ?? ''));

        return match (true) {
            str_contains($haystack, 'pci') => 'PCI',
            str_contains($haystack, 'ndpa'), str_contains($haystack, 'privacy') => 'NDPA',
            str_contains($haystack, 'cbn'), str_contains($haystack, 'cyber') => 'CBN-RBCSF',
            default => 'ISMS',
        };
    }

    private function weighted(array $d): string
    {
        $r = mt_rand(1, array_sum($d));
        $c = 0;
        foreach ($d as $k => $w) {
            $c += $w;
            if ($r <= $c) {
                return $k;
            }
        }

        return array_key_first($d);
    }

    private function processes(int $tenantId): void
    {
        if (Process::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $caps = Capability::where('organization_id', $tenantId)->pluck('id', 'name')->toArray();
        $l1 = [
            ['P-CUST', 'Customer Management', 'Customer Management'],
            ['P-PAY', 'Payments Operations', 'Customer Management'],
            ['P-LOAN', 'Credit Lifecycle', 'Customer Management'],
            ['P-TREAS', 'Treasury Operations', 'Customer Management'],
            ['P-COMP', 'Compliance & Regulatory', 'Customer Management'],
            ['P-IT', 'IT Operations', 'Customer Management'],
        ];
        $parentIds = [];
        foreach ($l1 as [$code, $name]) {
            $parentIds[$code] = Process::create([
                'organization_id' => $tenantId,
                'code' => $code, 'name' => $name, 'level' => 1,
                'criticality' => 'high', 'rto_hours' => 4, 'rpo_hours' => 1,
            ])->id;
        }
        // Build L2 and L3 beneath each L1
        $l2 = [
            'P-CUST' => ['Customer Onboarding', 'Customer Servicing', 'Customer Exit'],
            'P-PAY' => ['NIBSS NIP Settlement', 'Card Settlement', 'SWIFT International'],
            'P-LOAN' => ['Credit Appraisal', 'Disbursement', 'Collections'],
            'P-TREAS' => ['FX Trading', 'Money Markets', 'Liquidity Management'],
            'P-COMP' => ['KYC/AML', 'Regulator Returns', 'Audit Response'],
            'P-IT' => ['Change Management', 'Incident Response', 'Access Management'],
        ];
        foreach ($l2 as $parentCode => $children) {
            foreach ($children as $n => $childName) {
                $l2Id = Process::create([
                    'organization_id' => $tenantId,
                    'code' => $parentCode.'-L2-'.($n + 1),
                    'name' => $childName,
                    'parent_id' => $parentIds[$parentCode],
                    'level' => 2,
                    'criticality' => ['critical', 'high', 'medium'][rand(0, 2)],
                    'rto_hours' => [1, 2, 4, 8][rand(0, 3)],
                    'rpo_hours' => [0, 1, 2, 4][rand(0, 3)],
                ])->id;
                // A few L3 processes
                for ($k = 1; $k <= 2; $k++) {
                    Process::create([
                        'organization_id' => $tenantId,
                        'code' => $parentCode.'-L3-'.($n + 1).'.'.$k,
                        'name' => $childName.' — Step '.$k,
                        'parent_id' => $l2Id,
                        'level' => 3,
                        'criticality' => 'medium',
                        'rto_hours' => rand(1, 24),
                        'rpo_hours' => rand(0, 4),
                    ]);
                }
            }
        }
    }

    private function principles(int $tenantId): void
    {
        if (Principle::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $ps = [
            ['P-001', 'Primacy of Principles', 'All information management practices comply with these principles.', 'Predictability, consistency and business-aligned decisions.', 'Enforce through ARB review; escalate non-compliance.'],
            ['P-002', 'Business Continuity', 'Enterprise operations are maintained in spite of system interruptions.', 'Customer trust + regulator expectations demand continuity.', 'Every critical app has an RTO/RPO target and active DR plan.'],
            ['P-003', 'Common Use Applications', 'Prefer tenant-wide applications over bespoke siloes.', 'Lower TCO and better support.', 'Capability map + Application portfolio govern procurement decisions.'],
            ['P-004', 'Data is an Asset', 'Information is a valuable enterprise resource and managed accordingly.', 'Drives regulatory compliance and analytics value.', 'Classification, lineage and stewardship per Data Architecture.'],
            ['P-005', 'Data Accessibility', 'Data is accessible across the enterprise to authorised users.', 'Reduces duplication and supports decision-making.', 'Enforced via IAM + data catalogue.'],
            ['P-006', 'Data Security', 'Data is protected from unauthorised use or disclosure.', 'Regulatory + reputational protection.', 'NDPA + CBN + PCI controls mapped per Security Architecture.'],
            ['P-007', 'Technology Independence', 'Applications independent of specific technology choices.', 'Avoid vendor lock-in; preserve flexibility.', 'Radar management; discourage proprietary extensions.'],
            ['P-008', 'Ease of Use', 'Applications are simple to learn and use.', 'Adoption and productivity.', 'Follow AEGIS design system; measure NPS.'],
            ['P-009', 'Requirements-Based Change', 'Only in response to business need are changes made.', 'Avoid change for its own sake.', 'ADM-phase gating via Workflow Studio.'],
            ['P-010', 'Responsive Change Management', 'Changes are managed in a timely manner.', 'Avoid bureaucracy killing momentum.', 'Standard vs normal vs emergency change paths.'],
            ['P-011', 'Zero-Trust by Default', 'Never trust, always verify.', 'Modern threat landscape.', 'Enforce identity-aware proxies, micro-segmentation, signed requests.'],
            ['P-012', 'Cloud-Smart', 'Use cloud where it adds value and meets Nigerian regulator requirements.', 'Balance agility with data residency obligations.', 'CBN + NDPC-aligned cloud policy; sovereign options for RED data.'],
            ['P-013', 'Regulator-First Observability', 'Design for examiner transparency.', 'Examinations are inevitable.', 'Every control + change must be attestable with evidence trail.'],
            ['P-014', 'Open Standards Preferred', 'Open standards > proprietary formats.', 'Interoperability + exit strategy.', 'Standards catalogue biased to open.'],
            ['P-015', 'Reuse Before Buy Before Build', 'Reuse proven internal capability before buying; buy COTS before custom build.', 'Cost + speed.', 'Pattern library + portfolio analysis mandated before initiative approval.'],
        ];
        foreach ($ps as [$code, $name, $stmt, $ration, $impl]) {
            Principle::create([
                'organization_id' => $tenantId, 'code' => $code, 'name' => $name,
                'statement' => $stmt, 'rationale' => $ration, 'implications' => $impl,
                'status' => 'active',
            ]);
        }
    }

    private function arb(int $tenantId): void
    {
        if (ArbSubmission::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $statuses = ['submitted' => 20, 'in_review' => 30, 'approved' => 30, 'rejected' => 10, 'deferred' => 10];
        $titles = [
            'Migrate agent banking stack to Kubernetes',
            'Retire IBM AIX legacy CRMS',
            'Adopt HashiCorp Vault for secrets',
            'Replace F5 with cloud-native load balancing',
            'Enable Azure AD Conditional Access (risk-based MFA)',
            'New FirstCollect payment flow',
            'Onboard Mastercard Click-to-Pay',
            'Deprecate TLS 1.0 across SWIFT gateway',
            'Establish private cloud for Treasury',
            'Adopt Kafka as primary event backbone',
            'Enforce SAST across all .NET repos',
            'Retire legacy SunGard back-office',
            'Implement OAuth2 client credentials for all internal APIs',
            'Stand up Regulatory Intelligence ingest pipeline',
            'Move customer PII vault to dedicated HSM',
            'Adopt GraphQL for mobile BFF',
            'New Islamic banking product onboarding',
            'DPIA-driven redesign of DSAR workflow',
            'SWIFT CSP annual attestation refresh',
            'Zero-trust micro-segmentation programme',
        ];
        foreach ($titles as $i => $title) {
            $status = $this->weighted($statuses);
            ArbSubmission::create([
                'organization_id' => $tenantId,
                'code' => 'FBN-ARB-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'title' => $title,
                'summary' => $title.' — architectural change request with impact analysis attached.',
                'status' => $status,
                'impact_blast_radius' => ['affected_apps' => rand(2, 10), 'affected_zones' => rand(1, 3)],
                'impacted_principles' => ['P-002', 'P-006', 'P-011'],
                'impacted_standards' => ['STD-001', 'STD-007'],
                'decided_at' => in_array($status, ['approved', 'rejected', 'deferred']) ? now()->subDays(rand(1, 60)) : null,
                'decided_by' => in_array($status, ['approved', 'rejected', 'deferred']) ? 'Group CISO' : null,
                'digital_signature' => $status === 'approved' ? hash('sha256', $title) : null,
            ]);
        }
    }

    private function exceptions(int $tenantId): void
    {
        if (EaException::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $list = [
            ['EX-001', 'TLS 1.0 on legacy SunGard back-office', 'Re-platforming in Phase 3; cannot upgrade until retirement.', 'Network segmentation + WAF; no PII traversal.', -90, 60],
            ['EX-002', 'Shared privileged account on legacy AIX', 'Vendor support requires shared credentials.', 'PAM session recording + daily review.', -60, 90],
            ['EX-003', 'Java 8 runtime on Treasury Front Office', 'Murex version pinned.', 'Network isolation + monthly CVE review.', -30, 120],
            ['EX-004', 'MFA exemption for break-glass account', 'Required for disaster recovery.', 'Sealed credentials + quarterly rotation.', -180, 180],
            ['EX-005', 'Non-standard encryption on legacy tape backup', 'Tape hardware limit.', 'Off-site storage under access log.', -45, 30],
            ['EX-006', 'Local admin rights on dev workstations', 'Required for developer productivity.', 'EDR + logging; separate network.', -120, 365],
            ['EX-007', 'Legacy API key auth on internal batch', 'No OAuth support.', 'IP allow-list + mTLS.', -200, -5],
            ['EX-008', 'SaaS SSO disabled for vendor portal', 'Vendor platform limitation.', 'Per-user unique password + IP allow-list.', -15, 200],
        ];
        foreach ($list as $i => [$code, $subject, $justification, $cc, $fromDaysAgo, $expiresInDays]) {
            $status = 'active';
            if ($expiresInDays <= 0) {
                $status = 'expired';
            }
            EaException::create([
                'organization_id' => $tenantId, 'code' => $code, 'subject' => $subject,
                'justification' => $justification, 'compensating_controls' => $cc,
                'effective_from' => now()->addDays($fromDaysAgo)->toDateString(),
                'expires_at' => now()->addDays($expiresInDays)->toDateString(),
                'status' => $status,
            ]);
        }
    }
}

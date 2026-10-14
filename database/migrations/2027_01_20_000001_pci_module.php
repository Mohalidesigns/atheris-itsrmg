<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PCI Management validation (ATH-VAL-011).
 *
 * The PCI module had no framework behind it: requirement statuses, the self-assessment answers and
 * the control matrix were generated (some with rand()), and controls were mapped to requirements at
 * random on every page load. This migration adds:
 * - PCI DSS v4.0.1 as a platform framework: the 12 principal requirements and their defined
 *   sub-requirements, so PCI assessments run on the compliance-assessment engine like any other
 *   framework and controls map to requirements through control_framework_mappings;
 * - the bank's CDE scoping decision per asset (cde / connected / out_of_scope) with its rationale.
 */
return new class extends Migration
{
    public const SLUG = 'pci-dss-4.0.1';

    /** [requirement, title, goal, [sub-requirement => title]] */
    public const REQUIREMENTS = [
        ['1', 'Install and maintain network security controls', 'Build and Maintain a Secure Network and Systems', [
            '1.1' => 'Processes and mechanisms for installing and maintaining network security controls are defined and understood',
            '1.2' => 'Network security controls (NSCs) are configured and maintained',
            '1.3' => 'Network access to and from the cardholder data environment is restricted',
            '1.4' => 'Network connections between trusted and untrusted networks are controlled',
            '1.5' => 'Risks to the CDE from computing devices that connect to untrusted networks and the CDE are mitigated',
        ]],
        ['2', 'Apply secure configurations to all system components', 'Build and Maintain a Secure Network and Systems', [
            '2.1' => 'Processes and mechanisms for applying secure configurations to all system components are defined and understood',
            '2.2' => 'System components are configured and managed securely',
            '2.3' => 'Wireless environments are configured and managed securely',
        ]],
        ['3', 'Protect stored account data', 'Protect Account Data', [
            '3.1' => 'Processes and mechanisms for protecting stored account data are defined and understood',
            '3.2' => 'Storage of account data is kept to a minimum',
            '3.3' => 'Sensitive authentication data (SAD) is not stored after authorization',
            '3.4' => 'Access to displays of full PAN and ability to copy PAN is restricted',
            '3.5' => 'Primary account number (PAN) is secured wherever it is stored',
            '3.6' => 'Cryptographic keys used to protect stored account data are secured',
            '3.7' => 'Where cryptography is used to protect stored account data, key management processes and procedures covering all aspects of the key lifecycle are defined and implemented',
        ]],
        ['4', 'Protect cardholder data with strong cryptography during transmission over open, public networks', 'Protect Account Data', [
            '4.1' => 'Processes and mechanisms for protecting cardholder data with strong cryptography during transmission are defined and documented',
            '4.2' => 'PAN is protected with strong cryptography during transmission',
        ]],
        ['5', 'Protect all systems and networks from malicious software', 'Maintain a Vulnerability Management Program', [
            '5.1' => 'Processes and mechanisms for protecting all systems and networks from malicious software are defined and understood',
            '5.2' => 'Malicious software (malware) is prevented, or detected and addressed',
            '5.3' => 'Anti-malware mechanisms and processes are active, maintained, and monitored',
            '5.4' => 'Anti-phishing mechanisms protect users against phishing attacks',
        ]],
        ['6', 'Develop and maintain secure systems and software', 'Maintain a Vulnerability Management Program', [
            '6.1' => 'Processes and mechanisms for developing and maintaining secure systems and software are defined and understood',
            '6.2' => 'Bespoke and custom software are developed securely',
            '6.3' => 'Security vulnerabilities are identified and addressed',
            '6.4' => 'Public-facing web applications are protected against attacks',
            '6.5' => 'Changes to all system components are managed securely',
        ]],
        ['7', 'Restrict access to system components and cardholder data by business need to know', 'Implement Strong Access Control Measures', [
            '7.1' => 'Processes and mechanisms for restricting access by business need to know are defined and understood',
            '7.2' => 'Access to system components and data is appropriately defined and assigned',
            '7.3' => 'Access to system components and data is managed via an access control system(s)',
        ]],
        ['8', 'Identify users and authenticate access to system components', 'Implement Strong Access Control Measures', [
            '8.1' => 'Processes and mechanisms for identifying users and authenticating access are defined and understood',
            '8.2' => 'User identification and related accounts for users and administrators are strictly managed throughout an account\'s lifecycle',
            '8.3' => 'Strong authentication for users and administrators is established and managed',
            '8.4' => 'Multi-factor authentication (MFA) is implemented to secure access into the CDE',
            '8.5' => 'Multi-factor authentication (MFA) systems are configured to prevent misuse',
            '8.6' => 'Use of application and system accounts and associated authentication factors is strictly managed',
        ]],
        ['9', 'Restrict physical access to cardholder data', 'Implement Strong Access Control Measures', [
            '9.1' => 'Processes and mechanisms for restricting physical access to cardholder data are defined and understood',
            '9.2' => 'Physical access controls manage entry into facilities and systems containing cardholder data',
            '9.3' => 'Physical access for personnel and visitors is authorized and managed',
            '9.4' => 'Media with cardholder data is securely stored, accessed, distributed, and destroyed',
            '9.5' => 'Point-of-interaction (POI) devices are protected from tampering and unauthorized substitution',
        ]],
        ['10', 'Log and monitor all access to system components and cardholder data', 'Regularly Monitor and Test Networks', [
            '10.1' => 'Processes and mechanisms for logging and monitoring all access are defined and documented',
            '10.2' => 'Audit logs are implemented to support the detection of anomalies and suspicious activity, and the forensic analysis of events',
            '10.3' => 'Audit logs are protected from destruction and unauthorized modifications',
            '10.4' => 'Audit logs are reviewed to identify anomalies or suspicious activity',
            '10.5' => 'Audit log history is retained and available for analysis',
            '10.6' => 'Time-synchronization mechanisms support consistent time settings across all systems',
            '10.7' => 'Failures of critical security control systems are detected, reported, and responded to promptly',
        ]],
        ['11', 'Test security of systems and networks regularly', 'Regularly Monitor and Test Networks', [
            '11.1' => 'Processes and mechanisms for regularly testing security of systems and networks are defined and understood',
            '11.2' => 'Wireless access points are identified and monitored, and unauthorized wireless access points are addressed',
            '11.3' => 'External and internal vulnerabilities are regularly identified, prioritized, and addressed',
            '11.4' => 'External and internal penetration testing is regularly performed, and exploitable vulnerabilities and security weaknesses are corrected',
            '11.5' => 'Network intrusions and unexpected file changes are detected and responded to',
            '11.6' => 'Unauthorized changes on payment pages are detected and responded to',
        ]],
        ['12', 'Support information security with organizational policies and programs', 'Maintain an Information Security Policy', [
            '12.1' => 'A comprehensive information security policy that governs and provides direction for protection of the entity\'s information assets is known and current',
            '12.2' => 'Acceptable use policies for end-user technologies are defined and implemented',
            '12.3' => 'Risks to the cardholder data environment are formally identified, evaluated, and managed',
            '12.4' => 'PCI DSS compliance is managed',
            '12.5' => 'PCI DSS scope is documented and validated',
            '12.6' => 'Security awareness education is an ongoing activity',
            '12.7' => 'Personnel are screened to reduce risks from insider threats',
            '12.8' => 'Risk to information assets associated with third-party service provider (TPSP) relationships is managed',
            '12.9' => 'Third-party service providers (TPSPs) support their customers\' PCI DSS compliance',
            '12.10' => 'Suspected and confirmed security incidents that could impact the CDE are responded to immediately',
        ]],
    ];

    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('pci_scope', 16)->nullable()->after('data_classification');
            $table->text('pci_scope_note')->nullable()->after('pci_scope');
        });

        if (DB::table('control_frameworks')->where('slug', self::SLUG)->exists()) {
            return;
        }
        $now = now();
        $fwId = DB::table('control_frameworks')->insertGetId([
            'name' => 'PCI DSS v4.0.1', 'slug' => self::SLUG, 'short_name' => 'PCI DSS',
            'description' => 'Payment Card Industry Data Security Standard v4.0.1: the 12 principal requirements and their defined sub-requirements.',
            'version' => '4.0.1', 'issuing_body' => 'PCI Security Standards Council', 'category' => 'security', 'jurisdiction' => 'Global',
            'is_system' => true, 'is_active' => true, 'url' => 'https://www.pcisecuritystandards.org', 'sort_order' => 40,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::REQUIREMENTS as $i => [$code, $title, $goal, $subs]) {
            $parent = DB::table('framework_requirements')->insertGetId([
                'framework_id' => $fwId, 'parent_id' => null, 'requirement_code' => $code, 'title' => $title, 'section' => $goal,
                'level' => 0, 'is_mandatory' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $n = 0;
            foreach ($subs as $subCode => $subTitle) {
                DB::table('framework_requirements')->insert([
                    'framework_id' => $fwId, 'parent_id' => $parent, 'requirement_code' => $subCode, 'title' => $subTitle, 'section' => $goal,
                    'level' => 1, 'is_mandatory' => true, 'sort_order' => $n++, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $fwId = DB::table('control_frameworks')->where('slug', self::SLUG)->value('id');
        if ($fwId) {
            DB::table('framework_requirements')->where('framework_id', $fwId)->whereNotNull('parent_id')->delete();
            DB::table('framework_requirements')->where('framework_id', $fwId)->delete();
            DB::table('control_frameworks')->where('id', $fwId)->delete();
        }
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['pci_scope', 'pci_scope_note']));
    }
};

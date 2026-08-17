<?php

namespace Database\Seeders;

use App\Models\ControlFramework;
use App\Models\FrameworkRequirement;
use Illuminate\Database\Seeder;

class RegulatoryFrameworkSeeder extends Seeder
{
    public function run(): void
    {
        // === NDPA 2023 ===
        $ndpa = ControlFramework::create([
            'name' => 'Nigeria Data Protection Act 2023',
            'slug' => 'ndpa-2023',
            'short_name' => 'NDPA 2023',
            'description' => 'The Nigeria Data Protection Act 2023 establishes the legal framework for data protection in Nigeria.',
            'version' => '2023',
            'issuing_body' => 'Nigeria Data Protection Commission',
            'category' => 'regulatory',
            'jurisdiction' => 'NG',
            'is_system' => true,
            'sort_order' => 1,
        ]);

        $this->seedNdpaRequirements($ndpa);

        // === CBN Cybersecurity Framework ===
        $cbn = ControlFramework::create([
            'name' => 'CBN Risk-Based Cybersecurity Framework',
            'slug' => 'cbn-cybersecurity',
            'short_name' => 'CBN Framework',
            'description' => 'CBN regulatory framework for cybersecurity governance in Nigerian financial institutions.',
            'version' => '2023',
            'issuing_body' => 'Central Bank of Nigeria',
            'category' => 'regulatory',
            'jurisdiction' => 'NG',
            'is_system' => true,
            'sort_order' => 2,
        ]);

        $this->seedCbnRequirements($cbn);

        // === ISO 27001:2022 ===
        $iso = ControlFramework::create([
            'name' => 'ISO/IEC 27001:2022',
            'slug' => 'iso-27001-2022',
            'short_name' => 'ISO 27001',
            'description' => 'International standard for information security management systems (ISMS).',
            'version' => '2022',
            'issuing_body' => 'ISO/IEC',
            'category' => 'standard',
            'jurisdiction' => 'global',
            'is_system' => true,
            'sort_order' => 3,
        ]);

        $this->seedIso27001Requirements($iso);

        // === NIST CSF 2.0 ===
        $nist = ControlFramework::create([
            'name' => 'NIST Cybersecurity Framework 2.0',
            'slug' => 'nist-csf-2',
            'short_name' => 'NIST CSF 2.0',
            'description' => 'NIST framework for improving critical infrastructure cybersecurity.',
            'version' => '2.0',
            'issuing_body' => 'National Institute of Standards and Technology',
            'category' => 'framework',
            'jurisdiction' => 'global',
            'is_system' => true,
            'sort_order' => 4,
        ]);

        $this->seedNistRequirements($nist);
    }

    private function seedNdpaRequirements(ControlFramework $fw): void
    {
        $sections = [
            ['code' => 'NDPA-1', 'title' => 'Lawful Processing', 'section' => 'Part II', 'children' => [
                ['code' => 'NDPA-1.1', 'title' => 'Consent of data subject'],
                ['code' => 'NDPA-1.2', 'title' => 'Performance of contract'],
                ['code' => 'NDPA-1.3', 'title' => 'Legal obligation compliance'],
                ['code' => 'NDPA-1.4', 'title' => 'Vital interest of data subject'],
                ['code' => 'NDPA-1.5', 'title' => 'Public interest or official authority'],
                ['code' => 'NDPA-1.6', 'title' => 'Legitimate interest'],
            ]],
            ['code' => 'NDPA-2', 'title' => 'Data Subject Rights', 'section' => 'Part III', 'children' => [
                ['code' => 'NDPA-2.1', 'title' => 'Right to be informed'],
                ['code' => 'NDPA-2.2', 'title' => 'Right of access'],
                ['code' => 'NDPA-2.3', 'title' => 'Right to rectification'],
                ['code' => 'NDPA-2.4', 'title' => 'Right to erasure'],
                ['code' => 'NDPA-2.5', 'title' => 'Right to restriction of processing'],
                ['code' => 'NDPA-2.6', 'title' => 'Right to data portability'],
                ['code' => 'NDPA-2.7', 'title' => 'Right to object'],
            ]],
            ['code' => 'NDPA-3', 'title' => 'Data Controller Obligations', 'section' => 'Part IV', 'children' => [
                ['code' => 'NDPA-3.1', 'title' => 'Data protection impact assessment'],
                ['code' => 'NDPA-3.2', 'title' => 'Data breach notification (72 hours)'],
                ['code' => 'NDPA-3.3', 'title' => 'Data protection officer appointment'],
                ['code' => 'NDPA-3.4', 'title' => 'Records of processing activities'],
                ['code' => 'NDPA-3.5', 'title' => 'Security of processing'],
            ]],
            ['code' => 'NDPA-4', 'title' => 'Transfer of Personal Data', 'section' => 'Part V', 'children' => [
                ['code' => 'NDPA-4.1', 'title' => 'Adequacy of protection in recipient country'],
                ['code' => 'NDPA-4.2', 'title' => 'Appropriate safeguards'],
                ['code' => 'NDPA-4.3', 'title' => 'Binding corporate rules'],
            ]],
            ['code' => 'NDPA-5', 'title' => 'Enforcement & Penalties', 'section' => 'Part VII', 'children' => [
                ['code' => 'NDPA-5.1', 'title' => 'Administrative penalties'],
                ['code' => 'NDPA-5.2', 'title' => 'Criminal offences'],
                ['code' => 'NDPA-5.3', 'title' => 'Compensation and damages'],
            ]],
        ];

        $this->createNestedRequirements($fw, $sections);
    }

    private function seedCbnRequirements(ControlFramework $fw): void
    {
        $sections = [
            ['code' => 'CBN-1', 'title' => 'Governance and Oversight', 'section' => 'Governance', 'children' => [
                ['code' => 'CBN-1.1', 'title' => 'Board oversight of cybersecurity'],
                ['code' => 'CBN-1.2', 'title' => 'CISO appointment and reporting'],
                ['code' => 'CBN-1.3', 'title' => 'Cybersecurity policy and strategy'],
                ['code' => 'CBN-1.4', 'title' => 'Cybersecurity budget allocation'],
            ]],
            ['code' => 'CBN-2', 'title' => 'Risk Assessment', 'section' => 'Risk', 'children' => [
                ['code' => 'CBN-2.1', 'title' => 'Cybersecurity risk assessment process'],
                ['code' => 'CBN-2.2', 'title' => 'Risk register maintenance'],
                ['code' => 'CBN-2.3', 'title' => 'Third-party risk management'],
                ['code' => 'CBN-2.4', 'title' => 'Periodic risk review'],
            ]],
            ['code' => 'CBN-3', 'title' => 'Cyber Resilience', 'section' => 'Resilience', 'children' => [
                ['code' => 'CBN-3.1', 'title' => 'Network security'],
                ['code' => 'CBN-3.2', 'title' => 'Application security'],
                ['code' => 'CBN-3.3', 'title' => 'Identity and access management'],
                ['code' => 'CBN-3.4', 'title' => 'Data security and protection'],
                ['code' => 'CBN-3.5', 'title' => 'Encryption standards'],
            ]],
            ['code' => 'CBN-4', 'title' => 'Monitoring and Detection', 'section' => 'Detection', 'children' => [
                ['code' => 'CBN-4.1', 'title' => 'Security operations center'],
                ['code' => 'CBN-4.2', 'title' => 'Log management and monitoring'],
                ['code' => 'CBN-4.3', 'title' => 'Vulnerability management'],
                ['code' => 'CBN-4.4', 'title' => 'Penetration testing'],
            ]],
            ['code' => 'CBN-5', 'title' => 'Incident Response', 'section' => 'Response', 'children' => [
                ['code' => 'CBN-5.1', 'title' => 'Incident response plan'],
                ['code' => 'CBN-5.2', 'title' => 'CBN notification within 24 hours'],
                ['code' => 'CBN-5.3', 'title' => 'Forensic investigation capability'],
                ['code' => 'CBN-5.4', 'title' => 'Business continuity planning'],
            ]],
        ];

        $this->createNestedRequirements($fw, $sections);
    }

    private function seedIso27001Requirements(ControlFramework $fw): void
    {
        $sections = [
            ['code' => 'A.5', 'title' => 'Organizational Controls', 'section' => 'Annex A', 'children' => [
                ['code' => 'A.5.1', 'title' => 'Policies for information security'],
                ['code' => 'A.5.2', 'title' => 'Information security roles and responsibilities'],
                ['code' => 'A.5.3', 'title' => 'Segregation of duties'],
                ['code' => 'A.5.4', 'title' => 'Management responsibilities'],
                ['code' => 'A.5.5', 'title' => 'Contact with authorities'],
                ['code' => 'A.5.6', 'title' => 'Contact with special interest groups'],
                ['code' => 'A.5.7', 'title' => 'Threat intelligence'],
                ['code' => 'A.5.8', 'title' => 'Information security in project management'],
            ]],
            ['code' => 'A.6', 'title' => 'People Controls', 'section' => 'Annex A', 'children' => [
                ['code' => 'A.6.1', 'title' => 'Screening'],
                ['code' => 'A.6.2', 'title' => 'Terms and conditions of employment'],
                ['code' => 'A.6.3', 'title' => 'Information security awareness, education and training'],
                ['code' => 'A.6.4', 'title' => 'Disciplinary process'],
                ['code' => 'A.6.5', 'title' => 'Responsibilities after termination or change of employment'],
            ]],
            ['code' => 'A.7', 'title' => 'Physical Controls', 'section' => 'Annex A', 'children' => [
                ['code' => 'A.7.1', 'title' => 'Physical security perimeters'],
                ['code' => 'A.7.2', 'title' => 'Physical entry'],
                ['code' => 'A.7.3', 'title' => 'Securing offices, rooms and facilities'],
                ['code' => 'A.7.4', 'title' => 'Physical security monitoring'],
            ]],
            ['code' => 'A.8', 'title' => 'Technological Controls', 'section' => 'Annex A', 'children' => [
                ['code' => 'A.8.1', 'title' => 'User endpoint devices'],
                ['code' => 'A.8.2', 'title' => 'Privileged access rights'],
                ['code' => 'A.8.3', 'title' => 'Information access restriction'],
                ['code' => 'A.8.4', 'title' => 'Access to source code'],
                ['code' => 'A.8.5', 'title' => 'Secure authentication'],
                ['code' => 'A.8.6', 'title' => 'Capacity management'],
                ['code' => 'A.8.7', 'title' => 'Protection against malware'],
                ['code' => 'A.8.8', 'title' => 'Management of technical vulnerabilities'],
                ['code' => 'A.8.9', 'title' => 'Configuration management'],
            ]],
        ];

        $this->createNestedRequirements($fw, $sections);
    }

    private function seedNistRequirements(ControlFramework $fw): void
    {
        $sections = [
            ['code' => 'GV', 'title' => 'Govern', 'section' => 'Core', 'children' => [
                ['code' => 'GV.OC', 'title' => 'Organizational Context'],
                ['code' => 'GV.RM', 'title' => 'Risk Management Strategy'],
                ['code' => 'GV.RR', 'title' => 'Roles, Responsibilities, and Authorities'],
                ['code' => 'GV.PO', 'title' => 'Policy'],
                ['code' => 'GV.OV', 'title' => 'Oversight'],
                ['code' => 'GV.SC', 'title' => 'Cybersecurity Supply Chain Risk Management'],
            ]],
            ['code' => 'ID', 'title' => 'Identify', 'section' => 'Core', 'children' => [
                ['code' => 'ID.AM', 'title' => 'Asset Management'],
                ['code' => 'ID.RA', 'title' => 'Risk Assessment'],
                ['code' => 'ID.IM', 'title' => 'Improvement'],
            ]],
            ['code' => 'PR', 'title' => 'Protect', 'section' => 'Core', 'children' => [
                ['code' => 'PR.AA', 'title' => 'Identity Management, Authentication, and Access Control'],
                ['code' => 'PR.AT', 'title' => 'Awareness and Training'],
                ['code' => 'PR.DS', 'title' => 'Data Security'],
                ['code' => 'PR.PS', 'title' => 'Platform Security'],
                ['code' => 'PR.IR', 'title' => 'Technology Infrastructure Resilience'],
            ]],
            ['code' => 'DE', 'title' => 'Detect', 'section' => 'Core', 'children' => [
                ['code' => 'DE.CM', 'title' => 'Continuous Monitoring'],
                ['code' => 'DE.AE', 'title' => 'Adverse Event Analysis'],
            ]],
            ['code' => 'RS', 'title' => 'Respond', 'section' => 'Core', 'children' => [
                ['code' => 'RS.MA', 'title' => 'Incident Management'],
                ['code' => 'RS.AN', 'title' => 'Incident Analysis'],
                ['code' => 'RS.CO', 'title' => 'Incident Response Reporting and Communication'],
                ['code' => 'RS.MI', 'title' => 'Incident Mitigation'],
            ]],
            ['code' => 'RC', 'title' => 'Recover', 'section' => 'Core', 'children' => [
                ['code' => 'RC.RP', 'title' => 'Incident Recovery Plan Execution'],
                ['code' => 'RC.CO', 'title' => 'Incident Recovery Communication'],
            ]],
        ];

        $this->createNestedRequirements($fw, $sections);
    }

    private function createNestedRequirements(ControlFramework $fw, array $sections): void
    {
        foreach ($sections as $i => $section) {
            $parent = FrameworkRequirement::create([
                'framework_id' => $fw->id,
                'requirement_code' => $section['code'],
                'title' => $section['title'],
                'section' => $section['section'] ?? null,
                'level' => 'domain',
                'sort_order' => $i,
            ]);

            foreach (($section['children'] ?? []) as $j => $child) {
                FrameworkRequirement::create([
                    'framework_id' => $fw->id,
                    'parent_id' => $parent->id,
                    'requirement_code' => $child['code'],
                    'title' => $child['title'],
                    'section' => $section['section'] ?? null,
                    'level' => 'requirement',
                    'sort_order' => $j,
                ]);
            }
        }
    }
}

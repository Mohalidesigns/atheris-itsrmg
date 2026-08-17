<?php

namespace Database\Seeders;

use App\Modules\CBNCSAT\Models\CsatMaStatement;
use Illuminate\Database\Seeder;

class CsatMaStatementsSeeder extends Seeder
{
    public function run(): void
    {
        $seq = 0;
        $statements = $this->getStatements();

        foreach ($statements as $s) {
            CsatMaStatement::create([
                'domain_code' => $s['dc'],
                'domain_name' => $s['dn'],
                'factor_code' => $s['fc'],
                'factor_name' => $s['fn'],
                'component_code' => $s['cc'],
                'component_name' => $s['cn'],
                'maturity_level' => $s['ml'],
                'sequence' => ++$seq,
                'statement_text' => $s['text'],
            ]);
        }

        $this->command->info("Seeded {$seq} CBN-CSAT maturity declarative statements.");
    }

    private function getStatements(): array
    {
        // Domain 1: Cyber Risk Management & Oversight (141 statements)
        // Domain 2: Threat Intelligence & Collaboration (45 statements)
        // Domain 3: Cybersecurity Controls (174 statements)
        // Domain 4: External Dependency Management (51 statements)
        // Domain 5: Cyber Incident Management & Resilience (83 statements)
        // Total: 494 statements

        $statements = [];

        // ===== DOMAIN 1: Cyber Risk Management & Oversight =====
        $d1 = ['dc' => 1, 'dn' => 'Cyber Risk Management & Oversight'];

        // D1-F1: Governance
        // D1-F1-C1: Oversight
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F1', 'Governance', 'D1-F1-C1', 'Oversight', [
            1 => [
                'Designated member(s) of management are responsible for overseeing information security.',
                'Information security roles and responsibilities have been identified.',
                'The institution has an information security program that is approved by the board or an appropriate board committee.',
                'The board or an appropriate board committee reviews and approves the institution\'s cybersecurity program at least annually.',
                'The board or appropriate committee is informed of cybersecurity risks and issues at least quarterly.',
                'The board or appropriate committee is informed about the results of independent assessments of the institution\'s cybersecurity.',
            ],
            2 => [
                'Cybersecurity oversight roles have been enhanced with dedicated resources.',
                'The board has appointed a CISO or equivalent with direct board reporting.',
                'The board reviews cybersecurity budgets and resource allocation.',
                'Management provides comprehensive cybersecurity reporting that includes emerging threats.',
            ],
            3 => [
                'A board-level cybersecurity committee or subcommittee has been established.',
                'The cybersecurity committee reviews and challenges management risk assessments.',
                'Cybersecurity metrics and KPIs are reported to the board with trend analysis.',
                'The board approves cybersecurity risk appetite statements and tolerance levels.',
            ],
            4 => [
                'The board engages external cybersecurity advisors for independent assessment.',
                'The institution participates in industry-level cybersecurity governance forums.',
                'Board members receive regular cybersecurity competency training.',
            ],
            5 => [
                'The institution leads or contributes to development of industry cybersecurity governance frameworks.',
                'Board-level cybersecurity oversight model is recognised as industry best practice.',
            ],
        ]));

        // D1-F1-C2: Strategy/Policies
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F1', 'Governance', 'D1-F1-C2', 'Strategy & Policies', [
            1 => [
                'An information security and business continuity risk management function(s) exists within the institution.',
                'The institution has an information security strategy that integrates technology, policies, procedures, and training.',
                'Information security policies, standards, and procedures are documented and have been implemented.',
                'The institution has policies commensurate with its risk and complexity that address information security threats.',
                'Policies are reviewed and updated regularly to address new threats, changes in regulatory requirements, and business considerations.',
            ],
            2 => [
                'The cybersecurity strategy is reviewed and updated at least annually.',
                'Policies address emerging technology risks such as cloud, mobile, and IoT.',
                'A policy exception process is formally documented and tracked.',
                'Cybersecurity policies are aligned with industry frameworks (NIST, ISO 27001, COBIT).',
            ],
            3 => [
                'Cybersecurity strategy is integrated with the institution\'s overall enterprise risk management strategy.',
                'Policies are benchmarked against peer institutions and regulatory expectations.',
                'Policy compliance is measured and reported using automated tools.',
            ],
            4 => [
                'Cybersecurity strategy is forward-looking and considers threat intelligence and emerging risks.',
                'Policies are adaptive and updated in near real-time based on threat landscape changes.',
            ],
            5 => [
                'The institution contributes to industry cybersecurity policy development and standard-setting.',
                'Cybersecurity strategy includes predictive analytics and threat modelling.',
            ],
        ]));

        // D1-F2: Risk Management
        // D1-F2-C1: Risk Management Program
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F2', 'Risk Management', 'D1-F2-C1', 'Risk Management Program', [
            1 => [
                'A risk management program is in place and includes cybersecurity risk.',
                'The risk management program identifies, measures, mitigates, and monitors risk.',
                'The institution has a process to identify and assess cybersecurity threats and risks.',
                'Cybersecurity risks are included in the institution\'s enterprise risk management framework.',
            ],
            2 => [
                'Risk assessments incorporate threat intelligence from internal and external sources.',
                'Risk appetite for cybersecurity has been documented and approved by the board.',
                'Residual cybersecurity risk is tracked and reported.',
            ],
            3 => [
                'Cybersecurity risk is quantified using industry-standard methodologies (e.g., FAIR).',
                'Risk assessments incorporate scenario analysis and stress testing.',
                'Risk management processes are validated through independent review.',
            ],
            4 => [
                'Advanced risk analytics and predictive modelling are used.',
                'Real-time risk dashboards provide dynamic risk posture views.',
            ],
            5 => [
                'The institution contributes to development of industry cybersecurity risk management standards.',
            ],
        ]));

        // D1-F2-C2: Risk Assessment
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F2', 'Risk Management', 'D1-F2-C2', 'Risk Assessment', [
            1 => [
                'Risk assessments are performed before major system changes or new product/service introductions.',
                'Risk assessment processes include cybersecurity risks.',
                'Risk assessments address threats relevant to the institution\'s operating environment.',
            ],
            2 => [
                'Risk assessments are updated at least annually or when significant changes occur.',
                'Threat scenarios specific to the Nigerian financial sector are incorporated.',
            ],
            3 => [
                'Risk assessments include advanced threat modelling techniques.',
                'Results of risk assessments drive investment and resource allocation decisions.',
            ],
            4 => [
                'Continuous risk assessment processes are automated and integrated with threat intelligence feeds.',
            ],
            5 => [
                'Risk assessment methodologies set new standards for the Nigerian financial services sector.',
            ],
        ]));

        // D1-F3: Resources
        // D1-F3-C1: Staffing
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F3', 'Resources', 'D1-F3-C1', 'Staffing', [
            1 => [
                'Adequate staff with cybersecurity knowledge, skills, and abilities have been hired.',
                'Cybersecurity responsibilities have been assigned to specific staff.',
                'Employment candidates, contractors, and third parties are subject to background verification.',
            ],
            2 => [
                'A dedicated CISO or equivalent role has been established.',
                'Cybersecurity staffing levels are benchmarked against industry standards.',
                'Succession planning exists for key cybersecurity roles.',
            ],
            3 => [
                'Cybersecurity expertise spans multiple specialist domains.',
                'Cross-training and rotation programs reduce key-person risk.',
            ],
            4 => [
                'The cybersecurity team includes threat hunting and advanced analytics capabilities.',
            ],
            5 => [
                'The institution\'s cybersecurity team is recognised as an industry centre of excellence.',
            ],
        ]));

        // D1-F4: Training & Culture
        // D1-F4-C1: Training
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F4', 'Training & Culture', 'D1-F4-C1', 'Training', [
            1 => [
                'Annual information security training has been established for all employees and agents.',
                'Targeted training is provided based on specific business roles and information security responsibilities.',
                'Training includes recognition and reporting of potential cybersecurity incidents.',
                'Training addresses phishing and social engineering awareness.',
            ],
            2 => [
                'Training is updated to address current and emerging threats.',
                'Training includes role-specific modules for IT, finance, and executive staff.',
                'Phishing simulation exercises are conducted at least quarterly.',
            ],
            3 => [
                'Training effectiveness is measured through testing and demonstrated behaviour change.',
                'Advanced training programmes exist for IT and cybersecurity staff.',
            ],
            4 => [
                'Continuous learning programmes with gamification drive high engagement.',
                'Training incorporates lessons learned from industry cyber incidents.',
            ],
            5 => [
                'The institution runs cybersecurity awareness programmes for the broader financial sector.',
            ],
        ]));

        // D1-F4-C2: Culture
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F4', 'Training & Culture', 'D1-F4-C2', 'Culture', [
            1 => [
                'Management promotes a security-conscious culture.',
                'Employees are aware of their cybersecurity responsibilities.',
            ],
            2 => [
                'Cybersecurity is communicated as a shared responsibility across the institution.',
                'Recognition programmes incentivise positive security behaviours.',
            ],
            3 => [
                'Cybersecurity culture metrics are measured and reported.',
                'Security champions are embedded across business units.',
            ],
            4 => [
                'A proactive security culture enables rapid threat identification from all levels.',
            ],
            5 => [
                'The institution is recognised externally for its cybersecurity culture excellence.',
            ],
        ]));

        // D1-F2-C3: Audit
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F2', 'Risk Management', 'D1-F2-C3', 'Audit', [
            1 => [
                'Independent audit or assessment of cybersecurity operations is performed at least annually.',
                'Audit scope covers cybersecurity governance, operations, and compliance.',
                'Audit findings are reported to the board or appropriate committee.',
                'Audit recommendations are tracked to remediation.',
            ],
            2 => [
                'Audit includes testing of cybersecurity controls effectiveness.',
                'Audit scope expands to cover third-party and vendor cybersecurity.',
            ],
            3 => [
                'Continuous audit methodologies are employed for cybersecurity.',
                'Audit uses data analytics to identify cybersecurity anomalies.',
            ],
            4 => [
                'Audit incorporates threat intelligence and advanced testing techniques.',
            ],
            5 => [
                'Audit methodology contributes to industry cybersecurity audit standards.',
            ],
        ]));

        // D1-F3-C2: IT Asset Management
        $statements = array_merge($statements, $this->buildComponent($d1, 'D1-F3', 'Resources', 'D1-F3-C2', 'IT Asset Management', [
            1 => [
                'An IT asset inventory is maintained and includes hardware, software, and data assets.',
                'IT assets are classified based on their criticality and sensitivity.',
                'Asset ownership is assigned and documented.',
            ],
            2 => [
                'Asset inventory is regularly updated and reconciled.',
                'Automated asset discovery tools supplement manual inventory.',
            ],
            3 => [
                'Asset lifecycle management is integrated with cybersecurity controls.',
                'Real-time asset visibility is maintained across the enterprise.',
            ],
            4 => [
                'Advanced asset management integrates with vulnerability management and threat intelligence.',
            ],
            5 => [
                'Asset management practices contribute to industry standard development.',
            ],
        ]));

        // ===== DOMAIN 2: Threat Intelligence & Collaboration =====
        $d2 = ['dc' => 2, 'dn' => 'Threat Intelligence & Collaboration'];

        // D2-F1-C1: Threat Intelligence & Information
        $statements = array_merge($statements, $this->buildComponent($d2, 'D2-F1', 'Threat Intelligence', 'D2-F1-C1', 'Threat Intelligence & Information', [
            1 => [
                'The institution belongs to or subscribes to a threat and vulnerability information-sharing source.',
                'Threat information is used to monitor threats and vulnerabilities.',
                'The institution identifies and tracks relevant cyber threats.',
                'Vulnerability alerts are received from credible sources and addressed in a timely manner.',
            ],
            2 => [
                'Threat intelligence is contextualised for the Nigerian banking sector.',
                'Intelligence feeds cover both strategic and tactical threat levels.',
                'Threat information is correlated with internal security events.',
            ],
            3 => [
                'Threat intelligence is automated and integrated into security operations.',
                'Predictive threat analysis is performed based on sector-specific intelligence.',
                'Threat intelligence drives proactive security control adjustments.',
            ],
            4 => [
                'Advanced threat intelligence includes dark web monitoring and adversary profiling.',
                'Threat hunting operations leverage intelligence for proactive defence.',
            ],
            5 => [
                'The institution is a recognised contributor to Nigerian financial sector threat intelligence.',
                'Intelligence capabilities enable prediction of emerging threat campaigns.',
            ],
        ]));

        // D2-F2-C1: Monitoring & Analyzing
        $statements = array_merge($statements, $this->buildComponent($d2, 'D2-F2', 'Monitoring & Analyzing', 'D2-F2-C1', 'Monitoring & Analyzing', [
            1 => [
                'Network and system activity is monitored for potential cybersecurity events.',
                'Security alerts are analysed and prioritised.',
                'Monitoring includes review of logs from critical systems and applications.',
                'Anomalous activity is investigated.',
            ],
            2 => [
                'Monitoring covers all critical assets including cloud and mobile channels.',
                'Correlation rules detect multi-stage attack patterns.',
                'Security monitoring operates on a 24/7 basis.',
            ],
            3 => [
                'Advanced analytics and machine learning enhance anomaly detection.',
                'SIEM platform integrates with all critical data sources.',
                'Monitoring effectiveness is measured and reported.',
            ],
            4 => [
                'Automated response capabilities reduce mean time to detect and respond.',
                'User and entity behaviour analytics (UEBA) detect insider threats.',
            ],
            5 => [
                'Monitoring capabilities set the benchmark for the Nigerian financial sector.',
            ],
        ]));

        // D2-F3-C1: Information Sharing
        $statements = array_merge($statements, $this->buildComponent($d2, 'D2-F3', 'Information Sharing', 'D2-F3-C1', 'Information Sharing', [
            1 => [
                'The institution reports cybersecurity incidents to relevant authorities as required.',
                'Processes exist for sharing cybersecurity information with law enforcement.',
                'Information sharing with industry peers occurs on an ad hoc basis.',
            ],
            2 => [
                'The institution participates in industry cybersecurity information-sharing groups.',
                'Information sharing is formalised with selected peer institutions.',
                'Shared intelligence follows agreed taxonomies and classification standards.',
            ],
            3 => [
                'Active participation in CBN and NIBSS cybersecurity threat sharing forums.',
                'Automated threat indicator sharing with trusted partners.',
            ],
            4 => [
                'The institution leads or coordinates sector-level information sharing initiatives.',
            ],
            5 => [
                'The institution drives national-level cybersecurity information sharing frameworks.',
            ],
        ]));

        // ===== DOMAIN 3: Cybersecurity Controls =====
        $d3 = ['dc' => 3, 'dn' => 'Cybersecurity Controls'];

        // D3-F1: Preventative Controls
        // D3-F1-C1: Infrastructure Management
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F1', 'Preventative Controls', 'D3-F1-C1', 'Infrastructure Management', [
            1 => [
                'Network perimeter defence tools (e.g., firewalls, IPS) are implemented.',
                'Security hardening standards are documented and applied to all systems.',
                'System configurations are based on industry security standards or benchmarks.',
                'Ports, functions, protocols, and services are restricted to those required for business.',
                'Secure configurations for hardware and software are documented and maintained.',
                'Systems not compliant with security configuration standards are identified and remediated.',
            ],
            2 => [
                'Network segmentation isolates sensitive areas from general network traffic.',
                'Web application firewalls protect internet-facing applications.',
                'Infrastructure security is tested through regular vulnerability assessments.',
                'Configuration management databases track all infrastructure components.',
            ],
            3 => [
                'Micro-segmentation provides granular network isolation.',
                'Infrastructure security is continuously monitored and automatically remediated.',
                'Zero-trust architecture principles are applied to network design.',
            ],
            4 => [
                'Software-defined networking enables dynamic security policy enforcement.',
                'Advanced deception technologies detect lateral movement.',
            ],
            5 => [
                'Infrastructure security practices pioneer new approaches adopted by the industry.',
            ],
        ]));

        // D3-F1-C2: Access & Data Management
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F1', 'Preventative Controls', 'D3-F1-C2', 'Access & Data Management', [
            1 => [
                'Logical access controls are in place for all systems and applications.',
                'Authentication mechanisms are used to control access (passwords, tokens, biometrics).',
                'Access is granted based on the principle of least privilege.',
                'User access reviews are performed at least annually.',
                'Data is classified according to sensitivity (e.g., confidential, internal, public).',
                'Data protection controls are commensurate with data classification.',
                'Customer data is protected in accordance with NDPA 2023 requirements.',
                'Remote access requires multi-factor authentication.',
            ],
            2 => [
                'Multi-factor authentication is required for all privileged and remote access.',
                'Data loss prevention controls monitor and restrict sensitive data movement.',
                'Access recertification is performed quarterly for privileged accounts.',
                'Encryption is applied to sensitive data at rest and in transit.',
            ],
            3 => [
                'Privileged access management (PAM) tools enforce session recording and credential rotation.',
                'Data classification and protection is automated through DLP integration.',
                'Identity governance and administration (IGA) automates access lifecycle.',
            ],
            4 => [
                'Adaptive authentication adjusts security controls based on risk signals.',
                'Advanced data analytics detect anomalous access patterns.',
            ],
            5 => [
                'Access and data management practices contribute to industry standards development.',
            ],
        ]));

        // D3-F1-C3: Endpoint Security
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F1', 'Preventative Controls', 'D3-F1-C3', 'Endpoint Security', [
            1 => [
                'Anti-malware software is installed and regularly updated on all endpoints.',
                'Endpoints are configured to prevent unauthorised software installation.',
                'Mobile device management controls are in place for devices accessing corporate resources.',
                'Removable media usage is restricted and monitored.',
            ],
            2 => [
                'Endpoint detection and response (EDR) tools provide advanced threat detection.',
                'Application whitelisting is implemented on critical systems.',
                'Endpoint security configurations are centrally managed and enforced.',
            ],
            3 => [
                'Endpoint security includes behavioural analysis and machine learning detection.',
                'Automated endpoint isolation capabilities contain threats rapidly.',
            ],
            4 => [
                'Advanced endpoint protection integrates with threat intelligence and SOAR platforms.',
            ],
            5 => [
                'Endpoint security capabilities pioneer new protection approaches for the sector.',
            ],
        ]));

        // D3-F1-C4: Secure Coding
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F1', 'Preventative Controls', 'D3-F1-C4', 'Secure Coding', [
            1 => [
                'Secure software development practices are followed.',
                'Software development includes security testing before deployment.',
                'Known vulnerabilities in production software are tracked and remediated.',
            ],
            2 => [
                'Secure coding standards are documented and enforced (e.g., OWASP).',
                'Static and dynamic application security testing is integrated into the SDLC.',
                'Code review processes include security-focused reviews.',
            ],
            3 => [
                'DevSecOps practices integrate security throughout the development pipeline.',
                'Automated security testing is embedded in CI/CD pipelines.',
            ],
            4 => [
                'Advanced application security includes runtime protection (RASP) and API security.',
            ],
            5 => [
                'Secure coding practices contribute to industry development standards.',
            ],
        ]));

        // D3-F2: Detective Controls
        // D3-F2-C1: Threat Detection
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F2', 'Detective Controls', 'D3-F2-C1', 'Threat Detection', [
            1 => [
                'Security event detection processes are in place.',
                'Antivirus and anti-malware tools detect known threats.',
                'Intrusion detection/prevention systems monitor network traffic.',
                'System and application logs are collected and reviewed.',
            ],
            2 => [
                'SIEM aggregates and correlates events across multiple sources.',
                'Detection rules are regularly updated based on threat intelligence.',
                'Network traffic analysis detects anomalous communication patterns.',
            ],
            3 => [
                'Advanced threat detection includes sandboxing and behavioural analysis.',
                'Machine learning models detect novel attack patterns.',
                'Threat hunting exercises proactively search for undetected threats.',
            ],
            4 => [
                'Automated threat detection and response reduces dwell time to minutes.',
                'Detection capabilities include advanced persistent threat (APT) detection.',
            ],
            5 => [
                'Detection capabilities contribute to sector-wide threat identification.',
            ],
        ]));

        // D3-F2-C2: Anomalous Activity Detection
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F2', 'Detective Controls', 'D3-F2-C2', 'Anomalous Activity Detection', [
            1 => [
                'Processes exist to detect anomalous activities that may indicate cybersecurity events.',
                'Alert parameters are set for information security alerts.',
                'Unauthorised access attempts are detected and reported.',
            ],
            2 => [
                'Baseline behaviour profiles are established for users and systems.',
                'Anomaly detection covers transaction patterns relevant to banking operations.',
            ],
            3 => [
                'User and entity behaviour analytics (UEBA) detect insider threats.',
                'Anomaly detection is integrated with fraud detection systems.',
            ],
            4 => [
                'Advanced analytics predict anomalous behaviour before incidents occur.',
            ],
            5 => [
                'Anomaly detection capabilities set industry benchmarks.',
            ],
        ]));

        // D3-F2-C3: Event Detection
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F2', 'Detective Controls', 'D3-F2-C3', 'Event Detection', [
            1 => [
                'Security events are logged, tracked, and analysed.',
                'Event correlation identifies related events across multiple systems.',
                'Critical security events are escalated promptly.',
            ],
            2 => [
                'Automated event detection reduces manual analysis requirements.',
                'Event severity classification drives response prioritisation.',
            ],
            3 => [
                'Advanced event correlation identifies complex multi-stage attacks.',
                'Event detection coverage extends to all critical business processes.',
            ],
            4 => [
                'Real-time event analytics enable predictive security operations.',
            ],
            5 => [
                'Event detection capabilities advance the state of practice.',
            ],
        ]));

        // D3-F3: Corrective Controls
        // D3-F3-C1: Patch Management
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F3', 'Corrective Controls', 'D3-F3-C1', 'Patch Management', [
            1 => [
                'A patch management process is in place to address known vulnerabilities.',
                'Critical patches are applied within a defined timeframe.',
                'Patch status is tracked and monitored.',
                'Compensating controls are implemented when patches cannot be applied immediately.',
            ],
            2 => [
                'Patch management covers all systems including network devices and applications.',
                'Patch testing procedures validate patches before production deployment.',
                'Patch compliance is measured and reported.',
            ],
            3 => [
                'Automated patch management tools deploy patches across the enterprise.',
                'Patch prioritisation incorporates vulnerability severity and asset criticality.',
            ],
            4 => [
                'Virtual patching provides immediate protection while permanent patches are tested.',
            ],
            5 => [
                'Patch management processes lead industry practices.',
            ],
        ]));

        // D3-F3-C2: Remediation
        $statements = array_merge($statements, $this->buildComponent($d3, 'D3-F3', 'Corrective Controls', 'D3-F3-C2', 'Remediation', [
            1 => [
                'A process is in place to remediate identified cybersecurity weaknesses.',
                'Remediation activities are tracked to completion.',
                'Root cause analysis is performed for significant cybersecurity events.',
            ],
            2 => [
                'Remediation timelines are defined based on risk severity.',
                'Remediation effectiveness is verified through retesting.',
            ],
            3 => [
                'Remediation is integrated with the enterprise risk management lifecycle.',
                'Trend analysis of remediation identifies systemic issues.',
            ],
            4 => [
                'Automated remediation workflows accelerate vulnerability closure.',
            ],
            5 => [
                'Remediation practices contribute to industry improvement programmes.',
            ],
        ]));

        // ===== DOMAIN 4: External Dependency Management =====
        $d4 = ['dc' => 4, 'dn' => 'External Dependency Management'];

        // D4-F1-C1: Connections
        $statements = array_merge($statements, $this->buildComponent($d4, 'D4-F1', 'Connections', 'D4-F1-C1', 'Connections', [
            1 => [
                'External connections are inventoried and documented.',
                'Network connections to third parties are secured.',
                'Access granted to third parties is limited to what is necessary.',
                'Third-party remote access connections are monitored.',
            ],
            2 => [
                'Third-party connections are subject to regular security assessments.',
                'API connections to third parties are secured and monitored.',
                'Connection security requirements are documented in agreements.',
            ],
            3 => [
                'Real-time monitoring of third-party connections identifies anomalies.',
                'Third-party connection security is integrated with threat intelligence.',
            ],
            4 => [
                'Advanced analytics detect sophisticated attacks through third-party channels.',
            ],
            5 => [
                'Connection security practices advance industry standards.',
            ],
        ]));

        // D4-F2: Relationship Management
        // D4-F2-C1: Due Diligence
        $statements = array_merge($statements, $this->buildComponent($d4, 'D4-F2', 'Relationship Management', 'D4-F2-C1', 'Due Diligence', [
            1 => [
                'Due diligence is performed on prospective third parties before contracts are signed.',
                'Due diligence includes assessment of the third party\'s cybersecurity practices.',
                'Critical third parties are identified and prioritised for enhanced oversight.',
            ],
            2 => [
                'Due diligence includes review of third-party cybersecurity certifications and audit reports.',
                'Third-party risk is assessed against the institution\'s risk appetite.',
            ],
            3 => [
                'Continuous monitoring of critical third-party cybersecurity posture is performed.',
                'Due diligence includes assessment of fourth-party (sub-contractor) risk.',
            ],
            4 => [
                'Advanced third-party risk intelligence platforms provide real-time risk views.',
            ],
            5 => [
                'Due diligence practices contribute to development of sector-wide standards.',
            ],
        ]));

        // D4-F2-C2: Contracts
        $statements = array_merge($statements, $this->buildComponent($d4, 'D4-F2', 'Relationship Management', 'D4-F2-C2', 'Contracts', [
            1 => [
                'Contracts with third parties include cybersecurity requirements.',
                'Contracts address data protection, confidentiality, and breach notification obligations.',
                'Service level agreements include cybersecurity performance requirements.',
            ],
            2 => [
                'Contracts require third parties to meet specific cybersecurity standards.',
                'Right-to-audit clauses are included in contracts for critical third parties.',
            ],
            3 => [
                'Contracts address data residency and localisation requirements per NDPA 2023.',
                'Contract cybersecurity requirements are regularly updated.',
            ],
            4 => [
                'Contract management integrates with real-time third-party risk monitoring.',
            ],
            5 => [
                'Contract frameworks contribute to industry best practices.',
            ],
        ]));

        // D4-F2-C3: Ongoing Monitoring
        $statements = array_merge($statements, $this->buildComponent($d4, 'D4-F2', 'Relationship Management', 'D4-F2-C3', 'Ongoing Monitoring', [
            1 => [
                'Third-party performance against service levels and cybersecurity requirements is monitored.',
                'Periodic assessments of critical third parties are conducted.',
                'Issues identified in third-party monitoring are escalated and resolved.',
            ],
            2 => [
                'Annual cybersecurity assessments are performed for critical third parties.',
                'Third-party incident response capabilities are validated.',
            ],
            3 => [
                'Continuous monitoring includes automated risk scoring of third parties.',
                'Third-party cybersecurity events are correlated with institutional risk.',
            ],
            4 => [
                'Real-time risk intelligence platforms monitor global third-party cyber events.',
            ],
            5 => [
                'Ongoing monitoring practices set the standard for the sector.',
            ],
        ]));

        // ===== DOMAIN 5: Cyber Incident Management & Resilience =====
        $d5 = ['dc' => 5, 'dn' => 'Cyber Incident Management & Resilience'];

        // D5-F1: Incident Resilience Planning
        // D5-F1-C1: Planning
        $statements = array_merge($statements, $this->buildComponent($d5, 'D5-F1', 'Incident Resilience Planning', 'D5-F1-C1', 'Planning', [
            1 => [
                'An incident response plan (IRP) has been developed and approved.',
                'The IRP addresses roles, responsibilities, and communication strategies.',
                'The IRP includes notification procedures for regulators including the CBN.',
                'The IRP addresses containment, eradication, and recovery procedures.',
                'Business continuity plans address cyber incident scenarios.',
                'Insurance coverage for cybersecurity events has been evaluated.',
            ],
            2 => [
                'The IRP includes specific scenarios relevant to Nigerian banking (e.g., USSD fraud, BEC, ATM jackpotting).',
                'Communication plans include customers, media, and regulatory stakeholders.',
                'The IRP is reviewed and updated at least annually.',
            ],
            3 => [
                'Incident response plans are integrated with enterprise crisis management.',
                'Scenario planning includes systemic attacks affecting the national payment infrastructure.',
                'Recovery time and recovery point objectives are defined for all critical systems.',
            ],
            4 => [
                'Automated incident response playbooks enable rapid containment.',
                'Planning includes scenarios for advanced persistent threats.',
            ],
            5 => [
                'Incident planning contributes to national-level cyber resilience frameworks.',
            ],
        ]));

        // D5-F1-C2: Testing
        $statements = array_merge($statements, $this->buildComponent($d5, 'D5-F1', 'Incident Resilience Planning', 'D5-F1-C2', 'Testing', [
            1 => [
                'Incident response plans are tested at least annually.',
                'Testing includes tabletop exercises involving key stakeholders.',
                'Disaster recovery tests validate system and data recovery capabilities.',
                'Test results are documented and used to update plans.',
            ],
            2 => [
                'Testing includes simulation of realistic cyber attack scenarios.',
                'Red team exercises test detection and response capabilities.',
                'Business continuity tests include cyber scenarios.',
            ],
            3 => [
                'Advanced exercises include cross-institution coordination.',
                'Testing incorporates lessons learned from industry incidents.',
            ],
            4 => [
                'Continuous testing and simulation platforms validate resilience.',
            ],
            5 => [
                'The institution leads sector-wide cyber resilience exercises.',
            ],
        ]));

        // D5-F2: Detection, Response & Mitigation
        // D5-F2-C1: Detection
        $statements = array_merge($statements, $this->buildComponent($d5, 'D5-F2', 'Detection, Response & Mitigation', 'D5-F2-C1', 'Detection', [
            1 => [
                'Processes are in place to detect cybersecurity incidents.',
                'Detection mechanisms cover network, application, and endpoint layers.',
                'Alert triage processes prioritise potential incidents.',
            ],
            2 => [
                'SOC operations provide timely detection across all critical systems.',
                'Detection capabilities cover both internal and external threat vectors.',
            ],
            3 => [
                'Advanced detection capabilities identify sophisticated and targeted attacks.',
                'Threat hunting supplements automated detection.',
            ],
            4 => [
                'AI-powered detection minimises dwell time for advanced threats.',
            ],
            5 => [
                'Detection capabilities are recognised as industry-leading.',
            ],
        ]));

        // D5-F2-C2: Response & Mitigation
        $statements = array_merge($statements, $this->buildComponent($d5, 'D5-F2', 'Detection, Response & Mitigation', 'D5-F2-C2', 'Response & Mitigation', [
            1 => [
                'Incident response procedures are documented and communicated.',
                'Containment strategies are defined for different incident types.',
                'Evidence preservation procedures support forensic investigation.',
                'Incidents are categorised and prioritised based on impact.',
            ],
            2 => [
                'Response procedures include coordination with law enforcement.',
                'Incident response teams are trained and available 24/7.',
                'Post-incident review processes identify improvements.',
            ],
            3 => [
                'Automated response capabilities accelerate containment.',
                'Response procedures integrate with NIBSS and CBN incident coordination.',
            ],
            4 => [
                'SOAR platforms orchestrate complex multi-system incident response.',
            ],
            5 => [
                'Response capabilities contribute to national cyber incident coordination.',
            ],
        ]));

        // D5-F3: Escalation & Reporting
        // D5-F3-C1: Escalation & Reporting
        $statements = array_merge($statements, $this->buildComponent($d5, 'D5-F3', 'Escalation & Reporting', 'D5-F3-C1', 'Escalation & Reporting', [
            1 => [
                'Escalation procedures define when and how to escalate cybersecurity incidents.',
                'Management is notified promptly of significant cybersecurity events.',
                'Incidents are reported to the CBN and other regulators as required.',
                'Customer notification procedures exist for incidents affecting customer data.',
            ],
            2 => [
                'Escalation thresholds are defined based on incident severity and business impact.',
                'Board and executive reporting includes cybersecurity incident summaries.',
                'NDPA 2023 breach notification requirements are addressed in procedures.',
            ],
            3 => [
                'Automated escalation ensures timely notification of critical incidents.',
                'Incident reporting integrates with enterprise risk reporting.',
            ],
            4 => [
                'Real-time incident dashboards provide leadership with live situational awareness.',
            ],
            5 => [
                'Escalation and reporting practices contribute to regulatory reporting standards.',
            ],
        ]));

        return $statements;
    }

    private function buildComponent(array $domain, string $factorCode, string $factorName, string $componentCode, string $componentName, array $levels): array
    {
        $statements = [];
        foreach ($levels as $level => $texts) {
            foreach ($texts as $text) {
                $statements[] = array_merge($domain, [
                    'fc' => $factorCode,
                    'fn' => $factorName,
                    'cc' => $componentCode,
                    'cn' => $componentName,
                    'ml' => $level,
                    'text' => $text,
                ]);
            }
        }

        return $statements;
    }
}

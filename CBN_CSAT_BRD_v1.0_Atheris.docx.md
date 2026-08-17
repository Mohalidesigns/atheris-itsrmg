

| ATHERIS LIMITED *Governance, Risk & Compliance Platform* BUSINESS REQUIREMENTS DOCUMENT CBN Cybersecurity Self-Assessment Tool (CBN-CSAT) Automated IT Risk Intelligence Module *Aligned to CBN Risk-Based Cybersecurity Framework & FFIEC CAT v1.1* |
| :---: |

| Document Version | v1.0 — Initial Release |
| :---- | :---- |
| **Module Classification** | IT Risk → Cybersecurity Assessment Sub-Module |
| **Regulatory Mandate** | CBN Risk-Based Cybersecurity Framework (Section 3.9.3), BOFIA 2020 |
| **Target Audience** | CBN-Regulated DMBs, MFBs, Mortgage Banks, Payment Service Banks |
| **Prepared By** | Atheris Product Engineering Team |
| **Document Status** | DRAFT — For Internal Review |
| **Classification** | CONFIDENTIAL — Atheris Limited |

# **Table of Contents**

1\.  Executive Summary3

2\.  Business Context & Regulatory Mandate4

3\.  Problem Statement5

4\.  Module Overview & Scope6

5\.  Stakeholder Analysis7

6\.  Business Requirements — Inherent Risk Profile Engine8

7\.  Business Requirements — Cybersecurity Maturity Assessment Engine10

8\.  Business Requirements — Threats & Vulnerabilities Register13

9\.  Business Requirements — Institution Profile & Workflow14

10\.  Business Requirements — Reporting & Analytics Dashboard15

11\.  Business Requirements — AI Intelligence Layer16

12\.  Competitive Positioning17

13\.  CBN Regulatory Compliance Mapping18

14\.  Acceptance Criteria & KPIs19

15\.  Implementation Phasing & Timeline20

16\.  Constraints, Assumptions & Dependencies21

17\.  Glossary22

# **1\. Executive Summary**

This Business Requirements Document (BRD) defines the complete functional and business requirements for the CBN Cybersecurity Self-Assessment Tool (CBN-CSAT) Module — a fully automated, AI-powered cybersecurity assessment engine built natively within the Atheris Limited GRC Platform.

The CBN-CSAT module automates the Central Bank of Nigeria's mandatory annual cybersecurity self-assessment process, currently executed by all Deposit Money Banks (DMBs) and regulated financial institutions via the Excel-based FFIEC Cybersecurity Assessment Tool adapted for Nigerian regulatory requirements. Atheris transforms this 19-sheet manual workbook — encompassing 494 declarative maturity statements, 47 inherent risk scoring criteria, 5 cybersecurity domains, threat and vulnerability registers, and multi-level approval workflows — into a fully digital, workflow-driven, AI-augmented platform capability.

| 🎯  Strategic Imperative No Nigerian or African GRC platform currently offers a digital-native, automated CBN Cybersecurity Self-Assessment capability. Delivering this module places Atheris in an unassailable position with every CBN-regulated institution — an estimated 1,000+ obligated institutions submitting annually. |
| :---- |

The module delivers five transformational capabilities:

| \# | Capability | Business Value |
| :---- | :---- | :---- |
| 1 | **Inherent Risk Profiling Engine** | Eliminates manual 47-question scoring; real-time composite risk calculation with CBN risk tiers |
| 2 | **Cybersecurity Maturity Assessment Engine** | Automates 494-statement evaluation across 5 domains, 16 assessment factors, 30 components at 5 maturity levels |
| 3 | **Threats & Vulnerabilities Register** | Structured, workflow-driven threat and vulnerability capture with risk scoring and treatment tracking |
| 4 | **AI Regulatory Intelligence Layer** | GPT-powered gap analysis, remediation recommendations, and CBN submission readiness scoring |
| 5 | **Multi-Institution Benchmarking** | Aggregate maturity benchmarking across CBN peer groups — unique to the Nigerian market |

Upon delivery, the CBN-CSAT module will be the first and only SaaS-based, natively Nigerian, fully automated cybersecurity assessment platform in Africa — a category-defining product that directly addresses regulatory pain felt by every supervised institution.

# **2\. Business Context & Regulatory Mandate**

## **2.1  Regulatory Background**

The Central Bank of Nigeria (CBN), under Section 3.9.3 of the CBN Risk-Based Cybersecurity Framework and Guidelines (2021), mandates that all Deposit Money Banks (DMBs) and other regulated financial institutions conduct and submit a structured annual Cybersecurity Self-Assessment. This requirement is reinforced by BOFIA 2020 (Banks and Other Financial Institutions Act) which grants the CBN supervisory powers to mandate and enforce cybersecurity governance standards.

The assessment instrument is the FFIEC Cybersecurity Assessment Tool (CAT), adapted for the Nigerian regulatory environment. The CBN customisation — most recently updated and formally adopted in the 2021 version used by institutions such as Abbey Mortgage Bank Plc — introduces Nigerian-specific risk categories, CBN-regulated delivery channels (NIBSS, NIP, USSD, PoS), and locally relevant threat categories.

| ⚖️  Regulatory Citations CBN Risk-Based Cybersecurity Framework (Section 3.9.3) • BOFIA 2020 (Part IV — Supervisory Powers) • NDPA 2023 (Data Protection obligations in cybersecurity assessments) • CBN Guidelines on Electronic Banking • CBN Consumer Protection Framework • NFIU AML/CFT Directives (cyber incident integration) |
| :---- |

## **2.2  Current State — The Manual Process**

As of 2025, every CBN-supervised institution is required to complete the self-assessment using a complex, 19-sheet Excel workbook. The current process is characterised by:

| Pain Point | Detail |
| :---- | :---- |
| **Manual Data Entry** | 494 declarative statements each requiring Yes/No/N/A selection with comments across 5 maturity domains. Prone to human error and inconsistency. |
| **No Workflow Controls** | No approval workflow, version control, or audit trail. Sign-off is a physical signature on a printed page. |
| **No Benchmarking** | Institutions complete assessments in isolation with no peer comparison, industry benchmarks, or CBN aggregate scoring. |
| **Annual Cycle Only** | The Excel tool supports only annual point-in-time assessment. Continuous monitoring is not possible. |
| **No CBN Integration** | Submission is via email/print. There is no digital submission API, tracking, or CBN acknowledgement system. |
| **No Gap Analysis** | The tool calculates maturity scores but provides no prioritised remediation roadmap, gap analysis, or action tracking. |
| **Risk Fragmentation** | Assessment outputs are isolated from the institution's broader enterprise risk posture, issues log, and audit findings. |

## **2.3  Market Opportunity**

Nigeria has over 1,000 CBN-regulated financial institutions obligated to complete this assessment annually. This includes 34 licensed commercial banks, 900+ microfinance banks, 31 primary mortgage institutions, 28 payment service banks, and numerous licensed fintech operators under CBN supervision. Every single one is a direct prospect for the Atheris CBN-CSAT module.

Beyond Nigeria, the FFIEC CAT framework is referenced across anglophone Africa (Ghana, Kenya, East Africa) where similar cybersecurity assessment mandates are being introduced by central banks. The Atheris CBN-CSAT module, with its modular regulatory adapter architecture, positions the platform for pan-African expansion.

# **3\. Problem Statement**

The current CBN Cybersecurity Self-Assessment process is broken. It is manual, fragmented, error-prone, and completely disconnected from the enterprise risk management infrastructure that modern financial institutions have invested in. This creates a compounding set of problems for regulated institutions, their risk officers, and the CBN as the supervisory authority.

## **3.1  For Financial Institutions**

* CISOs and IT risk teams spend 40–80 hours annually on manual Excel completion, validation, and formatting

* Assessment quality degrades year-over-year due to staff turnover and lack of institutional memory within the tool

* Compensating controls (Yes \[CC\]) are noted in cells but never formally tracked or linked to a remediation lifecycle

* The 5-level maturity model (Sub-Baseline → Innovative) is opaque — institutions cannot generate prioritised improvement roadmaps

* There is no continuous assessment capability — cybersecurity posture is measured once per year despite threat landscapes changing weekly

* Threat and Vulnerability registers (Sheets 18–19) are empty in most submissions — no structured process exists to populate them

## **3.2  For the CBN as Regulator**

* Submission via email/print prevents the CBN from building an aggregate view of the national cybersecurity posture

* No standardised machine-readable format makes cross-institution benchmarking and trend analysis impossible

* The regulator cannot proactively identify systemically at-risk institutions without manual review of hundreds of Excel files

* Verification of assessment integrity — whether institutions have honestly self-reported — is not practically feasible at scale

## **3.3  The Opportunity for Atheris**

| 💡  Core Value Proposition Atheris eliminates the Excel. The CBN-CSAT module transforms a 19-sheet manual workbook into a living, breathing, AI-powered cybersecurity intelligence platform. What takes a CISO 80 hours in Excel now takes 4 hours in Atheris — with a richer output, a full audit trail, AI-generated remediation roadmap, and a CBN-ready submission package. |
| :---- |

The Atheris CBN-CSAT module solves every identified problem by delivering: structured digital workflows replacing manual Excel entry; AI-powered gap analysis and remediation planning; continuous monitoring dashboards replacing annual point-in-time assessments; peer benchmarking against CBN industry cohorts; and a one-click CBN submission export package compliant with reporting requirements.

# **4\. Module Overview & Scope**

## **4.1  Module Positioning in Atheris**

The CBN-CSAT module is a dedicated sub-module within the IT Risk domain of the Atheris GRC Platform. It operates as a first-class module with its own navigation, data model, and reporting suite while integrating deeply with the core Atheris Risk Register, Issues & Findings, Policy Management, and Third-Party Risk modules.

| Module Location | Atheris GRC → IT Risk → Cybersecurity Assessment |
| :---- | :---- |
| **Parent Module** | IT Risk Management |
| **Regulatory Framework** | CBN-adapted FFIEC Cybersecurity Assessment Tool v1.1 (May 2017 update) |
| **Assessment Standard** | NIST Cybersecurity Framework (CSF) aligned via FFIEC CAT mapping |
| **Assessment Frequency** | Annual mandatory \+ configurable continuous monitoring cycles |
| **Target Users** | CISO, IT Risk Manager, Head of Compliance, Board/Audit Committee, CBN Examiners |
| **Integration Points** | Risk Register, Issues & Findings, TPRM, Policy Management, Audit Management, Incident Management |

## **4.2  Scope of Automation — Sheet-by-Sheet Mapping**

The following table maps every sheet in the CBN-CSAT Excel workbook to its corresponding Atheris module capability:

| \# | Excel Sheet Name | Atheris Automated Capability | Atheris Module |
| :---- | :---- | :---- | :---- |
| 1 | Table of Contents | Auto-generated navigation dashboard with real-time completion status | Dashboard |
| 2 | Background | Static regulatory context panel — CBN/FFIEC documentation links with version tracking | Reference Library |
| 3 | Inherent Risk Results | Real-time auto-calculated composite risk scorecard with trend charts | Inherent Risk Engine |
| 4 | Title | Assessment record header — auto-populated from institution profile | Assessment Metadata |
| 5 | Instructions | Contextual in-app help, tooltips, and guided workflows per section | UX / Onboarding |
| 6 | Items to Submit | CBN Submission Checklist with automated completeness validation and export package generation | Submission Engine |
| 7 | Details of Institution | Institution profile form with stakeholder engagement matrix and CISO attribution | Institution Profile |
| 8 | Approval Page | Digital approval workflow with e-signature capture, role-based approval routing, and timestamp audit trail | Workflow Engine |
| 9 | Risk-Maturity Summary | Interactive Risk-Maturity matrix dashboard with domain heat maps and target maturity gap visualisation | Summary Dashboard |
| 10 | ref (hidden) | Platform reference data engine — maturity level lookups, scoring coefficients, declarative statement catalogue | Data Engine |
| 11 | Inherent Risk Profile Input | Interactive 47-question inherent risk assessment with guided criteria, real-time scoring, and per-category commentary | Inherent Risk Engine |
| 12 | Inherent Risks — Explanation | Structured narrative capture for each inherent risk category with validation and evidence attachment | Evidence Management |
| 13 | Maturity Tool Input | Guided 494-statement maturity questionnaire engine with Yes/No/N/A/Yes\[CC\] workflow, compensating control linkage, and comment threads | Maturity Engine |
| 14 | Maturity — Explanation | Supplementary narrative capture for 28 supplemental questions across all 5 domains with structured response forms | Narrative Engine |
| 15 | Maturity Results | Auto-calculated maturity scorecard: domain, assessment factor, and component-level maturity with population tables | Maturity Engine |
| 16 | Charts of Assessment Factor | Interactive maturity target-setting interface with gap visualisation and desired maturity pathway planning | Planning Engine |
| 17 | Charts of Component | Component-level maturity drill-down charts with current vs. target vs. CBN minimum overlay | Analytics Dashboard |
| 18 | Threats | Structured threat register with likelihood/impact matrix, mitigating control linkage, and residual risk tracking | Threat Register |
| 19 | Vulnerabilities | Structured vulnerability register with exploit likelihood, impact scoring, mitigant tracking, and remediation workflow | Vulnerability Register |

## **4.3  Explicitly Out of Scope (v1.0)**

* Direct API integration with CBN's regulatory portal (pending CBN API availability)

* Third-party penetration test result ingestion (planned for v1.1)

* Integration with SIEM/SOAR platforms (planned for v1.2 via webhooks)

* Non-CBN regulatory variants (e.g., Ghana BoG, Kenya CBK) — planned for pan-African phase

# **5\. Stakeholder Analysis**

The CBN-CSAT module serves a diverse set of stakeholders across the three tiers of use: institution-level users completing the assessment, institution leadership approving and submitting, and the CBN as the regulatory recipient.

| Stakeholder | Role in Process | Key Needs from Atheris CBN-CSAT | Priority |
| :---- | :---- | :---- | :---- |
| **CISO / IT Risk Manager** | Assessment Lead | Guided questionnaire, auto-scoring, gap analysis, AI recommendations | 🔴 Critical |
| **IT Security Team** | Evidence Contributor | Section-level task assignment, evidence upload, comment threads | 🔴 Critical |
| **Head of Compliance** | Regulatory Reviewer | Regulatory mapping view, submission readiness check, CBN deadline tracking | 🟠 High |
| **Chief Risk Officer** | Final Approver | Executive summary, risk appetite alignment, board reporting package | 🟠 High |
| **MD / CEO** | Signatory | One-click approval with digital signature, executive dashboard | 🟡 Medium |
| **Board Audit Committee** | Oversight | Board-level cybersecurity maturity report, trend analysis year-over-year | 🟡 Medium |
| **Internal Audit** | Independent Review | Audit trail, compensating control tracking, findings linkage | 🟡 Medium |
| **CBN Examiner** | Regulatory Consumer | Standardised submission package, attestation records, evidence archive | 🟠 High |
| **Atheris Platform Admin** | System Administrator | Assessment cycle management, institution onboarding, questionnaire versioning | 🟡 Medium |

# **6\. Business Requirements — Inherent Risk Profile Engine**

The Inherent Risk Profile Engine replicates and extends the functionality of Sheets 3, 11, and 12 of the CBN-CSAT Excel tool. It provides a fully digital, guided risk profiling workflow covering all 47 inherent risk criteria across 5 categories, with real-time scoring, visual outputs, and narrative capture.

## **6.1  Inherent Risk Categories**

The engine must support all 5 CBN-mandated inherent risk categories with their full question sets:

| Cat. | Category Name | Sub-Questions | Risk Scope |
| :---- | :---- | :---- | :---- |
| 1 | **Technologies and Connection Types** | 14 questions: ISP connections, FinTech connections, wireless, BYOD, third parties, EOL systems, cloud, network devices | Technical infrastructure complexity |
| 2 | **Delivery Channels** | 4 questions: Online presence, mobile app, PoS terminals, ATMs | Customer-facing attack surface |
| 3 | **Online/Mobile Products and Technology Services** | 14 questions: Debit/credit cards, prepaid cards, P2P payments, ACH, wire transfers, treasury services, merchant acquiring | Transaction risk exposure |
| 4 | **Organisational Characteristics** | 9 questions: M\&A activity, employee count, IT staff turnover, privileged access, IT environment changes, DR facility, tape backup, branch locations | Operational risk factors |
| 5 | **External Threats** | 6 questions: DoS incidents, blackmail/threats, ransomware, unplanned downtime, BEC attacks, phishing volume | Active threat exposure |

## **6.2  Functional Requirements**

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-IR-01 | **5-Level Risk Scale** | Each question must support the CBN 5-level scoring scale: Least (1), Minimal (2), Moderate (3), Significant (4), Most (5). Each level must display the full criteria text as per the CBN framework for that question. | 🔴 Must Have |
| BR-IR-02 | **Guided Criteria Display** | When a user selects a risk level, the system must display the full descriptive criteria text for that level inline (e.g., "Minimal complexity — 1–20 connections" for ISP connections at Level 2). | 🔴 Must Have |
| BR-IR-03 | **Auto-Scoring Engine** | The system must auto-calculate: (a) category risk score as sum of question scores; (b) category average score; (c) category risk level mapping (Least/Minimal/Moderate/Significant/Most); (d) composite inherent risk score across all 5 categories. | 🔴 Must Have |
| BR-IR-04 | **Comment Capture** | Each question must support a free-text comment field for capturing contextual evidence, tool names, and configuration notes. Comments must be searchable and exportable. | 🔴 Must Have |
| BR-IR-05 | **Narrative Explanation Forms** | Sheet 12 (Inherent Risks — Explanation) must be replicated as structured narrative forms per category, capturing ISP names, third-party provider names, DR site locations, certification scopes, and other regulatory-required context. | 🔴 Must Have |
| BR-IR-06 | **Completion Tracking** | The system must track completion percentage per category and display an "Incomplete" status if any question is unanswered — blocking CBN submission until all fields are complete. | 🔴 Must Have |
| BR-IR-07 | **Risk Level Visualisation** | Results must be visualised as a bar chart (matching Sheet 3\) showing inherent risk level per category with composite overall level highlighted. Chart must be exportable as PNG/PDF. | 🔴 Must Have |
| BR-IR-08 | **Year-on-Year Comparison** | The system must display prior year responses alongside current year for each question, highlighting changes in risk level with delta indicators. | 🟠 Should Have |
| BR-IR-09 | **CBN Peer Benchmarking** | Aggregate inherent risk scores must be benchmarked against CBN peer group cohorts (e.g., mortgage banks, microfinance banks, commercial banks) anonymously. | 🟡 Nice to Have |
| BR-IR-10 | **Evidence Attachments** | Users must be able to attach supporting documents (policies, vendor contracts, certifications) to individual risk questions as evidence files. | 🟠 Should Have |

## **6.3  CBN-Specific Risk Customisations**

The following inherent risk questions are CBN-specific additions not present in the base FFIEC CAT v1.1 and must be treated as first-class requirements:

* Disaster Recovery Facility — 5-level question covering bank-owned DR, vendor facility, and infrastructure management responsibility

* Location of Off-site Tape Backup — 5-level question covering bank-owned off-site facility, vendor-approved site, and complete absence of backup

* PoS and ATM Operation — Separate questions distinguishing bank-owned vs. bank-managed vs. third-party PoS and ATM operations

* External Threat Quantification — Six count-based threat questions (DoS, ransomware, BEC, phishing, etc.) using CBN-specified incident frequency bands

* Cybersecurity Stakeholder Engagement Matrix — Capturing MD/CEO, COO, CIO, CTO, CISO, and departmental heads with Yes/No/N/A/Yes-with-comment participation attestation

# **7\. Business Requirements — Cybersecurity Maturity Assessment Engine**

The Maturity Assessment Engine is the centrepiece of the CBN-CSAT module, replicating and transforming Sheets 13, 14, 15, 16, and 17 of the Excel tool. It manages 494 declarative statements across 5 domains, 16 assessment factors, and 30 components at 5 maturity levels.

## **7.1  Assessment Architecture**

The maturity assessment is structured as a hierarchical taxonomy: Domains → Assessment Factors → Components → Declarative Statements. Each statement is assigned to a specific maturity level (Baseline through Innovative) and accepts one of four responses: Yes, Yes \[Compensating Control\], No, or N/A.

| Dom. | Domain Name | Assessment Factors | Components | Statements |
| :---- | :---- | :---- | :---- | :---- |
| 1 | **Cyber Risk Management & Oversight** | Governance, Risk Management, Resources, Training & Culture | Oversight, Strategy/Policies, IT Asset Mgmt, Risk Mgmt Program, Risk Assessment, Audit, Staffing, Training, Culture | **141** |
| 2 | **Threat Intelligence & Collaboration** | Threat Intelligence, Monitoring & Analyzing, Information Sharing | Threat Intelligence & Information, Monitoring and Analyzing, Information Sharing | **45** |
| 3 | **Cybersecurity Controls** | Preventative Controls, Detective Controls, Corrective Controls | Infrastructure Mgmt, Access & Data Mgmt, Endpoint Security, Secure Coding, Threat Detection, Anomalous Activity, Event Detection, Patch Mgmt, Remediation | **174** |
| 4 | **External Dependency Management** | Connections, Relationship Management | Connections, Due Diligence, Contracts, Ongoing Monitoring | **51** |
| 5 | **Cyber Incident Management & Resilience** | Incident Resilience Planning, Detection/Response/Mitigation, Escalation & Reporting | Planning, Testing, Detection, Response & Mitigation, Escalation & Reporting | **83** |
| **TOTAL** | **5 Domains** | **16 Assessment Factors** | **30 Components** | **494** |

## **7.2  Maturity Levels & Scoring Logic**

The CBN-CSAT maturity model defines 6 levels. A component achieves a given maturity level only when ALL declarative statements at that level (and all lower levels) are answered "Yes" or "Yes \[CC\]". Partial achievement is tracked as a fractional score.

| Maturity Level | Score | CBN Definition | Numeric Code |
| :---- | :---- | :---- | :---- |
| **Sub-Baseline** | 0 | Operating below FFIEC cybersecurity baseline expectations | 1 |
| **Baseline** | 1 | Minimum expectations required by law and regulations. The institution has adopted practices sufficient to meet FFIEC baseline standards. | 2 |
| **Evolving** | 2 | Additional formality of documented procedures and controls. Management risk processes are becoming more formal. | 3 |
| **Intermediate** | 3 | Detailed, formal processes. Controls are regularly tested and reviewed. Cybersecurity is integrated into enterprise risk management. | 4 |
| **Advanced** | 4 | Cybersecurity practices and analytics that are forward-looking, adaptive, and optimised across the enterprise. | 5 |
| **Innovative** | 5 | Driving innovation in people, processes, and technology. The institution contributes to the financial sector's collective cyber defence. | 6 |

## **7.3  Functional Requirements**

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-MA-01 | **494-Statement Questionnaire Engine** | The system must present all 494 declarative statements, grouped by Domain → Assessment Factor → Component → Maturity Level. Navigation must support drill-down, collapse/expand, and filtered views by domain, response status, and maturity level. | 🔴 Must Have |
| BR-MA-02 | **4-Option Response Capture** | Each statement must accept exactly four response options: Yes, Yes \[CC\] (Compensating Control), No, and N/A. The system must enforce single-selection and display the current selection prominently. | 🔴 Must Have |
| BR-MA-03 | **Compensating Control Workflow** | When "Yes \[CC\]" is selected, the system must trigger a mandatory compensating control capture workflow — requiring the user to document the control name, description, effectiveness, and planned permanent control timeline. CC records must be linked to the Issues & Findings module for tracking. | 🔴 Must Have |
| BR-MA-04 | **Comment Threading** | Each statement must support multi-participant comment threads with timestamps and user attribution. Comments must be preserved across assessment cycles as institutional knowledge. | 🔴 Must Have |
| BR-MA-05 | **Auto-Scoring Calculation** | The system must calculate maturity scores using the CBN-defined cumulative logic: (a) component maturity \= lowest level at which all statements are answered Yes/Yes\[CC\]; (b) assessment factor maturity \= average across components; (c) domain maturity \= lowest assessment factor maturity within domain; (d) percentage completion per maturity level. | 🔴 Must Have |
| BR-MA-06 | **Real-Time Scoring Dashboard** | Scores must update in real-time as responses are captured, displaying current maturity level per domain, assessment factor, and component without requiring page refresh. | 🔴 Must Have |
| BR-MA-07 | **Supplementary Narrative Questions** | The 28 supplementary questions from Sheet 14 (Maturity — Explanation) must be presented as structured narrative forms per domain, with mandatory/optional field validation and character limits. | 🔴 Must Have |
| BR-MA-08 | **Target Maturity Planning** | Users must be able to set target maturity levels per domain (as in Sheet 16). The system must generate a gap analysis: statements answered "No" that fall below the target maturity level, prioritised by impact. | 🔴 Must Have |
| BR-MA-09 | **Maturity Heat Map** | The Risk-Maturity Summary matrix (Sheet 9\) must be replicated as an interactive heat map: rows \= maturity levels, columns \= inherent risk levels, highlighting the current cell and showing domain positions within the matrix. | 🔴 Must Have |
| BR-MA-10 | **Year-on-Year Tracking** | The system must store all assessment cycles and present prior-year responses alongside current responses, with delta indicators for changed answers and trend sparklines per component. | 🔴 Must Have |
| BR-MA-11 | **Assessment Delegation** | The CISO must be able to assign specific domains, assessment factors, or individual statements to named contributors with deadlines and completion tracking. | 🟠 Should Have |
| BR-MA-12 | **Bulk Response Import** | For large institutions with existing assessments, the system must support bulk import of responses from the CBN Excel template via structured file upload with validation. | 🟠 Should Have |
| BR-MA-13 | **Component Charts** | The component-level maturity charts (Sheet 17\) must be replicated as interactive bar charts with current maturity, target maturity, and CBN minimum overlay lines. Charts must be filterable by domain. | 🔴 Must Have |

## **7.4  Maturity Scoring Algorithm Requirements**

### **Population Tracking**

The system must track the "Total Population" of declarative statements per component per maturity level (replicating Table A of Sheet 15\) as the denominator for percentage calculations. This population count must be a configurable parameter tied to the CBN-CSAT questionnaire version.

### **Partial Maturity Scoring**

Where not all statements at a maturity level are answered Yes/Yes\[CC\], the system must calculate a fractional score: Score \= (Count of Yes \+ Count of Yes\[CC\]) / Total Population at that level. This fractional score is used in assessment factor and domain aggregation.

### **Domain Maturity Determination**

Domain maturity is determined by the lowest consecutive maturity level at which all components achieve a score of 1.0 (100%). If any component scores below 1.0 at Baseline, the domain maturity is Sub-Baseline. This logic must be implemented exactly per the CBN-CSAT specification.

# **8\. Business Requirements — Threats & Vulnerabilities Register**

Sheets 18 (Threats) and 19 (Vulnerabilities) are the most consistently incomplete sections in Nigerian CBN-CSAT submissions. The Atheris module transforms these empty Excel tables into a guided, structured register with risk scoring workflows and integration to the broader IT Risk module.

## **8.1  Threat Register Requirements**

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-TR-01 | **Threat Catalogue Integration** | The system must provide a pre-loaded CBN/FFIEC-aligned threat catalogue covering cybersecurity threats relevant to Nigerian financial institutions (ransomware, BEC, DDoS, phishing, insider threat, supply chain, APT, card fraud, USSD interception, etc.). Users can select from catalogue or add custom threats. | 🔴 Must Have |
| BR-TR-02 | **Threat Attributes Capture** | Each threat entry must capture: Threat name, Description, Threat Source (Internal/External/Natural), Threat Category (Technical/Human/Environmental), Likelihood (High/Moderate/Low), Impact (High/Moderate/Low), Inherent Risk Score, Mitigating Controls (linked to controls register), Residual Risk Score, and Comments. | 🔴 Must Have |
| BR-TR-03 | **Risk Matrix Visualisation** | Threats must be plotted on an interactive 3x3 likelihood-impact risk matrix with colour-coded heat zones (High/Moderate/Low). Matrix must be interactive — clicking a cell filters the threat list. | 🔴 Must Have |
| BR-TR-04 | **Mitigating Control Linkage** | Mitigating controls captured against threats must be linkable to existing controls in the Atheris Controls Register — enabling cross-module risk aggregation. | 🟠 Should Have |
| BR-TR-05 | **Threat-to-Statement Mapping** | The system should map threats to the maturity assessment statements they relate to — e.g., linking a "DDoS" threat to Domain 3, Cybersecurity Controls, Detective Controls statements. This provides context for gap prioritisation. | 🟡 Nice to Have |

## **8.2  Vulnerability Register Requirements**

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-VR-01 | **Vulnerability Attributes Capture** | Each vulnerability entry must capture: Vulnerability name, Description, Vulnerability Category (People/Process/Technology), Likelihood of Exploit (High/Moderate/Low), Impact if Exploited (High/Moderate/Low), Mitigants In Place (Yes/No), Existing Mitigants description, Planned Mitigants description, and Comments. | 🔴 Must Have |
| BR-VR-02 | **Vulnerability Source Integration** | The system should support ingestion of vulnerability data from VAPT reports, penetration test results, and CVE databases to pre-populate the register with actual identified vulnerabilities. | 🟠 Should Have |
| BR-VR-03 | **Remediation Workflow** | Each vulnerability must support a remediation lifecycle: Identified → Assigned → In Progress → Remediated → Verified. Assignments must link to staff profiles with due dates and escalation rules. | 🔴 Must Have |
| BR-VR-04 | **Vulnerability Scoring** | The system must calculate a composite vulnerability risk score (Likelihood × Impact) and track residual risk after mitigant application. Critical vulnerabilities (score ≥ 6 on a 9-point scale) must trigger automated notifications to the CISO. | 🔴 Must Have |

# **9\. Business Requirements — Institution Profile & Workflow Engine**

## **9.1  Institution Profile (Sheet 7\)**

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-IP-01 | **Institution Master Profile** | The system must capture and persist: Institution name, CBN licence type, head office address, assessment year, CISO name, CISO phone, CISO email, CISO grade, CISO reporting line, and parent bank details. This data must auto-populate across all assessment reports. | 🔴 Must Have |
| BR-IP-02 | **Cybersecurity Stakeholder Engagement Matrix** | The system must capture the engagement attestation matrix for 10 CBN-specified roles: MD/CEO, COO, CIO, CTO, Head of Electronic Channels, Head of HR, Head of Treasury, Head of Trade Services, Head of Compliance, and CISO. Each role must record Yes/No/Not Applicable/Yes-with-comment with comment capture. | 🔴 Must Have |
| BR-IP-03 | **Multi-Year Assessment History** | The system must maintain full assessment records for all historical years, accessible via a timeline view, with version control and change audit trail. | 🔴 Must Have |

## **9.2  Approval Workflow (Sheet 8\)**

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-AW-01 | **Digital Approval Routing** | The system must implement a configurable multi-stage approval workflow: (Stage 1\) Preparer submits → (Stage 2\) CISO reviews and approves → (Stage 3\) CRO/CIO/COO reviews → (Stage 4\) MD/CEO signs off. Each stage must capture approver name, digital signature acknowledgement, date, and comments. | 🔴 Must Have |
| BR-AW-02 | **CBN Reference Letter Generation** | The system must auto-generate the CBN cover letter (as per Sheet 8\) referencing Section 3.9.3 of the CBN Cybersecurity Framework, populated with institution details, assessment date, and authorised signatory information. Generated as PDF. | 🔴 Must Have |
| BR-AW-03 | **Submission Package Generation** | The system must generate a complete CBN submission package containing: (a) Assessment summary report; (b) Inherent risk results; (c) Maturity results; (d) Threat and vulnerability register; (e) Attestation page; (f) Supporting evidence index. All documents formatted per CBN requirements. | 🔴 Must Have |
| BR-AW-04 | **Items to Submit Checklist** | The system must maintain a dynamic checklist of CBN submission requirements (Sheet 6\) with automated completion validation — preventing submission if required items are missing. | 🔴 Must Have |
| BR-AW-05 | **CBN Deadline Management** | The system must track CBN annual submission deadlines, sending automated reminders at 60, 30, 14, and 7 days before deadline. Overdue submissions must trigger escalation alerts to MD/CEO level. | 🔴 Must Have |

# **10\. Business Requirements — Reporting & Analytics Dashboard**

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-RP-01 | **Executive Cybersecurity Dashboard** | A board/executive-level dashboard must present: composite inherent risk level, maturity level per domain, year-on-year maturity trend, key gaps vs. CBN minimum (Baseline), compensating controls count, open vulnerability count, and days to next CBN submission deadline. Printable as a PDF board pack. | 🔴 Must Have |
| BR-RP-02 | **Risk-Maturity Matrix Report** | The interactive Risk-Maturity Summary matrix (Sheet 9\) must be a live dashboard component showing institution position plotted against inherent risk (x-axis) and maturity level (y-axis) for each domain. Target maturity overlay must be configurable. | 🔴 Must Have |
| BR-RP-03 | **Domain Maturity Radar Chart** | A radar/spider chart showing current maturity across all 5 domains, with target maturity overlay and prior-year comparison. Must be exportable. | 🔴 Must Have |
| BR-RP-04 | **Gap Analysis Report** | A prioritised gap analysis report listing all declarative statements answered "No" below the target maturity level, organised by domain, with AI-generated remediation recommendations, effort estimates, and linkage to specific CBN framework sections. | 🔴 Must Have |
| BR-RP-05 | **Assessment Progress Tracker** | Real-time completion tracker showing percentage complete per section, outstanding items by assignee, and projected completion date vs. CBN deadline. | 🔴 Must Have |
| BR-RP-06 | **CBN Submission Report** | A formatted, CBN-ready PDF report matching the layout of the original Excel tool outputs — suitable for direct submission. Must include all required sections per CBN guidelines. | 🔴 Must Have |
| BR-RP-07 | **Peer Benchmarking Report** | Anonymous benchmarking comparing the institution's maturity and risk profile against CBN peer cohort averages, highlighting areas of above/below-average performance. | 🟠 Should Have |
| BR-RP-08 | **Trend Analysis Report** | Multi-year trend analysis showing maturity progression or regression across domains, inherent risk score changes, and comparison to CBN industry averages. | 🟠 Should Have |

# **11\. Business Requirements — AI Intelligence Layer**

The AI Intelligence Layer is the capability that decisively differentiates the Atheris CBN-CSAT module from any manual or legacy tool. Powered by Claude AI via the Laravel Prism orchestration layer, it provides contextual intelligence throughout the assessment lifecycle.

| BR\# | Requirement | Description | Priority |
| :---- | :---- | :---- | :---- |
| BR-AI-01 | **Contextual Statement Guidance** | For each of the 494 declarative statements, AI must provide contextual guidance on: (a) what evidence is typically required to answer Yes; (b) common Nigerian banking implementation examples; (c) relevant CBN/NDPA/NFIU framework references; (d) typical compensating controls when full compliance is not yet achieved. | 🔴 Must Have |
| BR-AI-02 | **Gap Analysis & Remediation Engine** | Upon assessment completion or at any point during completion, AI must generate a prioritised remediation roadmap for all "No" responses below the target maturity level. Each recommendation must include: action description, effort estimate, CBN compliance rationale, implementation priority, and linkage to relevant policy templates in the PPM module. | 🔴 Must Have |
| BR-AI-03 | **CBN Submission Readiness Scoring** | AI must analyse the completed assessment and generate a submission readiness score (0–100) with specific risk flags for: (a) inconsistencies between inherent risk level and maturity level; (b) unexplained compensating controls; (c) empty narrative sections; (d) threats/vulnerabilities register incompleteness. Score must map to a RAG (Red/Amber/Green) indicator. | 🔴 Must Have |
| BR-AI-04 | **Narrative Generation Assistance** | AI must assist in completing the 28 supplementary narrative questions (Sheet 14\) by drafting contextual responses based on the institution's captured profile, maturity responses, and prior year narratives — reducing CISO effort for repetitive narrative writing. | 🟠 Should Have |
| BR-AI-05 | **Threat Intelligence Enrichment** | AI must enrich the threat register by suggesting threats relevant to the institution's inherent risk profile (e.g., suggesting ransomware and BEC threats for institutions with Significant mobile/P2P exposure) and providing current threat intelligence context. | 🟠 Should Have |
| BR-AI-06 | **Policy Gap Identification** | AI must cross-reference maturity assessment gaps with the institution's existing policies (from the Atheris PPM module) and identify policies that are absent, outdated, or not aligned with CBN requirements — generating a policy gap report as input to the PPM module. | 🟠 Should Have |
| BR-AI-07 | **Year-on-Year Change Analysis** | AI must analyse changes between assessment cycles and generate a narrative change summary explaining: what improved, what declined, likely causes, and recommended areas of focus for the next cycle. | 🟡 Nice to Have |

# **12\. Competitive Positioning**

The following analysis benchmarks the Atheris CBN-CSAT module against global GRC platforms and establishes the unique market position Atheris occupies.

| Capability | Atheris | Archer IRM | MetricStream | ServiceNow GRC | OneTrust | SwissGRC | Excel (Current) |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| **Native CBN FFIEC CAT v1.1** | **✅ Full** | ⚠️ Custom | ⚠️ Custom | ❌ None | ❌ None | ❌ None | ⚠️ Manual |
| **CBN Regulatory Framework Alignment** | **✅ Built-in** | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None | ⚠️ Manual |
| **494-Statement Maturity Engine** | **✅ Auto** | ⚠️ Config | ⚠️ Config | ❌ None | ❌ None | ❌ None | ⚠️ Manual |
| **Nigerian Threat Catalogue** | **✅ Built-in** | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None |
| **AI Gap Analysis & Remediation** | **✅ AI-Powered** | ⚠️ Basic | ⚠️ Basic | ⚠️ Basic | ⚠️ Limited | ❌ None | ❌ None |
| **CBN Submission Package Export** | **✅ One-click** | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None | ⚠️ Manual |
| **Naira-Denominated Pricing** | **✅ Yes** | ❌ USD | ❌ USD | ❌ USD | ❌ USD | ❌ EUR/CHF | N/A |
| **AWS Lagos (af-south-1) Data Residency** | **✅ Yes** | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None | N/A |
| **CBN Peer Benchmarking** | **✅ Yes** | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None |
| **Multi-Year Trend Analysis** | **✅ Yes** | ✅ Yes | ✅ Yes | ✅ Yes | ⚠️ Limited | ⚠️ Limited | ❌ None |
| **Compensating Control Workflow** | **✅ Yes** | ✅ Yes | ✅ Yes | ✅ Yes | ⚠️ Limited | ⚠️ Limited | ❌ None |
| **Digital Approval Workflow** | **✅ Yes** | ✅ Yes | ✅ Yes | ✅ Yes | ⚠️ Limited | ⚠️ Limited | ❌ None |
| **Implementation Timeline (Weeks)** | **4–6** | 24–52+ | 20–48+ | 24–52+ | 12–24 | 16–32 | 0 (Excel) |

| 🏆  Atheris Unique Market Position Atheris is the ONLY GRC platform in the world that: (1) natively implements the CBN-adapted FFIEC CAT v1.1 as a first-class digital capability; (2) aligns natively to CBN, BOFIA, NDPA, NFIU, and all Nigerian financial sector regulations; (3) hosts data in AWS Lagos (af-south-1) with guaranteed Nigerian data residency; (4) prices in Naira eliminating FX risk; and (5) benchmarks institutions against CBN peer cohorts. This is an unassailable competitive moat in the Nigerian GRC market. |
| :---- |

# **13\. CBN Regulatory Compliance Mapping**

| Regulatory Reference | Requirement | Atheris CBN-CSAT Coverage |
| :---- | :---- | :---- |
| **CBN Cybersecurity Framework §3.9.3** | Annual mandatory Cybersecurity Self-Assessment submission by all DMBs | Full: automated assessment engine, approval workflow, CBN submission package |
| **BOFIA 2020 §55–58** | CBN supervisory powers over IT risk and cybersecurity governance | Full: audit trail, board-level reporting, approval records, evidence archive |
| **CBN Cyber Framework §2.1** | Governance and oversight requirements for cybersecurity risk | Full: Domain 1 maturity assessment covers all governance requirements |
| **NDPA 2023 §24–26** | Data protection obligations applicable to cybersecurity assessments | Full: Data residency in AWS Lagos, DPCO attribution capture, NDPA incident linkage |
| **NFIU AML/CFT §4.5** | Integration of cyber incidents into AML/CFT programme | Partial: Threat register captures cyber incidents; NFIU reporting linkage in v1.1 |
| **CBN ATM/PoS Guidelines** | Specific cybersecurity requirements for ATM and PoS operations | Full: Inherent risk categories include CBN-specific ATM/PoS questions |
| **CBN Electronic Banking Guidelines** | Online banking and mobile banking security requirements | Full: Delivery channels and online/mobile products inherent risk assessment |
| **PCI DSS v4.0** | Payment card data security requirements for card-issuing banks | Partial: PCI DSS certification scope captured in narrative section; full PCI assessment in Audit Management module |

# **14\. Acceptance Criteria & KPIs**

## **14.1  Functional Acceptance Criteria**

* All 494 declarative maturity statements are correctly loaded, categorised, and scorable with Yes/No/Yes\[CC\]/N/A responses

* All 47 inherent risk questions are correctly loaded with full 5-level criteria text per question

* Composite maturity scores match outputs of the Excel reference tool for a given set of responses (within 0.001 tolerance)

* CBN submission package exports as a complete, formatted PDF that matches the required CBN template structure

* Approval workflow correctly routes through all configured stages with appropriate notifications

* AI gap analysis generates contextually relevant recommendations for every "No" response below target maturity

* Year-on-year comparison correctly identifies changed responses and calculates delta scores

## **14.2  Performance KPIs**

| KPI | Target | Measurement Method |
| :---- | :---- | :---- |
| **Assessment Completion Time** | \< 4 hours (vs. 40–80 hours in Excel) | Tracked from first response to submission package export |
| **Score Accuracy** | 100% match vs. Excel reference for identical inputs | Automated regression test suite against Excel-calculated outputs |
| **CBN Submission Package Generation** | \< 60 seconds | Timed from "Generate" button click to PDF ready |
| **AI Recommendation Quality** | \> 80% rated relevant by CISO users | User feedback rating on AI-generated recommendations |
| **Assessment Completion Rate** | \> 95% of all sections complete before submission | Tracked via completion validation engine |
| **Platform Adoption (Y1)** | 50+ CBN-regulated institutions onboarded | Active assessment records in system |
| **Assessment Retention Rate** | \> 90% institutions returning for Year 2 cycle | Year 2 assessment initiation rate among Year 1 users |

# **15\. Implementation Phasing & Timeline**

| Phase | Sprint / Duration | Deliverables | Priority Features |
| :---- | :---- | :---- | :---- |
| **Phase 1** | Sprints 1–3(Weeks 1–6) | Institution profile module, CBN-CSAT data model, 47-question inherent risk engine, 5-category scoring, Sheet 12 narrative forms, basic risk results dashboard | BR-IR-01 to BR-IR-07, BR-IP-01 to BR-IP-03 |
| **Phase 2** | Sprints 4–7(Weeks 7–14) | 494-statement maturity questionnaire engine, 4-option response capture, compensating control workflow, auto-scoring engine, real-time scoring dashboard, maturity heat map | BR-MA-01 to BR-MA-09 |
| **Phase 3** | Sprints 8–10(Weeks 15–20) | Approval workflow engine, CBN submission package generator, attestation module, CBN cover letter generation, items-to-submit checklist, deadline management | BR-AW-01 to BR-AW-05 |
| **Phase 4** | Sprints 11–13(Weeks 21–26) | Threat register, vulnerability register, remediation workflow, AI gap analysis engine, AI contextual guidance, CBN submission readiness scoring, full reporting suite | BR-TR-01 to BR-VR-04, BR-AI-01 to BR-AI-03, BR-RP-01 to BR-RP-06 |
| **Phase 5** | Sprints 14–16(Weeks 27–32) | Year-on-year comparison, multi-year trend analysis, AI narrative assistance, peer benchmarking engine, bulk import, advanced analytics, performance optimisation, UAT, CBN pilot | BR-IR-08, BR-MA-10 to BR-MA-13, BR-AI-04 to BR-AI-07, BR-RP-07 to BR-RP-08 |

| 🚀  Go-to-Market Target Phase 1–3 (Weeks 1–20) targets production readiness for the CBN annual submission cycle. With CBN annual assessments typically due Q1, development commencing in Q3 delivers a complete tool ready for the next submission cycle — with a 6-month market window before competitors can respond. |
| :---- |

# **16\. Constraints, Assumptions & Dependencies**

## **16.1  Constraints**

* The CBN-CSAT questionnaire must maintain exact parity with the CBN-published FFIEC CAT v1.1 framework — no modification of declarative statements or scoring criteria without formal CBN framework update

* Data residency: all assessment data must reside exclusively in AWS af-south-1 (Cape Town) or af-south-1 (Lagos when available) — no data may transit or rest in non-African AWS regions

* The module must maintain backwards compatibility with the Excel workbook structure to support bulk import of existing institutional assessments

* All monetary references must be Naira-denominated; USD/EUR references in the source FFIEC framework must be converted to Naira equivalents using CBN-published conversion guidance

## **16.2  Assumptions**

* CBN does not currently provide a machine-readable API for cybersecurity assessment submission — the module generates a PDF/Excel export package for manual submission

* Participating institutions will provide accurate self-assessment responses — the module supports attestation and audit trail but does not independently verify responses

* The CBN framework versioning cycle is annual — the module will require a questionnaire version update process aligned to CBN framework updates

## **16.3  Dependencies**

* Laravel Prism AI orchestration layer must be operational for the AI Intelligence Layer features (BR-AI-01 to BR-AI-07)

* Atheris IT Risk module core infrastructure (risk register, issues management) must be deployed as prerequisite for full module integration

* Atheris Policy Management (PPM) module required for policy gap linkage feature (BR-AI-06)

* AWS Lambda/SES for notification delivery of assessment reminders and deadline alerts

# **17\. Glossary**

| Term | Definition |
| :---- | :---- |
| **CBN-CSAT** | Central Bank of Nigeria Cybersecurity Self-Assessment Tool — the Atheris-branded automated implementation of the CBN-adapted FFIEC CAT |
| **FFIEC CAT** | Federal Financial Institutions Examination Council Cybersecurity Assessment Tool — the US regulatory framework on which the CBN assessment is based |
| **DMB** | Deposit Money Bank — CBN licensed commercial bank |
| **CISO** | Chief Information Security Officer — primary responsible officer for cybersecurity at regulated institutions |
| **Declarative Statement** | One of 494 statements in the maturity assessment. Each states a cybersecurity control or practice; institutions respond Yes/No/N/A/Yes\[CC\] |
| **Compensating Control (CC)** | A control that partially mitigates a risk where the primary required control is not fully implemented. Must be documented and tracked in Atheris. |
| **Maturity Level** | One of six progressive levels: Sub-Baseline, Baseline, Evolving, Intermediate, Advanced, Innovative |
| **Inherent Risk** | The level of risk an institution faces based on its technology profile, delivery channels, products, and external threats — before applying cybersecurity controls |
| **Composite Risk Score** | Weighted average inherent risk score across all 5 categories, mapped to a single risk level (Least/Minimal/Moderate/Significant/Most) |
| **Domain** | One of five high-level cybersecurity areas: (1) Cyber Risk Management & Oversight; (2) Threat Intelligence & Collaboration; (3) Cybersecurity Controls; (4) External Dependency Management; (5) Cyber Incident Management & Resilience |
| **Assessment Factor** | Sub-grouping within a Domain — 16 total across all domains |
| **Component** | Sub-grouping within an Assessment Factor — 30 total across all assessment factors |
| **NDPA 2023** | Nigeria Data Protection Act 2023 — primary data protection legislation applicable to Nigerian financial institutions |
| **BOFIA 2020** | Banks and Other Financial Institutions Act 2020 — primary banking regulatory legislation granting CBN supervisory powers |
| **NFIU** | Nigerian Financial Intelligence Unit — responsible for AML/CFT intelligence and reporting requirements |
| **AWS af-south-1** | Amazon Web Services Africa (Cape Town) region — current data residency region for Atheris; Lagos region (af-south-2) to follow upon AWS availability |


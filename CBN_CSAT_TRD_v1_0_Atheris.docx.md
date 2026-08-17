  
**ATHERIS LIMITED**

Governance, Risk & Compliance Platform

**TECHNICAL REQUIREMENTS DOCUMENT**

**CBN Cybersecurity Self-Assessment Tool (CBN-CSAT)**

Automated IT Risk Intelligence Module

*IT & Security Risk Management Platform Integration*

*Aligned to: CBN Risk-Based Cybersecurity Framework (§3.9.3)  |  FFIEC CAT v1.1  |  BOFIA 2020  |  NDPA 2023*

**DOCUMENT CONTROL**

| Document Title | CBN-CSAT Technical Requirements Document |
| :---- | :---- |
| **Document Reference** | ATH-TRD-CBNCSAT-001 |
| **Version** | v1.0 — Initial Release |
| **Based on BRD** | ATH-BRD-CBNCSAT-001 v1.0 |
| **Module Classification** | IT Risk → Cybersecurity Assessment Sub-Module |
| **Regulatory Mandate** | CBN Risk-Based Cybersecurity Framework (§3.9.3), BOFIA 2020, NDPA 2023 |
| **Parent Platform** | Atheris IT & Security Risk Management Platform |
| **Tech Stack** | Laravel 11 / PHP 8.3  |  React 18 JSX  |  Tailwind CSS  |  MySQL 8.0+  |  Redis |
| **AI Orchestration** | Laravel Prism  |  Model: claude-sonnet-4-6 |
| **Data Residency** | AWS af-south-1 (Cape Town) — all assessment data |
| **Prepared By** | Atheris Product Engineering Team |
| **Review Authority** | Chief Technology Officer, Atheris Limited |
| **Document Status** | DRAFT — For Internal Review |
| **Classification** | CONFIDENTIAL — Atheris Limited |

# **Table of Contents**

# **1\. Introduction & Purpose**

This Technical Requirements Document (TRD) defines the complete technical architecture, data models, API specifications, component design, integration patterns, security requirements, and implementation standards for the CBN Cybersecurity Self-Assessment Tool (CBN-CSAT) module — a sub-module of the Atheris IT & Security Risk Management Platform.

This TRD translates the business requirements defined in ATH-BRD-CBNCSAT-001 (v1.0) into precise engineering specifications that guide the development team from database schema design through AI integration, API construction, and frontend component architecture to production deployment.

## **1.1 Scope**

* Complete database schema — all tables, columns, indexes, and relationships

* Laravel 11 backend architecture — modules, services, repositories, jobs, and controllers

* RESTful API specification — all endpoints with request/response contracts and error codes

* React 18 frontend component hierarchy and state management strategy

* AI Intelligence Layer — Laravel Prism integration, prompt specifications, and async job architecture

* Cross-module integration contracts with Risk Register, Issues & Findings, and Policy Management

* Security, performance, and infrastructure requirements

* Testing strategy and acceptance criteria mapped to technical implementation

## **1.2 Module Positioning**

The CBN-CSAT module is a first-class sub-module under IT Risk Management in the Atheris GRC Platform. It introduces 20 new database tables, 8 frontend pages, 40+ API endpoints, and 6 AI agent capabilities.

| MODULE PATH: Atheris GRC Platform  →  IT Risk Management  →  Cybersecurity Assessment (CBN-CSAT)ROUTE PREFIX (API): /api/v1/csat          FRONTEND PATH: /risk/cybersecurity-assessmentNAMESPACE (PHP): App\\Modules\\CBNCSAT      DB PREFIX: csat\_ |
| :---- |

# **2\. System Architecture Overview**

## **2.1 Technology Stack**

| Layer | Technology | Version | Purpose |
| ----- | ----- | ----- | ----- |
| Backend Framework | Laravel | 11.x / PHP 8.3 | API server, business logic, queue management |
| Frontend Framework | React | 18.x (JSX) | SPA — all CSAT pages |
| Styling | Tailwind CSS | 3.x \+ AEGIS tokens | Utility CSS with Atheris design system |
| Database | MySQL | 8.0+ | Primary relational store for all CSAT data |
| Cache / Queue Broker | Redis | 7.x | Job queue (Horizon), response cache, WebSocket |
| Job Queue | Laravel Horizon | 5.x | Async scoring, PDF export, AI job processing |
| AI Orchestration | Laravel Prism | Latest stable | LLM prompt management, multi-model routing |
| Primary AI Model | Claude claude-sonnet-4-6 | claude-sonnet-4-6 | Gap analysis, narrative generation, remediation planning |
| PDF Generation | Browsershot \+ Puppeteer | 3.x | CBN submission package PDF compilation |
| File Storage | AWS S3 | SDK v3 / af-south-1 | Evidence attachments, export archives |
| Email | AWS SES | SDK v3 | Assessment reminders, deadline escalation alerts |
| Authentication | Laravel Sanctum | 3.x | Bearer token auth, SPA session management |
| WebSocket | Laravel Echo \+ Pusher | — | Real-time score updates to frontend |

## **2.2 Module Directory Structure**

| app/Modules/CBNCSAT/├── Http/│   ├── Controllers/│   │   ├── AssessmentController.php│   │   ├── InherentRiskController.php│   │   ├── MaturityController.php│   │   ├── ThreatRegisterController.php│   │   ├── VulnerabilityRegisterController.php│   │   ├── WorkflowController.php│   │   ├── ReportingController.php│   │   └── AIController.php│   ├── Requests/         (Form Request validation classes per endpoint)│   └── Resources/        (API Resource & Collection transformers)├── Models/               (20 Eloquent models — one per csat\_ table)├── Services/│   ├── InherentRiskScoringService.php│   ├── MaturityScoringService.php│   ├── SubmissionPackageService.php│   ├── WorkflowService.php│   └── BenchmarkingService.php├── AI/│   ├── GapAnalysisAgent.php│   ├── ReadinessScoreAgent.php│   ├── NarrativeAssistantAgent.php│   ├── ThreatEnrichmentAgent.php│   └── PolicyGapAgent.php├── Jobs/│   ├── RecalculateMaturityScoresJob.php│   ├── RecalculateInherentRiskJob.php│   ├── GenerateGapAnalysisJob.php│   ├── GenerateReadinessScoreJob.php│   ├── GenerateSubmissionPackageJob.php│   └── DraftNarrativeJob.php├── Events/ & Listeners/  (cross-module event dispatch)├── Integrations/│   ├── RiskRegisterIntegrationService.php│   ├── IssuesFindingsIntegrationService.php│   └── PolicyManagementIntegrationService.php├── Repositories/         (query encapsulation layer)├── routes/api.php└── Providers/CsatServiceProvider.php |
| :---- |

## **2.3 Module Architecture Layers**

| Layer | PHP Component | Responsibility |
| ----- | ----- | ----- |
| Presentation | API Controllers (Http/Controllers/) | HTTP request/response handling, input validation, response envelope formatting |
| Application | Services (Services/) | Business logic orchestration, scoring calculations, workflow management |
| AI | Agents (AI/) | LLM prompt construction, Prism calls, response parsing, recommendation persistence |
| Domain | Models \+ Repositories (Models/, Repositories/) | Eloquent models, global scopes, query encapsulation, domain rules |
| Infrastructure | Jobs, Events, Notifications | Async processing, event dispatch/consumption, email delivery |
| Integration | Integration Services (Integrations/) | Typed contracts to Risk Register, Issues & Findings, PPM modules |

# **3\. Database Schema Design**

All CBN-CSAT tables are prefixed csat\_ to ensure clear namespace separation from core Atheris tables. The schema uses a multi-tenant architecture where every table containing assessment data carries institution\_id as a foreign key, enforced by the global InstitutionScope Eloquent model scope.

| DATA RESIDENCY CONSTRAINT: All csat\_ tables must be deployed exclusively on MySQL RDS instances in AWS af-south-1 (Cape Town). No query, backup, or replication may route assessment data to non-African regions. This is a hard requirement under NDPA 2023\. |
| :---- |

## **3.1 Core Assessment Tables**

### **3.1.1  csat\_assessments — Assessment Lifecycle Records**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK AUTO\_INCREMENT | Primary key |
| institution\_id | BIGINT UNSIGNED | FK → institutions.id NOT NULL | Parent institution — multi-tenant partition key |
| assessment\_year | SMALLINT UNSIGNED | NOT NULL | CBN submission cycle year (e.g. 2025\) |
| status | ENUM | NOT NULL DEFAULT 'draft' | draft | in\_progress | pending\_approval | approved | submitted |
| submission\_deadline | DATE | NULLABLE | CBN annual submission deadline for this cycle |
| submitted\_at | TIMESTAMP | NULLABLE | Actual CBN submission timestamp |
| framework\_version | VARCHAR(20) | NOT NULL DEFAULT 'FFIEC-CAT-1.1' | CBN-CSAT questionnaire version — ties to seeded catalogue |
| composite\_risk\_level | ENUM | NULLABLE | least | minimal | moderate | significant | most |
| composite\_risk\_score | DECIMAL(5,3) | NULLABLE | Weighted average inherent risk score (1.000–5.000) |
| overall\_maturity\_level | ENUM | NULLABLE | sub\_baseline | baseline | evolving | intermediate | advanced | innovative |
| ai\_readiness\_score | TINYINT UNSIGNED | NULLABLE CHECK(0-100) | AI submission readiness score 0–100 |
| ai\_readiness\_rag | ENUM | NULLABLE | red | amber | green — mapped from readiness score |
| prior\_assessment\_id | BIGINT UNSIGNED | FK → csat\_assessments.id NULLABLE | Prior year assessment — enables YoY delta |
| created\_by | BIGINT UNSIGNED | FK → users.id NOT NULL | CISO who initiated assessment |
| created\_at | TIMESTAMP | NOT NULL |  |
| updated\_at | TIMESTAMP | NOT NULL |  |
| deleted\_at | TIMESTAMP | NULLABLE | Soft delete — 7-year retention for CBN examination |

### **3.1.2  csat\_institution\_profiles — Institution Details (Excel Sheet 7\)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK AUTO\_INCREMENT |  |
| assessment\_id | BIGINT UNSIGNED | FK UNIQUE NOT NULL | One profile per assessment cycle |
| institution\_name | VARCHAR(255) | NOT NULL | Full registered institution name |
| cbn\_licence\_type | ENUM | NOT NULL | dmb | mfb | mortgage\_bank | psb | merchant\_bank | development\_finance |
| head\_office\_address | TEXT | NOT NULL | Registered head office address |
| ciso\_name | VARCHAR(255) | NOT NULL |  |
| ciso\_email | VARCHAR(255) | NOT NULL |  |
| ciso\_phone | VARCHAR(20) | NOT NULL |  |
| ciso\_grade | VARCHAR(100) | NULLABLE | Job grade or title |
| ciso\_reporting\_line | VARCHAR(255) | NULLABLE | e.g. Chief Risk Officer |
| parent\_bank\_name | VARCHAR(255) | NULLABLE | Holding company / parent bank (subsidiary institutions) |
| created\_at | TIMESTAMP | NOT NULL |  |
| updated\_at | TIMESTAMP | NOT NULL |  |

### **3.1.3  csat\_stakeholder\_engagement — Stakeholder Engagement Matrix (Sheet 7\)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| role\_key | VARCHAR(100) | NOT NULL | md\_ceo | coo | cio | cto | head\_channels | head\_hr | head\_treasury | head\_trade | head\_compliance | ciso |
| role\_label | VARCHAR(255) | NOT NULL | Human-readable role name |
| engagement\_status | ENUM | NOT NULL | yes | no | na | yes\_with\_comment |
| comment | TEXT | NULLABLE | Required when status \= yes\_with\_comment or no |
| name\_of\_person | VARCHAR(255) | NULLABLE | Name of person occupying role |
| UNIQUE |  | (assessment\_id, role\_key) | One engagement record per role per assessment |

## **3.2 Inherent Risk Engine Tables**

### **3.2.1  csat\_ir\_questions — Inherent Risk Question Catalogue (Reference / Seeded)**

Read-only reference table. Seeded from CBN-CSAT FFIEC CAT v1.1 — 47 questions across 5 categories. Never modified by application logic.

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| category\_code | TINYINT UNSIGNED | NOT NULL CHECK(1-5) | 1=Technologies, 2=Channels, 3=Products/Services, 4=Org Characteristics, 5=External Threats |
| category\_name | VARCHAR(255) | NOT NULL | Category display name |
| question\_number | TINYINT UNSIGNED | NOT NULL | Sequence within category |
| question\_text | TEXT | NOT NULL | Question displayed to user |
| level\_1\_criteria | TEXT | NOT NULL | Full criteria text for Least (1) |
| level\_2\_criteria | TEXT | NOT NULL | Full criteria text for Minimal (2) |
| level\_3\_criteria | TEXT | NOT NULL | Full criteria text for Moderate (3) |
| level\_4\_criteria | TEXT | NOT NULL | Full criteria text for Significant (4) |
| level\_5\_criteria | TEXT | NOT NULL | Full criteria text for Most (5) |
| cbn\_specific | BOOLEAN | NOT NULL DEFAULT false | True for CBN-added questions absent from base FFIEC CAT (e.g. DR facility, PoS/ATM, tape backup) |
| framework\_version | VARCHAR(20) | NOT NULL DEFAULT 'FFIEC-CAT-1.1' | Ties question to questionnaire version |
| is\_active | BOOLEAN | NOT NULL DEFAULT true | Soft-disable for framework updates |
| UNIQUE |  | (category\_code, question\_number, framework\_version) |  |

### **3.2.2  csat\_ir\_responses — Inherent Risk Assessment Answers**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| question\_id | BIGINT UNSIGNED | FK → csat\_ir\_questions.id NOT NULL |  |
| selected\_level | TINYINT UNSIGNED | NOT NULL CHECK(1-5) | User-selected CBN 5-level risk score |
| comment | TEXT | NULLABLE | Contextual evidence note — searchable |
| completed\_by | BIGINT UNSIGNED | FK → users.id NULLABLE |  |
| completed\_at | TIMESTAMP | NULLABLE |  |
| updated\_at | TIMESTAMP | NOT NULL |  |
| UNIQUE |  | (assessment\_id, question\_id) | One response per question per assessment cycle |

### **3.2.3  csat\_ir\_category\_scores — Computed Inherent Risk Scores (Scoring Cache)**

Populated and refreshed by InherentRiskScoringService on every response save via RecalculateInherentRiskJob. Acts as the live scoring cache for real-time dashboard reads.

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| category\_code | TINYINT UNSIGNED | NOT NULL CHECK(1-5) |  |
| total\_score | DECIMAL(6,2) | NOT NULL DEFAULT 0 | Sum of selected\_level values for all answered questions in category |
| question\_count | TINYINT UNSIGNED | NOT NULL | Total questions in category (denominator) |
| answered\_count | TINYINT UNSIGNED | NOT NULL DEFAULT 0 | Questions with a saved response |
| average\_score | DECIMAL(5,3) | NOT NULL DEFAULT 0.000 | total\_score / answered\_count |
| risk\_level | ENUM | NOT NULL DEFAULT 'least' | least | minimal | moderate | significant | most — mapped from average\_score |
| completion\_pct | DECIMAL(5,2) | NOT NULL DEFAULT 0.00 | (answered\_count / question\_count) × 100 |
| calculated\_at | TIMESTAMP | NOT NULL | Last score recalculation timestamp |
| UNIQUE |  | (assessment\_id, category\_code) |  |

### **3.2.4  csat\_ir\_narratives — Inherent Risk Explanations (Sheet 12\)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| category\_code | TINYINT UNSIGNED | NOT NULL |  |
| narrative\_key | VARCHAR(100) | NOT NULL | Identifies the narrative field: e.g. isp\_provider\_names, third\_party\_names, dr\_location, offsite\_tape\_location, dr\_ownership |
| narrative\_label | VARCHAR(255) | NOT NULL | Display label for the field |
| narrative\_value | TEXT | NULLABLE | Free-text capture |
| UNIQUE |  | (assessment\_id, category\_code, narrative\_key) |  |

## **3.3 Maturity Assessment Engine Tables**

### **3.3.1  csat\_ma\_statements — 494-Statement Declarative Catalogue (Reference / Seeded)**

Master questionnaire table. Seeded with all 494 statements from CBN-CSAT FFIEC CAT v1.1. Read-only in production. Questionnaire updates require a new framework\_version and versioned re-seed.

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| domain\_code | TINYINT UNSIGNED | NOT NULL CHECK(1-5) | Domain 1–5 |
| domain\_name | VARCHAR(255) | NOT NULL | e.g. Cyber Risk Management & Oversight |
| factor\_code | VARCHAR(12) | NOT NULL | e.g. D1-F1 (Domain 1, Factor 1\) |
| factor\_name | VARCHAR(255) | NOT NULL | Assessment Factor label |
| component\_code | VARCHAR(15) | NOT NULL | e.g. D1-F1-C2 |
| component\_name | VARCHAR(255) | NOT NULL | Component label |
| maturity\_level | TINYINT UNSIGNED | NOT NULL CHECK(1-5) | 1=Baseline 2=Evolving 3=Intermediate 4=Advanced 5=Innovative |
| sequence | SMALLINT UNSIGNED | NOT NULL | Display order within component+level grouping |
| statement\_text | TEXT | NOT NULL | Full declarative statement text as per CBN-CSAT specification |
| population\_count | SMALLINT UNSIGNED | NOT NULL DEFAULT 1 | Count used as scoring denominator — typically 1, may differ in grouped statements |
| framework\_version | VARCHAR(20) | NOT NULL DEFAULT 'FFIEC-CAT-1.1' |  |
| is\_active | BOOLEAN | NOT NULL DEFAULT true | Soft-disable for superseded statements |

### **3.3.2  csat\_ma\_responses — Maturity Statement Responses (494 per assessment)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| statement\_id | BIGINT UNSIGNED | FK → csat\_ma\_statements.id NOT NULL |  |
| response | ENUM | NULLABLE | yes | yes\_cc | no | na — NULL \= not yet answered |
| has\_compensating\_control | BOOLEAN | NOT NULL DEFAULT false | True when response \= yes\_cc |
| comment | TEXT | NULLABLE | Per-statement comment (retained across assessment cycles) |
| responded\_by | BIGINT UNSIGNED | FK → users.id NULLABLE |  |
| responded\_at | TIMESTAMP | NULLABLE |  |
| updated\_at | TIMESTAMP | NOT NULL |  |
| UNIQUE |  | (assessment\_id, statement\_id) | One response per statement per assessment |

### **3.3.3  csat\_ma\_compensating\_controls — Compensating Control Documentation**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| response\_id | BIGINT UNSIGNED | FK → csat\_ma\_responses.id UNIQUE NOT NULL | One CC record per yes\_cc response |
| control\_name | VARCHAR(500) | NOT NULL | Name / title of the compensating control |
| control\_description | TEXT | NOT NULL | Description of how the control compensates for the missing primary control |
| effectiveness\_level | ENUM | NOT NULL | high | medium | low |
| planned\_permanent\_date | DATE | NULLABLE | Target date for implementing the permanent control |
| issues\_finding\_id | BIGINT UNSIGNED | FK → issues\_findings.id NULLABLE | Link to Atheris Issues & Findings module tracking item |
| created\_by | BIGINT UNSIGNED | FK → users.id NOT NULL |  |
| created\_at | TIMESTAMP | NOT NULL |  |
| updated\_at | TIMESTAMP | NOT NULL |  |

### **3.3.4  csat\_ma\_scores — Computed Maturity Scores (Multi-Level Scoring Cache)**

Populated by MaturityScoringService via RecalculateMaturityScoresJob on every response save. Stores component, factor, and domain scores for all scope levels. Serves as the read-optimised cache for dashboard and reporting queries.

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| score\_type | ENUM | NOT NULL | component | factor | domain |
| scope\_code | VARCHAR(20) | NOT NULL | e.g. D1-F1-C1 (component), D1-F1 (factor), D1 (domain) |
| scope\_name | VARCHAR(255) | NOT NULL | Human-readable display label |
| baseline\_score | DECIMAL(6,4) | NOT NULL DEFAULT 0.0000 | Fraction (0.0–1.0) of Baseline statements answered Yes/Yes\_CC |
| evolving\_score | DECIMAL(6,4) | NOT NULL DEFAULT 0.0000 |  |
| intermediate\_score | DECIMAL(6,4) | NOT NULL DEFAULT 0.0000 |  |
| advanced\_score | DECIMAL(6,4) | NOT NULL DEFAULT 0.0000 |  |
| innovative\_score | DECIMAL(6,4) | NOT NULL DEFAULT 0.0000 |  |
| achieved\_maturity\_level | TINYINT UNSIGNED | NOT NULL DEFAULT 0 | 0=Sub-Baseline, 1=Baseline, 2=Evolving, 3=Intermediate, 4=Advanced, 5=Innovative |
| target\_maturity\_level | TINYINT UNSIGNED | NULLABLE | User-configured target level (domain/component only) |
| completion\_pct | DECIMAL(5,2) | NOT NULL DEFAULT 0.00 | % of statements in scope with a non-null response |
| calculated\_at | TIMESTAMP | NOT NULL |  |
| UNIQUE |  | (assessment\_id, score\_type, scope\_code) |  |

### **3.3.5  csat\_ma\_narratives — Supplementary Narrative Questions (Sheet 14 — 28 questions)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| domain\_code | TINYINT UNSIGNED | NOT NULL CHECK(1-5) |  |
| question\_number | TINYINT UNSIGNED | NOT NULL | Global sequence 1–28 across all domains |
| question\_text | TEXT | NOT NULL | Supplementary narrative question (seeded from Sheet 14\) |
| response\_text | TEXT | NULLABLE | Institution-authored response |
| ai\_draft\_text | TEXT | NULLABLE | AI-generated draft assistance (if BR-AI-04 used) |
| is\_mandatory | BOOLEAN | NOT NULL DEFAULT true | Mandatory fields block CBN submission export |
| min\_characters | SMALLINT UNSIGNED | NOT NULL DEFAULT 100 | Minimum response length for validation |
| responded\_by | BIGINT UNSIGNED | FK → users.id NULLABLE |  |
| updated\_at | TIMESTAMP | NOT NULL |  |

## **3.4 Threats & Vulnerabilities Tables**

### **3.4.1  csat\_threats — Threat Register (Sheet 18\)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| threat\_name | VARCHAR(500) | NOT NULL |  |
| catalogue\_threat\_id | BIGINT UNSIGNED | FK → csat\_threat\_catalogue.id NULLABLE | Link to pre-loaded catalogue — NULL for custom threats |
| description | TEXT | NULLABLE |  |
| threat\_source | ENUM | NOT NULL | internal | external | natural |
| threat\_category | ENUM | NOT NULL | technical | human | environmental |
| likelihood | ENUM | NOT NULL | high | moderate | low |
| impact | ENUM | NOT NULL | high | moderate | low |
| inherent\_risk\_score | TINYINT UNSIGNED | GENERATED STORED NOT NULL | Likelihood × Impact on 3×3 matrix: high=3, moderate=2, low=1. Range: 1–9 |
| mitigating\_controls\_desc | TEXT | NULLABLE | Free-text description of mitigating controls |
| controls\_register\_id | BIGINT UNSIGNED | FK → controls.id NULLABLE | Link to Atheris Controls Register entry |
| residual\_risk\_score | TINYINT UNSIGNED | NULLABLE | Post-mitigant score (1–9) |
| created\_by | BIGINT UNSIGNED | FK → users.id NOT NULL |  |
| created\_at | TIMESTAMP | NOT NULL |  |
| updated\_at | TIMESTAMP | NOT NULL |  |
| INDEX |  | (assessment\_id, inherent\_risk\_score DESC) | Risk matrix ordering optimisation |

### **3.4.2  csat\_threat\_catalogue — Nigerian Banking Threat Catalogue (Seeded / Read-only)**

Pre-loaded with \~40 CBN/FFIEC-aligned threats relevant to Nigerian financial institutions. Includes ransomware, BEC, DDoS, phishing, USSD interception, PoS skimming, insider threat, supply chain attack, and others.

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| threat\_name | VARCHAR(500) | NOT NULL |  |
| threat\_source | ENUM | NOT NULL |  |
| threat\_category | ENUM | NOT NULL |  |
| typical\_likelihood | ENUM | NOT NULL | Suggested default for Nigerian banking context |
| typical\_impact | ENUM | NOT NULL |  |
| description | TEXT | NOT NULL |  |
| cbn\_relevance\_note | TEXT | NULLABLE | CBN-specific context note (e.g. NIBSS exposure, USSD ecosystem) |
| is\_active | BOOLEAN | NOT NULL DEFAULT true |  |

### **3.4.3  csat\_vulnerabilities — Vulnerability Register (Sheet 19\)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| vulnerability\_name | VARCHAR(500) | NOT NULL |  |
| description | TEXT | NULLABLE |  |
| vulnerability\_category | ENUM | NOT NULL | people | process | technology |
| likelihood\_of\_exploit | ENUM | NOT NULL | high | moderate | low |
| impact\_if\_exploited | ENUM | NOT NULL | high | moderate | low |
| composite\_score | TINYINT UNSIGNED | GENERATED STORED NOT NULL | Likelihood × Impact (1–9) — scores ≥ 7 trigger CISO alert |
| mitigants\_in\_place | BOOLEAN | NOT NULL DEFAULT false |  |
| existing\_mitigants | TEXT | NULLABLE |  |
| planned\_mitigants | TEXT | NULLABLE |  |
| remediation\_status | ENUM | NOT NULL DEFAULT 'identified' | identified | assigned | in\_progress | remediated | verified |
| assigned\_to | BIGINT UNSIGNED | FK → users.id NULLABLE |  |
| due\_date | DATE | NULLABLE |  |
| created\_by | BIGINT UNSIGNED | FK → users.id NOT NULL |  |
| created\_at | TIMESTAMP | NOT NULL |  |
| updated\_at | TIMESTAMP | NOT NULL |  |

## **3.5 Approval Workflow & AI Intelligence Tables**

### **3.5.1  csat\_approval\_stages — Multi-Stage Approval Configuration**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| institution\_id | BIGINT UNSIGNED | FK NOT NULL | Per-institution workflow configuration |
| stage\_number | TINYINT UNSIGNED | NOT NULL CHECK(1-4) | 1=Preparer submits, 2=CISO approves, 3=CRO/CIO review, 4=MD/CEO signs off |
| stage\_name | VARCHAR(255) | NOT NULL | e.g. CISO Review & Approval |
| role\_required | VARCHAR(100) | NOT NULL | Atheris RBAC role that may approve this stage: csat\_ciso | csat\_approver | csat\_signatory |
| is\_mandatory | BOOLEAN | NOT NULL DEFAULT true | Non-mandatory stages may be bypassed by admin |
| UNIQUE |  | (institution\_id, stage\_number) |  |

### **3.5.2  csat\_approval\_records — Approval Audit Trail (Immutable)**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| stage\_number | TINYINT UNSIGNED | NOT NULL |  |
| action | ENUM | NOT NULL | approved | rejected | returned\_for\_revision |
| approver\_id | BIGINT UNSIGNED | FK → users.id NOT NULL |  |
| approver\_name | VARCHAR(255) | NOT NULL | Captured at action time — immutable, non-repudiation |
| approver\_role | VARCHAR(100) | NOT NULL | Captured at action time — immutable |
| digital\_signature\_token | VARCHAR(500) | NULLABLE | HMAC hash of (user\_id \+ assessment\_id \+ stage \+ timestamp) for non-repudiation |
| comments | TEXT | NULLABLE |  |
| actioned\_at | TIMESTAMP | NOT NULL |  |

### **3.5.3  csat\_ai\_recommendations — AI-Generated Recommendations Store**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| recommendation\_type | ENUM | NOT NULL | gap\_analysis | readiness\_flag | narrative\_draft | threat\_enrichment | policy\_gap | yoy\_summary |
| scope\_reference | VARCHAR(150) | NULLABLE | e.g. statement\_id:42 | domain:3 | category:2 | global |
| recommendation\_text | TEXT | NOT NULL | AI-generated recommendation content |
| cbn\_framework\_ref | VARCHAR(500) | NULLABLE | CBN/regulatory citation e.g. 'CBN Cybersecurity Framework §3.4.2' |
| effort\_estimate | ENUM | NULLABLE | low | medium | high |
| priority\_rank | TINYINT UNSIGNED | NULLABLE | AI-assigned priority 1=highest |
| user\_rating | TINYINT UNSIGNED | NULLABLE CHECK(1-5) | User quality rating — feeds AI quality KPI |
| model\_used | VARCHAR(100) | NOT NULL | LLM model identifier (e.g. claude-sonnet-4-6) |
| prompt\_version | VARCHAR(20) | NOT NULL | Prompt template version for reproducibility |
| generated\_at | TIMESTAMP | NOT NULL |  |
| is\_dismissed | BOOLEAN | NOT NULL DEFAULT false | User dismissed this recommendation |
| INDEX |  | (assessment\_id, recommendation\_type, is\_dismissed) |  |

### **3.5.4  csat\_evidence\_attachments — Evidence File Registry**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| attachable\_type | VARCHAR(100) | NOT NULL | Polymorphic type: CsatIrResponse | CsatMaResponse | CsatThreat | CsatVulnerability |
| attachable\_id | BIGINT UNSIGNED | NOT NULL | Polymorphic FK to attached-to record |
| file\_name | VARCHAR(500) | NOT NULL | Original uploaded filename |
| s3\_key | VARCHAR(1000) | NOT NULL | AWS S3 af-south-1 object key |
| file\_size\_bytes | INT UNSIGNED | NOT NULL |  |
| mime\_type | VARCHAR(100) | NOT NULL | Allowed: application/pdf, docx, xlsx, image/jpeg, image/png |
| uploaded\_by | BIGINT UNSIGNED | FK → users.id NOT NULL |  |
| uploaded\_at | TIMESTAMP | NOT NULL |  |

### **3.5.5  csat\_section\_assignments — Section Delegation Records**

| Column | Type | Constraints | Description |
| ----- | ----- | ----- | ----- |
| id | BIGINT UNSIGNED | PK |  |
| assessment\_id | BIGINT UNSIGNED | FK NOT NULL |  |
| scope\_type | ENUM | NOT NULL | domain | factor | component | statement |
| scope\_code | VARCHAR(20) | NOT NULL |  |
| assigned\_to | BIGINT UNSIGNED | FK → users.id NOT NULL |  |
| due\_date | DATE | NULLABLE |  |
| status | ENUM | NOT NULL DEFAULT 'pending' | pending | in\_progress | completed | overdue |
| assigned\_by | BIGINT UNSIGNED | FK → users.id NOT NULL | CISO who created assignment |
| assigned\_at | TIMESTAMP | NOT NULL |  |

## **3.6 Required Database Indexes**

| Table | Index Definition | Rationale |
| ----- | ----- | ----- |
| csat\_assessments | INDEX (institution\_id, assessment\_year) | Primary multi-tenant filter — all dashboard queries |
| csat\_ma\_responses | UNIQUE (assessment\_id, statement\_id) | Response lookup for scoring queries |
| csat\_ma\_responses | INDEX (assessment\_id, response) | Gap analysis: WHERE response \= 'no' filter |
| csat\_ma\_scores | UNIQUE (assessment\_id, score\_type, scope\_code) | Score retrieval for dashboards and reporting |
| csat\_ir\_responses | UNIQUE (assessment\_id, question\_id) | Inherent risk scoring query |
| csat\_ir\_category\_scores | UNIQUE (assessment\_id, category\_code) | Category score retrieval |
| csat\_ai\_recommendations | INDEX (assessment\_id, recommendation\_type, is\_dismissed) | AI recommendation filtering |
| csat\_threats | INDEX (assessment\_id, inherent\_risk\_score DESC) | Risk matrix ordering |
| csat\_vulnerabilities | INDEX (assessment\_id, composite\_score DESC) | Critical vulnerability alert ordering |
| csat\_section\_assignments | INDEX (assigned\_to, status) | User's pending assignments view |

# **4\. Scoring Algorithm Specifications**

All scoring logic is implemented as pure, deterministic PHP service classes. Scoring functions must produce results matching the CBN-CSAT Excel reference workbook within a tolerance of ±0.001. Each service is unit-tested to 100% coverage against five reference datasets derived from the Excel workbook.

## **4.1 Inherent Risk Scoring — InherentRiskScoringService**

### **4.1.1 Category Score Calculation**

| public function calculateCategoryScore(int $assessmentId, int $categoryCode): CategoryScore{    $responses \= CsatIrResponse::where('assessment\_id', $assessmentId)        \-\>whereHas('question', fn($q) \=\> $q-\>where('category\_code', $categoryCode)-\>where('is\_active', true))        \-\>with('question')        \-\>get();    $questionCount \= CsatIrQuestion::where('category\_code', $categoryCode)-\>where('is\_active', true)-\>count();    $answeredCount \= $responses-\>count();    $totalScore    \= $responses-\>sum('selected\_level');    $averageScore  \= $answeredCount \> 0 ? $totalScore / $answeredCount : 0;    $completionPct \= $questionCount \> 0 ? ($answeredCount / $questionCount) \* 100 : 0;    $riskLevel \= match(true) {        $averageScore \<= 1.4 \=\> 'least',        $averageScore \<= 2.4 \=\> 'minimal',        $averageScore \<= 3.4 \=\> 'moderate',        $averageScore \<= 4.4 \=\> 'significant',        default              \=\> 'most',    };    return new CategoryScore(        categoryCode:  $categoryCode,        totalScore:    $totalScore,        averageScore:  round($averageScore, 3),        riskLevel:     $riskLevel,        completionPct: round($completionPct, 2),    );} |
| :---- |

### **4.1.2 Composite Risk Score Calculation**

| // CBN-CSAT v1.1: All 5 categories carry equal weight in composite calculationpublic function calculateCompositeRisk(int $assessmentId): CompositeRiskScore{    $categoryScores \= collect(range(1, 5))        \-\>map(fn($cat) \=\> $this-\>calculateCategoryScore($assessmentId, $cat));    $compositeScore \= $categoryScores-\>avg('averageScore');    $compositeLevel \= match(true) {        $compositeScore \<= 1.4 \=\> 'least',        $compositeScore \<= 2.4 \=\> 'minimal',        $compositeScore \<= 3.4 \=\> 'moderate',        $compositeScore \<= 4.4 \=\> 'significant',        default                \=\> 'most',    };    return new CompositeRiskScore(        score:          round($compositeScore, 3),        level:          $compositeLevel,        categoryScores: $categoryScores,    );} |
| :---- |

## **4.2 Maturity Scoring — MaturityScoringService**

| CBN CUMULATIVE RULE: A component achieves maturity level L only when ALL declarative statements at levels 1 through L are answered Yes or Yes\[CC\]. Any incomplete lower level breaks the chain — the domain cannot claim that maturity level even if all higher-level statements are answered. |
| :---- |

### **4.2.1 Component Maturity Calculation**

| public function calculateComponentMaturity(int $assessmentId, string $componentCode): ComponentScore{    $achievedLevel \= 0; // 0 \= Sub-Baseline    foreach (range(1, 5\) as $level) {        $statements \= CsatMaStatement::where('component\_code', $componentCode)            \-\>where('maturity\_level', $level)            \-\>where('is\_active', true)            \-\>get();        if ($statements-\>isEmpty()) continue; // No statements at this level — skip        $responses  \= CsatMaResponse::where('assessment\_id', $assessmentId)            \-\>whereIn('statement\_id', $statements-\>pluck('id'))            \-\>get()            \-\>keyBy('statement\_id');        $total    \= $statements-\>count();        $yesCount \= $responses-\>whereIn('response', \['yes', 'yes\_cc'\])-\>count();        $fraction \= $total \> 0 ? $yesCount / $total : 0;        // CBN Cumulative Rule: stop at first level below 100%        if ($fraction \< 1.0) break;        $achievedLevel \= $level;    }    return new ComponentScore(        componentCode:       $componentCode,        achievedMaturityLevel: $achievedLevel,        completionPct:       $this-\>calculateCompletionPct($assessmentId, $componentCode),        levelScores:         $this-\>calculateAllLevelScores($assessmentId, $componentCode),    );} |
| :---- |

### **4.2.2 Domain Maturity Determination**

| // CBN Rule: Domain maturity \= LOWEST component maturity within the domainpublic function calculateDomainMaturity(int $assessmentId, int $domainCode): DomainScore{    $components \= CsatMaStatement::where('domain\_code', $domainCode)        \-\>where('is\_active', true)        \-\>distinct('component\_code')        \-\>pluck('component\_code');    $componentScores \= $components-\>map(        fn($code) \=\> $this-\>calculateComponentMaturity($assessmentId, $code)    );    $domainMaturityLevel \= $componentScores-\>min('achievedMaturityLevel'); // Lowest wins    return new DomainScore(        domainCode:          $domainCode,        achievedMaturityLevel: $domainMaturityLevel,        componentScores:     $componentScores,    );} |
| :---- |

# **5\. RESTful API Specification**

All API endpoints are prefixed /api/v1/csat and require a valid Laravel Sanctum Bearer token. Role-based access is enforced via WorkflowPolicy and CsatPolicy middleware. All responses follow the Atheris standard API envelope.

## **5.1 Standard Response Envelope**

| // Success response{  "success": true,  "data":    { ... },  "meta":    { "timestamp": "ISO-8601", "request\_id": "uuid", "version": "1.0" }}// Validation error (422){  "success": false,  "error": {    "code":    "CSAT\_VALIDATION\_ERROR",    "message": "The given data was invalid.",    "details": { "field": \["Validation rule message"\] }  }}// Business rule error (409 / 403){  "success": false,  "error": { "code": "CSAT\_WORKFLOW\_ERROR", "message": "Assessment is not in pending\_approval status." }} |
| :---- |

## **5.2 Assessment Management Endpoints**

| Method | Endpoint | Controller Method | Notes |
| ----- | ----- | ----- | ----- |
| GET | /assessments | index | List all assessments for institution. Paginated. Supports ?year=2025\&status=draft |
| POST | /assessments | store | Create new assessment. Required: assessment\_year, framework\_version |
| GET | /assessments/{id} | show | Full assessment detail including sub-module summary counts |
| PUT | /assessments/{id} | update | Update submission\_deadline, metadata only |
| POST | /assessments/{id}/clone-prior-year | clonePriorYear | Clone all responses from prior\_assessment\_id as starting point. Returns new assessment\_id. |
| GET | /assessments/{id}/dashboard | dashboard | Executive dashboard payload: scores, completion, deadline, AI readiness RAG |
| GET | /assessments/{id}/completion-status | completionStatus | Per-section completion: IR %, maturity per domain %, narratives %, threats count, vulnerabilities count |
| GET | /assessments/{id}/submission-checklist | submissionChecklist | CBN submission checklist (Sheet 6\) with automated validation status per item |

## **5.3 Inherent Risk Engine Endpoints**

| Method | Endpoint | Controller Method | Notes |
| ----- | ----- | ----- | ----- |
| GET | /assessments/{id}/inherent-risk/questions | getQuestions | All 47 questions with full criteria text, grouped by category. Includes current response if exists. |
| GET | /assessments/{id}/inherent-risk/responses | getResponses | All saved responses for assessment with question detail |
| PUT | /assessments/{id}/inherent-risk/responses/{questionId} | saveResponse | Save response. Body: { selected\_level: 1-5, comment: string|null }. Triggers async score recalc. |
| POST | /assessments/{id}/inherent-risk/responses/bulk | bulkSave | Bulk save. Body: { responses: \[{question\_id, selected\_level, comment}\] } |
| GET | /assessments/{id}/inherent-risk/scores | getScores | Computed category scores \+ composite score from csat\_ir\_category\_scores cache |
| GET | /assessments/{id}/inherent-risk/narratives | getNarratives | All narrative form fields for Sheet 12 |
| PUT | /assessments/{id}/inherent-risk/narratives/{key} | saveNarrative | Save single narrative field. Body: { value: string } |

## **5.4 Maturity Assessment Engine Endpoints**

| Method | Endpoint | Controller Method | Notes |
| ----- | ----- | ----- | ----- |
| GET | /assessments/{id}/maturity/statements | getStatements | All 494 statements with current responses. Filters: ?domain=1\&factor=D1-F1\&response=no\&level=2\&assigned\_to=me |
| PUT | /assessments/{id}/maturity/responses/{statementId} | saveResponse | Save response. Body: { response: yes|yes\_cc|no|na, comment }. Dispatches RecalculateMaturityScoresJob. |
| POST | /assessments/{id}/maturity/responses/bulk-import | bulkImport | Import from CBN Excel upload. Accepts .xlsx. Returns import summary (accepted/rejected/skipped counts). |
| GET | /assessments/{id}/maturity/scores | getScores | All component, factor, and domain scores from csat\_ma\_scores cache |
| GET | /assessments/{id}/maturity/heat-map | getHeatMap | Risk-Maturity matrix data: domain positions on 5×5 grid for Sheet 9 replication |
| GET | /assessments/{id}/maturity/gap-analysis | getGapAnalysis | All 'no' responses below target maturity. Sorted by gap severity. Includes AI recommendations if generated. |
| PUT | /assessments/{id}/maturity/targets/{scopeCode} | setTarget | Set target maturity level. Body: { target\_level: 1-5 } |
| GET | /assessments/{id}/maturity/narratives | getNarratives | All 28 supplementary narrative questions with current responses (Sheet 14\) |
| PUT | /assessments/{id}/maturity/narratives/{questionId} | saveNarrative | Save narrative response. Body: { response\_text: string } |
| POST | /assessments/{id}/maturity/assignments | createAssignment | Assign scope to team member. Body: { scope\_type, scope\_code, assigned\_to, due\_date } |

## **5.5 Threats & Vulnerabilities Endpoints**

| Method | Endpoint | Notes |
| ----- | ----- | ----- |
| GET | /threat-catalogue | Pre-loaded Nigerian catalogue. Filterable: ?source=external\&category=technical |
| GET | /assessments/{id}/threats | All threats for assessment |
| POST | /assessments/{id}/threats | Create threat. Body: { threat\_name, catalogue\_threat\_id?, threat\_source, threat\_category, likelihood, impact, description, mitigating\_controls\_desc } |
| PUT | /assessments/{id}/threats/{threatId} | Update threat attributes |
| DELETE | /assessments/{id}/threats/{threatId} | Remove threat (soft delete) |
| GET | /assessments/{id}/threats/risk-matrix | 3×3 risk matrix data — threat counts per cell for heat map rendering |
| GET | /assessments/{id}/vulnerabilities | All vulnerabilities for assessment |
| POST | /assessments/{id}/vulnerabilities | Create vulnerability. Body: { vulnerability\_name, vulnerability\_category, likelihood\_of\_exploit, impact\_if\_exploited, mitigants\_in\_place, existing\_mitigants, planned\_mitigants } |
| PUT | /assessments/{id}/vulnerabilities/{vulnId} | Update vulnerability details |
| PUT | /assessments/{id}/vulnerabilities/{vulnId}/status | Update remediation status. Body: { status: identified|assigned|in\_progress|remediated|verified, assigned\_to?, due\_date? } |

## **5.6 Workflow & Approval Endpoints**

| Method | Endpoint | Notes |
| ----- | ----- | ----- |
| GET | /assessments/{id}/workflow/status | Current stage, approval history, and next required action |
| POST | /assessments/{id}/workflow/submit | Submit assessment to next approval stage. Validates submission checklist completeness before allowing. |
| POST | /assessments/{id}/workflow/approve | Approve current stage. Role validated. Body: { comments? }. Generates digital\_signature\_token. |
| POST | /assessments/{id}/workflow/reject | Reject and return. Body: { comments: string (required) }. Resets status to in\_progress. |

## **5.7 AI Intelligence Layer Endpoints**

| Method | Endpoint | Notes |
| ----- | ----- | ----- |
| POST | /assessments/{id}/ai/gap-analysis | Trigger full gap analysis. Returns { job\_id }. Async — poll /ai/jobs/{jobId}/status. |
| POST | /assessments/{id}/ai/readiness-score | Generate CBN submission readiness score. Returns { job\_id }. |
| POST | /assessments/{id}/ai/narrative-draft/{qId} | AI-draft supplementary narrative. Returns { job\_id }. |
| POST | /assessments/{id}/ai/threat-enrichment | AI-suggest threats for institution risk profile. Returns { job\_id }. |
| POST | /assessments/{id}/ai/policy-gap | Cross-reference gaps with PPM policies. Returns { job\_id }. |
| GET | /assessments/{id}/ai/recommendations | All stored AI recommendations. Filters: ?type=gap\_analysis\&dismissed=false |
| PUT | /assessments/{id}/ai/recommendations/{recId}/rate | Rate recommendation. Body: { rating: 1-5 } |
| PUT | /assessments/{id}/ai/recommendations/{recId}/dismiss | Dismiss recommendation |
| GET | /ai/jobs/{jobId}/status | Poll AI job. Returns { status: pending|processing|complete|failed, result? } |

## **5.8 Reporting & Export Endpoints**

| Method | Endpoint | Notes |
| ----- | ----- | ----- |
| GET | /assessments/{id}/reports/executive-dashboard | Board dashboard data: composite risk, domain maturity, YoY trend, key gaps, deadline |
| GET | /assessments/{id}/reports/risk-maturity-matrix | Sheet 9 matrix data: domain positions on 5×5 risk×maturity grid |
| GET | /assessments/{id}/reports/domain-radar | Radar chart data: current \+ target \+ prior year maturity per domain |
| GET | /assessments/{id}/reports/gap-analysis | Prioritised gap report: all No-responses below target with AI recommendations |
| GET | /assessments/{id}/reports/progress-tracker | Completion tracker: per-section % with assignee breakdown and ETA |
| POST | /assessments/{id}/reports/submission-package | Generate full CBN PDF submission package. Returns { job\_id }. Rate limited: 5/hour. |
| GET | /assessments/{id}/reports/submission-package/{jobId} | Download generated PDF when job complete |
| GET | /assessments/{id}/reports/yoy-comparison | Year-on-year delta: changed responses, score movements, AI narrative summary |
| GET | /assessments/benchmarks/peer-group | Anonymous peer benchmarking vs. CBN cohort (dmb|mfb|mortgage\_bank) |

# **6\. AI Intelligence Layer — Technical Specification**

The AI Intelligence Layer uses the Laravel Prism package for LLM orchestration. All AI features use claude-sonnet-4-6 as the primary model. Assessment data sent to Anthropic is pseudonymised — institution\_id is replaced with a rotating pseudonymous token before any prompt dispatch.

## **6.1 Laravel Prism Configuration**

| // config/prism.php (CSAT-relevant section)'providers' \=\> \[    'anthropic' \=\> \[        'api\_key'       \=\> env('ANTHROPIC\_API\_KEY'),        'default\_model' \=\> 'claude-sonnet-4-6',        'max\_tokens'    \=\> 4096,    \]\],'cache' \=\> \[    'enabled' \=\> true,    'ttl'     \=\> 3600,   // AI responses cached 1hr per prompt hash    'driver'  \=\> 'redis',\], |
| :---- |

## **6.2 Gap Analysis Agent — GapAnalysisAgent.php**

Processes all No responses below target maturity in batches of 20 statements (to manage token context). Generates prioritised remediation recommendations with CBN framework citations for each gap.

| class GapAnalysisAgent{    public function generateRecommendations(CsatAssessment $assessment): void    {        $gaps \= $this-\>collectGaps($assessment); // All 'no' responses below target        $gaps-\>chunk(20)-\>each(function ($batch) use ($assessment) {            GenerateGapAnalysisJob::dispatch($assessment-\>id, $batch-\>pluck('id'))                \-\>onQueue('ai-high');        });    }    public function buildSystemPrompt(CsatAssessment $assessment): string    {        return \<\<\<PROMPT        You are a senior Nigerian banking cybersecurity consultant specialising in CBN regulatory compliance.        You are reviewing a CBN Cybersecurity Self-Assessment (FFIEC CAT v1.1) for a {$assessment-\>institutionProfile-\>cbn\_licence\_type}.        Your recommendations must:        1\. Reference specific CBN Risk-Based Cybersecurity Framework sections        2\. Use Nigerian banking terminology and reference the local ecosystem (NIBSS, NIP, USSD, NFIU)        3\. Acknowledge Nigerian infrastructure realities (connectivity, power, staffing)        4\. Cite NDPA 2023 obligations where the gap has data protection implications        5\. Provide effort estimates realistic for Nigerian banking sector resourcing (months, not quarters)        Respond ONLY as a valid JSON array. No preamble. No markdown fences. No explanation outside the JSON.        Each item: { "scope\_reference": string, "recommendation\_text": string,                     "cbn\_framework\_ref": string, "effort\_estimate": "low|medium|high",                     "priority\_rank": integer }        PROMPT;    }} |
| :---- |

## **6.3 Submission Readiness Score Agent — ReadinessScoreAgent.php**

Analyses the completed assessment for inconsistencies, empty sections, and CBN compliance risks. Returns a 0–100 score with RAG classification and specific named risk flags.

| Score Range | RAG Status | Interpretation | Recommended Action |
| ----- | ----- | ----- | ----- |
| 85–100 | Green | Ready for submission. Minor issues only. | Proceed to approval workflow immediately |
| 65–84 | Amber | Near-ready. Material addressable gaps exist. | Address flagged items before submitting to CISO |
| 0–64 | Red | Material issues exist that undermine CBN submission integrity. | Resolve all Red flags before initiating the approval workflow |

## **6.4 AI Job Queue Architecture**

| Job Class | Queue | Timeout | Retries | Trigger |
| ----- | ----- | ----- | ----- | ----- |
| RecalculateMaturityScoresJob | scoring | 30s | 3 | On every maturity response save |
| RecalculateInherentRiskJob | scoring | 20s | 3 | On every inherent risk response save |
| GenerateGapAnalysisJob | ai-high | 300s | 2 | POST /ai/gap-analysis |
| GenerateReadinessScoreJob | ai-high | 120s | 2 | POST /ai/readiness-score |
| DraftNarrativeJob | ai-medium | 60s | 1 | POST /ai/narrative-draft/{qId} |
| EnrichThreatsJob | ai-medium | 90s | 2 | POST /ai/threat-enrichment |
| PolicyGapJob | ai-medium | 120s | 2 | POST /ai/policy-gap |
| GenerateSubmissionPackageJob | pdf | 180s | 2 | POST /reports/submission-package |

# **7\. Frontend Component Architecture (React 18\)**

The CBN-CSAT frontend is a React 18 SPA integrated into the Atheris GRC platform shell. All components use the AEGIS design system tokens (design-system.md) with Tailwind CSS utility classes. TanStack Query v5 manages server state; Zustand manages UI state; Laravel Echo delivers real-time score updates.

## **7.1 State Management Strategy**

| State Domain | Library | Scope | Examples |
| ----- | ----- | ----- | ----- |
| Server state (API data) | TanStack React Query v5 | Per-query, cached \+ invalidated | Assessment records, scores, statements, recommendations |
| UI state | Zustand | Global store | Active domain, selected filter, modal state, AI job polling map |
| Form state | React Hook Form \+ Zod | Per-form component instance | Response capture, institution profile, narrative editors |
| Real-time | Laravel Echo \+ Pusher / Soketi | Channel subscription | Score recalculation broadcasts, approval notifications, AI job completion events |

## **7.2 Module Route Structure**

| // React Router v6 — CBN-CSAT Module/risk/cybersecurity-assessment  ├──  /                           AssessmentListPage (all cycles, status badges, deadlines)  ├──  /new                        CreateAssessmentPage  └──  /:assessmentId       ├──  /overview              AssessmentOverviewPage (executive dashboard)       ├──  /institution-profile   InstitutionProfilePage (Sheet 7 forms)       ├──  /inherent-risk       │    ├──  /                 InherentRiskDashboard (category scores \+ composite)       │    ├──  /questions        InherentRiskQuestionnairePage (47 questions)       │    └──  /narratives       InherentRiskNarrativesPage (Sheet 12 forms)       ├──  /maturity       │    ├──  /                 MaturityDashboard (scores \+ heat map)       │    ├──  /assessment       MaturityQuestionnairePage (494 statements — virtualised)       │    ├──  /narratives       MaturityNarrativesPage (28 supplementary questions)       │    └──  /targets          MaturityTargetPage (target maturity planning \+ gap view)       ├──  /threats               ThreatRegisterPage       ├──  /vulnerabilities       VulnerabilityRegisterPage       ├──  /ai-insights           AIInsightsDashboard (all recommendations, ratings)       ├──  /workflow              ApprovalWorkflowPage       └──  /reports            ├──  /executive        ExecutiveDashboardReport            ├──  /gap-analysis     GapAnalysisReport            └──  /submission       SubmissionPackagePage |
| :---- |

## **7.3 Key Component Specifications**

### **7.3.1 MaturityQuestionnaireEngine — Core Component (494 Statements)**

Most complex component in the module. Renders all 494 statements with response capture, real-time scoring, delegation, and compensating control workflows.

| Feature / Prop | Implementation | Notes |
| ----- | ----- | ----- |
| Domain sidebar | Fixed sidebar — 5 domain items with live completion % badge | Click domain scrolls to section |
| Statement rendering | Accordion: Domain → Factor → Component → Statements | Expand/collapse levels independently |
| Response capture | Radio group: 4 options (Yes / Yes\[CC\] / No / N/A) | Keyboard-navigable; auto-saves on selection |
| CC modal | Triggered on Yes\[CC\] selection | Captures: control\_name, description, effectiveness\_level, planned\_permanent\_date |
| Real-time scoring | Laravel Echo: assessment.{id}.scores.updated event | Score sidebar panel updates without refresh |
| Filter bar | Multi-select: Domain, Response status, Maturity level, Assigned to me | URL-persisted for shareable filtered views |
| Bulk assignment | Multi-select statements \+ assignee picker \+ due date | Dispatches POST /maturity/assignments per selection |
| Comment thread | Expandable inline panel per statement | Multi-participant threads with attribution \+ timestamp |
| Evidence attach | File drop zone in statement expansion panel | POST /evidence — 10MB limit, whitelist MIME types |
| Virtualisation | TanStack Virtual (react-virtual) | Virtual scrolling for 494 items — target render \< 100ms |
| YoY comparison | Side-by-side badge: prior year response alongside current | Visible when prior\_assessment\_id exists on assessment |

### **7.3.2 MaturityHeatMap Component (Risk-Maturity Matrix — Sheet 9\)**

| Feature | Implementation Details |
| ----- | ----- |
| Grid structure | 5×5 SVG grid. Y-axis: Maturity levels (Sub-Baseline → Innovative). X-axis: Inherent Risk levels (Least → Most). |
| Domain markers | Each of 5 domains rendered as a labelled marker at its (risk level, maturity level) intersection |
| Current cell highlight | AEGIS Gold (\#C8952A) border on institution's current overall risk+maturity cell |
| Target overlay | Dashed AEGIS Navy border on configured target cell per domain |
| Interactivity | Click domain marker → navigates to Maturity questionnaire filtered to that domain |
| Library | Custom SVG (no third-party chart) — pixel-accurate CBN matrix layout replication |

### **7.3.3 InherentRiskScorecard Component**

| Element | Library / Approach |
| ----- | ----- |
| Category bar chart | Recharts BarChart — 5 bars matching Sheet 3 layout. CBN colour-coded risk level fill per bar. |
| Composite risk badge | Custom AEGIS component — large centred level label (Least/Minimal/Moderate/Significant/Most) with AEGIS colour mapping |
| YoY delta badges | Arrow \+ colour-coded delta label per category (vs. prior year score) |
| Export | html-to-image → PNG — board report export via Export button |

### **7.3.4 AIInsightsDashboard Component**

| Element | Description |
| ----- | ----- |
| Readiness Score Widget | Large circular progress ring showing 0–100 readiness score with RAG colour. Flagged items listed below. |
| Recommendations list | Filterable by type (gap\_analysis | readiness\_flag | threat\_enrichment). Sorted by priority\_rank. |
| Recommendation card | Displays: recommendation\_text, cbn\_framework\_ref, effort\_estimate badge, priority\_rank, rating widget (1–5 stars), dismiss button. |
| Generate buttons | Trigger AI job endpoints with optimistic loading states. Poll /ai/jobs/{jobId}/status via React Query refetchInterval. |

# **8\. Module Integration Architecture**

The CBN-CSAT module integrates with three parent Atheris platform modules via typed service interfaces and Laravel events. All integrations are optional at module boot — if the parent module is not deployed, integration features degrade gracefully.

## **8.1 Integration with IT Risk Register**

| Integration Point | Direction | Trigger | Implementation |
| ----- | ----- | ----- | ----- |
| High-score threats → Risk Register | CSAT → Risk Register | Threat composite\_score ≥ 7 on save | ThreatHighRiskIdentified event → RiskRegisterIntegrationListener::createRiskItem() |
| Critical maturity gaps → Risk Register | CSAT → Risk Register | Gap analysis complete with Critical-rated gaps | PropagateMaturityGapsToRiskRegisterJob dispatched on AI gap analysis completion |
| Risk items → Assessment context | Risk Register → CSAT | AI gap analysis generation | RiskRegisterIntegrationService::getRelatedRisks(assessmentId) called in GapAnalysisAgent prompt context |

## **8.2 Integration with Issues & Findings**

| Integration Point | Direction | Implementation |
| ----- | ----- | ----- |
| Compensating controls → Issues | CSAT → Issues | CompensatingControlCreated event → IssuesFindingsIntegrationListener creates finding with planned\_permanent\_date as due\_date and CSAT reference link |
| AI recommendations → Issues | CSAT → Issues (user-triggered) | 'Add to Issues Register' button on recommendation card calls IssuesFindingsIntegrationService::createFromRecommendation() |
| Open CSAT issues → Assessment | Issues → CSAT | Assessment overview shows count of open Issues with csat\_ source tag |

## **8.3 Integration with Policy Management (PPM)**

| Integration Point | Direction | Implementation |
| ----- | ----- | ----- |
| AI Policy Gap Identification | CSAT → PPM (read) | PolicyGapAgent reads institution policies via PolicyManagementIntegrationService::getPublishedPolicies() and cross-references against maturity gaps |
| Policy gap report to PPM | CSAT → PPM | PolicyGapReportCreated event → PPM module receives gap list for policy creation workflow initiation |
| Policy documents → Evidence | PPM → CSAT (read) | Evidence attachment picker queries PolicyManagementIntegrationService::getPublishedPolicies() to allow policy-as-evidence attachment |

# **9\. Security Requirements**

The CBN-CSAT module processes confidential regulatory assessment data for regulated financial institutions. All security controls are mandatory with no exception. The module must be independently penetration-tested before production deployment.

## **9.1 Authentication & Authorisation**

| Requirement | Implementation | Standard |
| ----- | ----- | ----- |
| API Authentication | Laravel Sanctum Bearer token — required on all /api/v1/csat/\* routes | OAuth 2.0 Bearer |
| Module RBAC | Roles: csat\_preparer | csat\_reviewer | csat\_ciso | csat\_approver | csat\_signatory | csat\_admin — layered on Atheris core RBAC | Principle of Least Privilege |
| Institution isolation | InstitutionScope global Eloquent scope — all csat\_ model queries auto-filtered by auth user's institution\_id | Multi-tenant data isolation |
| Approval authorisation | WorkflowPolicy::canApprove() validates user role matches required stage role before any approval mutation | Policy-based authorisation |
| AI endpoint access | csat\_ciso or csat\_admin role required for all AI generation endpoints (BR-AI-01 to BR-AI-07) | Role restriction |

## **9.2 Data Protection (NDPA 2023 & CBN Requirements)**

| Requirement | Implementation |
| ----- | ----- |
| Data at rest encryption | AWS S3 SSE-KMS for all evidence files. MySQL column-level encryption for ciso\_email, ciso\_phone, head\_office\_address using Laravel Crypt. |
| Data in transit | TLS 1.3 enforced on all API and frontend traffic. HTTP→HTTPS redirect. HSTS header with 1-year max-age. |
| AWS data residency | All csat\_ data in AWS af-south-1 (Cape Town). S3 bucket policy blocks cross-region replication. MySQL RDS in af-south-1. No assessment data may transit non-African AWS regions. |
| AI data pseudonymisation | Before any prompt dispatch to Anthropic API: institution\_id replaced with rotating pseudonymous token; CISO name and contact details stripped from prompt context. |
| Data retention | Assessment records retained minimum 7 years (CBN examination window). Soft delete via deleted\_at — hard delete requires csat\_admin role \+ confirmation. Deletion audit trail maintained indefinitely. |
| Comprehensive audit trail | All write mutations on csat\_ tables logged to atheris\_audit\_log: user\_id, action, table\_name, record\_id, changed\_data (JSON diff), ip\_address, user\_agent, created\_at. |

## **9.3 Input Validation Rules**

| Field / Endpoint | Validation Rule |
| ----- | ----- |
| response (maturity) | ENUM in \['yes', 'yes\_cc', 'no', 'na'\] — strict. No other values accepted. |
| selected\_level (IR) | Integer in \[1, 5\] — strict range check. |
| Evidence file upload | MIME type whitelist: application/pdf, application/vnd.openxmlformats-officedocument.\*, image/jpeg, image/png. Max 10MB per file. |
| AI endpoints | Rate limit: 10 AI requests per assessment per hour per user. Enforced via Laravel throttle middleware. |
| PDF export | Rate limit: 5 submission package exports per assessment per hour. Enforced via custom RateLimiter. |
| Narrative text | Strip all HTML tags and executable content on save. Sanitise before storage using League/HtmlToMarkdown. |
| assessment\_year | Integer between current\_year \- 2 and current\_year \+ 1\. Cannot create duplicate year for same institution. |

# **10\. Performance Requirements**

## **10.1 API Response Time Targets**

| Endpoint Category | P50 Target | P99 Target | Strategy |
| ----- | ----- | ----- | ----- |
| Assessment dashboard | \< 200ms | \< 500ms | Redis-cached dashboard payload, invalidated on score update events |
| Maturity statements (494 items) | \< 300ms | \< 800ms | Paginated by domain (\~100 items). Eager load responses in single join. |
| Score calculation | Async (\< 100ms to queue) | — | Dispatched as queued job; client notified via WebSocket on completion |
| Report data endpoints | \< 500ms | \< 2000ms | Reads from materialised score caches (csat\_ma\_scores, csat\_ir\_category\_scores) |
| AI generation (async) | \< 2s to queue job | — | Returns job\_id immediately; background processing 30–300s |
| PDF submission package | \< 180s total | — | Puppeteer via Horizon 'pdf' queue. Client polls job status. |

## **10.2 Caching Strategy**

| Cache Key Pattern | TTL | Invalidation Trigger | Contents |
| ----- | ----- | ----- | ----- |
| csat:dash:{assessment\_id} | 15min | ScoresUpdated event | Executive dashboard payload |
| csat:scores:ir:{assessment\_id} | Until invalidated | RecalculateInherentRiskJob completes | All category scores \+ composite |
| csat:scores:ma:{assessment\_id} | Until invalidated | RecalculateMaturityScoresJob completes | All component/factor/domain scores |
| csat:statements:{version} | 24hrs | Framework version change | Full 494-statement catalogue (read-only) |
| csat:ir\_questions:{version} | 24hrs | Framework version change | Full 47-question catalogue with criteria text |
| csat:benchmark:{type}:{year} | 6hrs | Daily refresh job | Peer group aggregated benchmarking data |

# **11\. Testing Strategy & Acceptance Criteria**

## **11.1 Unit Testing (Pest PHP)**

| Test Suite | Coverage | Key Test Cases |
| ----- | ----- | ----- |
| InherentRiskScoringService | 100% | Category score calculation; composite score; risk level mapping; edge cases: all-Least, all-Most, partial responses, zero responses |
| MaturityScoringService | 100% | Component cumulative logic; domain lowest-wins rule; fractional scoring; Sub-Baseline detection; 100% Innovative achievement |
| GapAnalysisAgent | 80%+ | Prompt construction with institution context; JSON response parsing; recommendation persistence; batch chunking at 20 statements |
| ReadinessScoreAgent | 80%+ | Score range → RAG mapping; flag generation for empty narratives, CC inconsistencies, empty registers |
| WorkflowService | 90%+ | Stage progression; role validation; approval recording; rejection with return; checklist blocking validation |
| SubmissionPackageService | 85%+ | Checklist completeness; all-section validation; PDF job dispatch; package structure completeness |

## **11.2 Scoring Accuracy Regression Suite**

| CRITICAL: The scoring regression suite must pass 100% before any production deployment. Test datasets are derived from actual CBN-CSAT Excel workbook calculations. Tolerance: ±0.001 on all decimal scores. |
| :---- |

Five reference datasets are maintained as test fixtures:

* Dataset A: All responses at Baseline level — expected: Baseline maturity across all domains, category-specific IR scores

* Dataset B: Mixed responses with compensating controls — expected: partial maturity levels, CC-adjusted scores

* Dataset C: Below-Baseline (Sub-Baseline expected) — expected: zero achieved maturity, all domains Sub-Baseline

* Dataset D: Advanced-level institution — expected: Advanced maturity on Domains 1-3, Intermediate on 4-5

* Dataset E: Real anonymised Nigerian bank submission — score output verified against Excel workbook

## **11.3 API Integration Tests (Pest PHP)**

All 40+ API endpoints tested with four scenarios each: valid input (200/201), invalid input (422 validation), wrong institution (403), and unauthenticated (401).

Additional integration test scenarios:

* Inherent risk score recalculation triggered and cached correctly after response save

* Maturity score recalculation job dispatched on every response save

* Approval workflow role enforcement (csat\_preparer cannot approve stage 2\)

* AI endpoint rate limiting (10 requests/hour enforced)

* Evidence file upload MIME type rejection for disallowed types

## **11.4 Frontend & E2E Tests**

| Test Category | Library | Key Scenarios |
| ----- | ----- | ----- |
| Component unit tests | Vitest \+ Testing Library | Response capture, score display, compensating control modal, assignment picker |
| E2E — Full assessment flow | Playwright | Create assessment → complete inherent risk → complete 10 statements per domain → submit → approve all stages → generate PDF |
| E2E — AI features | Playwright | Trigger gap analysis → poll job → view recommendations → rate → dismiss |
| E2E — Excel import | Playwright | Upload CBN Excel template → validate import summary → verify responses persisted |
| Accessibility | axe-core \+ Playwright | WCAG 2.1 AA compliance for all 8 CBN-CSAT pages |
| Performance | Lighthouse CI | LCP \< 2.5s, TBT \< 200ms, CLS \< 0.1 for MaturityQuestionnairePage |

# **12\. Database Migrations & Seeding Plan**

## **12.1 Migration Execution Order**

| Order | Migration File | Description |
| ----- | ----- | ----- |
| 001 | create\_csat\_assessments\_table | Core assessment lifecycle records |
| 002 | create\_csat\_institution\_profiles\_table | Institution detail (Sheet 7\) |
| 003 | create\_csat\_stakeholder\_engagement\_table | Stakeholder engagement matrix |
| 004 | create\_csat\_ir\_questions\_table | 47-question IR catalogue (reference) |
| 005 | create\_csat\_ir\_responses\_table | IR assessment responses |
| 006 | create\_csat\_ir\_category\_scores\_table | IR computed score cache |
| 007 | create\_csat\_ir\_narratives\_table | IR narrative explanations (Sheet 12\) |
| 008 | create\_csat\_ma\_statements\_table | 494-statement maturity catalogue (reference) |
| 009 | create\_csat\_ma\_responses\_table | Maturity statement responses |
| 010 | create\_csat\_ma\_compensating\_controls\_table | Compensating control documentation |
| 011 | create\_csat\_ma\_scores\_table | Computed maturity score cache (all levels) |
| 012 | create\_csat\_ma\_narratives\_table | 28 supplementary narrative questions (Sheet 14\) |
| 013 | create\_csat\_threat\_catalogue\_table | Pre-loaded Nigerian threat catalogue |
| 014 | create\_csat\_threats\_table | Assessment threat register (Sheet 18\) |
| 015 | create\_csat\_vulnerabilities\_table | Assessment vulnerability register (Sheet 19\) |
| 016 | create\_csat\_approval\_stages\_table | Multi-stage approval configuration |
| 017 | create\_csat\_approval\_records\_table | Approval audit trail |
| 018 | create\_csat\_ai\_recommendations\_table | AI-generated recommendations store |
| 019 | create\_csat\_evidence\_attachments\_table | Evidence file registry (polymorphic) |
| 020 | create\_csat\_section\_assignments\_table | Section delegation records |

## **12.2 Seeder Requirements**

| Seeder | Source | Records | Critical Notes |
| ----- | ----- | ----- | ----- |
| CsatIrQuestionsSeeder | CBN-CSAT FFIEC CAT v1.1 (Sheets 11+12) | 47 | Must include full 5-level criteria text per question. CBN-specific questions (e.g. DR facility, PoS/ATM, tape backup) flagged cbn\_specific=true. |
| CsatMaStatementsSeeder | CBN-CSAT Excel Sheet 13 | 494 | MOST CRITICAL SEEDER. Must be 100% accurate — every statement with correct domain, factor, component, maturity\_level, and sequence. Any seeding error invalidates all scoring accuracy. |
| CsatMaNarrativesSeeder | CBN-CSAT Excel Sheet 14 | 28 | Pre-populate question\_text for all 28 supplementary questions across 5 domains. |
| CsatThreatCatalogueSeeder | CBN/FFIEC threat taxonomy \+ Nigerian threat intelligence | \~40 | Nigerian banking threats: ransomware, BEC, DDoS, phishing, USSD interception, PoS skimming, insider threat, supply chain, ATM jackpotting, mobile banking fraud. |

# **13\. Implementation Phasing — Technical Delivery Plan**

Five implementation phases aligned to BRD phasing (ATH-BRD-CBNCSAT-001 §15). Phases 1–3 target production readiness for the CBN annual submission cycle.

| Phase | Duration | Technical Deliverables | BRD Coverage |
| ----- | ----- | ----- | ----- |
| Phase 1Foundation \&Inherent Risk | Sprints 1–3Weeks 1–6 | Migrations 001–007. CsatServiceProvider registered. InherentRiskScoringService (100% unit-tested). CsatIrQuestionsSeeder (47 questions). IR API endpoints (all §5.3). InherentRiskQuestionnairePage \+ InherentRiskScorecard (React). Institution Profile CRUD \+ Stakeholder Matrix. RecalculateInherentRiskJob. | BR-IR-01 to BR-IR-07BR-IP-01 to BR-IP-03 |
| Phase 2Maturity Engine | Sprints 4–7Weeks 7–14 | Migrations 008–012. CsatMaStatementsSeeder (494 statements — CRITICAL). MaturityScoringService (100% unit-tested). Maturity API endpoints (all §5.4). MaturityQuestionnaireEngine with virtualisation \+ real-time scoring. CompensatingControl modal \+ CC workflow. MaturityHeatMap component. RecalculateMaturityScoresJob. Laravel Echo WebSocket broadcast. | BR-MA-01 to BR-MA-09BR-MA-13 |
| Phase 3Workflow \&Submission | Sprints 8–10Weeks 15–20 | Migrations 016–017. WorkflowService \+ WorkflowPolicy (role validation). Submission checklist validation engine. CBN Cover Letter PDF (Browsershot). SubmissionPackageService \+ GenerateSubmissionPackageJob. CBN deadline management \+ AWS SES notifications. ApprovalWorkflowPage \+ SubmissionPackagePage (React). Scoring regression suite passed. | BR-AW-01 to BR-AW-05 |
| Phase 4Threats, Vulnerabilities& AI Layer | Sprints 11–13Weeks 21–26 | Migrations 013–015, 018–019. CsatThreatCatalogueSeeder. Threat \+ Vulnerability CRUD API. ThreatRegisterPage \+ VulnerabilityRegisterPage (React). GapAnalysisAgent \+ GenerateGapAnalysisJob. ReadinessScoreAgent \+ GenerateReadinessScoreJob. AIInsightsDashboard (React). Full reporting suite (Recharts). ExecutiveDashboardReport. | BR-TR-01 to BR-VR-04BR-AI-01 to BR-AI-03BR-RP-01 to BR-RP-06 |
| Phase 5Advanced Features& UAT | Sprints 14–16Weeks 27–32 | Year-on-year comparison API \+ UI. NarrativeAssistantAgent (BR-AI-04). PolicyGapAgent \+ PPM integration (BR-AI-06). Peer benchmarking engine \+ BenchmarkingService. Bulk Excel import (BR-MA-12). Redis caching optimisation \+ index tuning. Penetration testing. WCAG 2.1 AA audit. UAT with CBN pilot institution. Production cutover. | BR-IR-08BR-MA-10 to BR-MA-13BR-AI-04 to BR-AI-07BR-RP-07 to BR-RP-08 |

# **14\. Technical Glossary**

| Term | Technical Definition |
| ----- | ----- |
| AEGIS Design System | Atheris-proprietary frontend design system. Tailwind CSS token layer providing NAVY (\#0F2044), GOLD (\#C8952A), LIGHT\_NAVY (\#1A3A6B) colour variables, typography scale, component patterns, and WCAG 2.1 AA compliant interactive states for all Atheris frontend modules. |
| InstitutionScope | Laravel Eloquent Global Scope applied to all csat\_ models. Automatically appends WHERE institution\_id \= auth()-\>user()-\>institution\_id to every query. Enforces multi-tenant data isolation transparently — no per-controller implementation required. |
| Cumulative Maturity Logic | CBN-CSAT scoring rule: a component achieves maturity level L only when 100% of declarative statements at levels 1 through L are answered Yes or Yes\[CC\]. Any incomplete lower level breaks the cumulative chain — no skipping levels. |
| Fractional Score | A DECIMAL(6,4) value in range \[0.0000–1.0000\]. Calculated as (Yes \+ Yes\[CC\]) / total statements at a given maturity level within a component. Used in factor and domain score aggregation. |
| Laravel Prism | Laravel-native LLM orchestration package. Provides multi-model routing (Anthropic/OpenAI/Ollama), prompt versioning, response caching, streaming, and structured output parsing. Used for all CSAT AI features. |
| Composite Risk Score | DECIMAL(5,3) value (1.000–5.000). Equal-weighted average of average\_score across all 5 inherent risk categories. Maps to: ≤1.4=Least, ≤2.4=Minimal, ≤3.4=Moderate, ≤4.4=Significant, \>4.4=Most. |
| RecalculateMaturityScoresJob | Laravel queued job on 'scoring' queue. Recalculates all component, factor, and domain maturity scores for an assessment and updates csat\_ma\_scores cache. Dispatched on every maturity response save. Broadcasts ScoresUpdated event to frontend via WebSocket on completion. |
| digital\_signature\_token | HMAC-SHA256 hash of (user\_id \+ assessment\_id \+ stage\_number \+ actioned\_at ISO timestamp). Stored immutably in csat\_approval\_records for non-repudiation. Cannot be forged without the application signing key. |
| Submission Package | Complete PDF export required for CBN annual submission. Generated by SubmissionPackageService via Browsershot/Puppeteer. Contains: assessment summary, IR results, maturity results per domain, threat register, vulnerability register, attestation page, CBN cover letter (§3.9.3 reference), and evidence index. |
| af-south-1 | AWS Africa (Cape Town) region. Mandatory data residency region for all CSAT assessment data under NDPA 2023 and CBN data localisation requirements. All MySQL RDS, S3 buckets, and Elasticache instances must be deployed exclusively in this region. |
| Yes\[CC\] | Yes with Compensating Control. Response indicating partial compliance — the required control is not fully implemented, but a compensating control is documented. Treated as Yes for maturity scoring. Mandatory: triggers CC documentation workflow \+ Issues & Findings linkage. Tracked separately in csat\_ma\_compensating\_controls. |

---

# **15. Implementation Task Tracker**

## **Phase 1 — Foundation & Inherent Risk Engine (Current)**

| # | Task | Status |
|---|------|--------|
| 1.1 | Create 20 database migrations (csat_* tables) | ✅ |
| 1.2 | Create Eloquent models for all csat_ tables | ✅ |
| 1.3 | Create CsatServiceProvider and register module | ✅ |
| 1.4 | Create InherentRiskScoringService (category + composite scoring) | ✅ |
| 1.5 | Create MaturityScoringService (component + domain scoring) | ✅ |
| 1.6 | Create CsatIrQuestionsSeeder (47 inherent risk questions) | ✅ |
| 1.7 | Create CsatMaStatementsSeeder (344 maturity statements) | ✅ |
| 1.8 | Create CsatThreatCatalogueSeeder (30 Nigerian threats) | ✅ |
| 1.9 | Create CsatMaNarrativesTemplateSeeder (28 narrative questions) | ✅ |
| 1.10 | Create CSAT Controllers (single CsatAssessmentController with all actions) | ✅ |
| 1.11 | Create Form Request validation classes (inline in controller) | ✅ |
| 1.12 | Register all CSAT web routes (34 Inertia routes) | ✅ |
| 1.13 | Create AssessmentListPage (React) — CSAT/Index.jsx | ✅ |
| 1.14 | Create AssessmentOverviewPage / Dashboard (React) — CSAT/Overview.jsx | ✅ |
| 1.15 | Create InstitutionProfilePage (React) — CSAT/InstitutionProfile.jsx | ✅ |
| 1.16 | Create InherentRiskQuestionnairePage (React) — CSAT/InherentRisk/Questions.jsx | ✅ |
| 1.17 | Create InherentRiskDashboard with scorecard (React) — CSAT/InherentRisk/Dashboard.jsx | ✅ |
| 1.18 | Create InherentRiskNarrativesPage (React) — CSAT/InherentRisk/Narratives.jsx | ✅ |
| 1.19 | Create MaturityQuestionnairePage (React) — CSAT/Maturity/Assessment.jsx | ✅ |
| 1.20 | Create MaturityDashboard with heat map (React) — CSAT/Maturity/Dashboard.jsx | ✅ |
| 1.21 | Create MaturityNarrativesPage (React) — CSAT/Maturity/Narratives.jsx | ✅ |
| 1.22 | Create MaturityTargetPage (React) — CSAT/Maturity/Targets.jsx | ✅ |
| 1.23 | Create ThreatRegisterPage (React) — CSAT/Threats.jsx | ✅ |
| 1.24 | Create VulnerabilityRegisterPage (React) — CSAT/Vulnerabilities.jsx | ✅ |
| 1.25 | Create ApprovalWorkflowPage (React) — CSAT/Workflow.jsx | ✅ |
| 1.26 | Create AIInsightsDashboard (React) — CSAT/AIInsights.jsx | ✅ |
| 1.27 | Create ReportsPages (Executive) (React) — CSAT/Reports.jsx | ✅ |
| 1.28 | Add sidebar navigation entry for CBN-CSAT module | ✅ |
| 1.29 | Run migrations and seeders — verify database (47 IR Q's, 344 MA stmts, 30 threats, 5 domains, 30 components) | ✅ |
| 1.30 | End-to-end test: create assessment → complete IR → complete maturity → workflow → reports | ✅ |

ATH-TRD-CBNCSAT-001  |  v1.0  |  CONFIDENTIAL — Atheris Limited  |  **© 2025 Atheris Limited. All Rights Reserved.**
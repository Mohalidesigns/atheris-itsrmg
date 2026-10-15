# IT Governance Policy: Integrated CAPA Module
## (ISO/IEC 27001:2022, COBIT 2019, ITIL v4, & CBN IT Standards Aligned)

---

## 1. Document Control & Metadata
* **Document ID:** IT-GOV-CAPA-001
* **Version:** 3.0
* **Effective Date:** October 14, 2026
* **Owner:** Head of IT Governance / Chief Information Security Officer (CISO)
* **Approver:** Chief Information Officer (CIO) / IT Steering Committee

---

## 2. Policy Purpose & Framework Flexibility
The purpose of this module is to establish a standardized process for identifying, investigating, resolving, and preventing structural deficiencies within the IT environment. 

To maximize operational agility, this module natively accommodates three primary IT management frameworks. The IT Governance, Operations, and Security teams are empowered to select the framework path that best aligns with the specific asset, process, or incident under review:

| Framework Option | Primary Use Case / Focus | Core Reference Clauses / Practices |
| :--- | :--- | :--- |
| **ISO/IEC 27001:2022** | Information Security Management Systems (ISMS) & Compliance | Clause 10.1 (Continual Improvement), Clause 10.2 (Nonconformity & Corrective Action) |
| **COBIT 2019** | Enterprise IT Governance, Strategy, & Risk Alignment | APO12 (Managed Risk), MEA01 (Managed Performance & Conformance Monitoring) |
| **ITIL v4** | Service Management, Operational Incidents, & Continuous Value | Problem Management, Continual Improvement Practice |

---

## 3. Mandatory Central Bank of Nigeria (CBN) Mapping
Regardless of the architectural framework chosen above, all CAPA workflows executed within Nigerian entities under CBN jurisdiction **must** cross-reference and fulfill the following regulatory provisions:

1. **CBN IT Standards Blueprint (Section 3.5 & 4.2):** Mandates formal root cause analysis for persistent system downtimes, core banking platform errors, and missing software patches.
2. **CBN Risk-Based Cyber Security Framework:** Section 3.1.5 requires formalized remedial action plans following any security incident, internal audit finding, or vulnerability penetration testing exception.
3. **CBN Consumer Protection Regulations:** Section 6.0 dictates that any operational IT failure causing erroneous customer account debits, mobile banking downtimes, or financial data leakage must trigger an immediate CAPA log to prevent systematic recurrence.

---

## 4. The 6-Stage Integrated CAPA Workflow

### Stage 1: Identification & Multi-Framework Classification
When a deficiency is discovered, the IT Team logs the entry and selects their applicable framework methodology:

* **Trigger Sources:** IT Audits, CBN Inspection Reports, Security Incidents, Penetration Test Vulnerabilities, Major Service Outages.
* **Framework Routing Option:**
  * **ISO 27001 Path:** Classify as a *Nonconformity* affecting the confidentiality, integrity, or availability of an asset.
  * **COBIT Path:** Classify as a *Risk Event* or *Performance Variance* skewing enterprise alignment.
  * **ITIL Path:** Classify as a *Problem Statement* emerging from one or more underlying operational Incidents.

### Stage 2: Containment & Immediate Mitigation
Before deep-dive analysis, immediate actions must be taken to minimize current exposure, operational impacts, or financial regulatory penalties.
* **Actions:** Isolate compromised servers, apply temporary firewall rules, restart failing services, or issue provisional customer advisories.

### Stage 3: Multi-Framework Root Cause Analysis (RCA)
Teams must investigate the systemic cause of the issue using an approved methodology:
* **The 5 Whys Technique:** Iteratively drilling down into the failure chain to uncover the operational fault.
* **Ishikawa (Fishbone) Diagram:** Segmenting causes into People, Process, Technology, and Governance.
* **Framework Execution Alignment:**
  * **ISO 27001 Focus:** Isolate the breakdown in information security controls (e.g., failed access reviews, missing policy).
  * **COBIT Focus:** Identify gaps in capability levels, organizational structures, or process metrics.
  * **ITIL Focus:** Isolate the underlying structural error within the IT infrastructure or configuration baseline.

### Stage 4: Action Plan Formulation & CBN Regulatory Alignment
Develop a structured plan defining explicit tasks, owners, and strict deadlines. Every plan must explicitly document:
* **CBN Correlation:** Explicitly state which CBN directive is being satisfied by the permanent fix (e.g., *"Fixes CBN Cyber Security Guideline Sec 3.1.5 on patching latency"*).

### Stage 5: Implementation & Deployment Tracking
Action items are moved into execution. System changes must route through the corporate IT Change Management process to prevent secondary incidents.

### Stage 6: Independent Effectiveness Verification
CAPA closure cannot happen immediately after patch implementation. An independent party (e.g., IT Governance Team or Internal Audit) must verify the fix after a designated settling period (typically 30 to 90 days).
* **ISO 27001 Criteria:** Confirm the risk is within acceptable thresholds and the nonconformity is mitigated.
* **COBIT Criteria:** Verify process capability metrics have returned to standard baselines.
* **ITIL Criteria:** Ensure the problem record is closed with no subsequent incident recurrences from that root cause.

---

## 5. Governance RACI Matrix

| CAPA Step | IT Governance / GRC | IT Infrastructure & Operations | Information Security / CISO | Internal IT Auditor |
| :--- | :---: | :---: | :---: | :---: |
| **1. Identification** | **A** | **R** | **R** | **C** |
| **2. Containment** | **C** | **R** | **R** | **I** |
| **3. Root Cause Analysis** | **A** | **R** | **R** | **I** |
| **4. Action Planning** | **A** | **R** | **R** | **C** |
| **5. Implementation** | **I** | **R** | **R** | **I** |
| **6. Verification & Closure**| **R** | **I** | **I** | **A** |

* **R (Responsible):** The executioner who does the work.
* **A (Accountable):** The ultimate approver with veto power.
* **C (Consulted):** Subject Matter Experts providing vital inputs.
* **I (Informed):** Parties kept updated on completion status.

---

## 6. Key Performance Indicators (KPIs) & Escalation Matrix
To ensure accountability, metrics are reviewed during monthly governance meetings and aggregated for the annual **ISO 27001 Management Review** and **CBN Compliance reporting cycles**:

### Performance Metrics
* **CAPA Overdue Rate:** Percentage of action items exceeding their target remediation deadlines (Target: < 5%).
* **Recurrence Rate:** Percentage of closed issues that reappear within 12 months (Target: 0%).
* **Mean Time to Remediate (MTTR):** Average calendar days elapsed from Stage 1 to Stage 5.

### Resolution SLA & Escalation Hierarchy

| Severity Level | Framework Context Examples | Max Remediation Window | Escalation Policy |
| :--- | :--- | :--- | :--- |
| **Critical** | Major CBN data breach / Core banking downtime / ISO 27001 Class A failure | **48 Hours** | Notify CIO, CISO, and Risk Committee within 2 hours. Daily status calls. |
| **High** | High-rated vulnerability exploit risk / COBIT target variance > 30% | **14 Days** | Notify Head of IT Operations. Weekly status reporting. |
| **Medium** | Minor process nonconformity / Recurring low-impact ITIL incidents | **30 Days** | Standard IT Governance tracking. |
| **Low** | General improvement suggestion / Documentation updates | **90 Days** | Quarterly audit cycle cleanup. |

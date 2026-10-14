<?php

namespace App\Services\Approvals;

use App\Models\AccessReviewCampaign;
use App\Models\BcpPlan;
use App\Models\BiaRecord;
use App\Models\BoardPackRun;
use App\Models\ConfigDriftEvent;
use App\Models\Ea\MaturityAssessment;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\SoaVersion;

/**
 * Every process in the platform that needs an approval (ATH-VAL-016), and what Workflow Studio
 * needs to know to configure it.
 *
 * - `engine`: "generic" processes run on ApprovalEngine (stages configured in Workflow Studio);
 *   "change" and "csat" keep their own engines (ChangeApprovalRouter, CsatWorkflowService), whose
 *   stages Workflow Studio edits in place.
 * - `permission`: the permission a person needs to act on the process at all; a stage narrows who
 *   among them may decide it.
 * - `initiator`: the record attribute holding who started the process (excluded from approving it).
 * - `owner`: the attribute an "owner" stage resolves to; `owner_cannot_approve` mirrors the
 *   module's rule that the record owner never approves it.
 * - `conditions`: record attributes a stage can be limited to (e.g. only critical drift).
 * - `initiate`: where the process starts, enforced against the configured initiator roles.
 * - `default`: what happens when the bank has configured no stages.
 */
class ApprovalProcesses
{
    public const PROCESSES = [
        'change.approval' => [
            'module' => 'Change Management', 'label' => 'Change approval', 'engine' => 'change',
            'description' => 'Risk-based approval of normal and emergency changes: peer and security review, business owner, CAB / ECAB, Change Manager authorisation.',
            'permission' => 'approve changes', 'initiate' => 'Submitting a change for approval',
            'default' => 'Default stages by change type and risk level (seeded per bank).',
        ],
        'csat.assessment' => [
            'module' => 'CBN Cyber Assessment', 'label' => 'CSAT sign-off', 'engine' => 'csat',
            'description' => 'Sequential sign-off of the annual CBN CSAT self-assessment before submission to the CBN.',
            'permission' => 'approve csat', 'initiate' => 'Submitting the assessment for approval',
            'default' => 'Preparer submission, CISO, CRO/CIO/COO, MD/CEO.',
        ],
        'policy.approval' => [
            'module' => 'Policy Management', 'label' => 'Policy approval', 'engine' => 'generic', 'owner_cannot_approve' => true,
            'description' => 'A policy submitted for review is approved before it can be published.',
            'permission' => 'approve policies', 'model' => Policy::class, 'initiator' => 'submitted_by', 'owner' => 'owner_id',
            'title' => ['policy_code', 'title'], 'route' => 'policies.show', 'initiate' => 'Submitting a policy for review',
            'conditions' => ['category' => ['label' => 'Policy category', 'values' => Policy::CATEGORIES]],
            'default' => 'One approval by anyone with "approve policies" other than the submitter.',
        ],
        'policy.exception' => [
            'module' => 'Policy Management', 'label' => 'Policy exception', 'engine' => 'generic',
            'description' => 'A requested exception to a policy or control, with compensating controls and an expiry.',
            'permission' => 'approve policies', 'model' => PolicyException::class, 'initiator' => 'requested_by', 'owner' => 'owner_id',
            'title' => ['exception_code', 'title'], 'route' => 'policy-exceptions.index', 'initiate' => 'Requesting an exception',
            'conditions' => ['risk_rating' => ['label' => 'Risk rating', 'values' => ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low']]],
            'default' => 'One decision by anyone with "approve policies" other than the requester.',
        ],
        'isms.soa' => [
            'module' => 'ISMS', 'label' => 'Statement of Applicability', 'engine' => 'generic',
            'description' => 'A version of the ISO 27001 SoA submitted for approval.',
            'permission' => 'approve isms', 'model' => SoaVersion::class, 'initiator' => 'prepared_by',
            'title' => ['version_no'], 'title_prefix' => 'SoA version ', 'route' => 'isms.soa', 'initiate' => 'Submitting a SoA version',
            'default' => 'One decision by anyone with "approve isms" other than the preparer.',
        ],
        'bcp.plan' => [
            'module' => 'Business Continuity', 'label' => 'BCP / DR plan approval', 'engine' => 'generic', 'owner_cannot_approve' => true,
            'description' => 'A continuity plan is approved before it is in force.',
            'permission' => 'approve bcp-plans', 'model' => BcpPlan::class, 'initiator' => 'owner_id', 'owner' => 'owner_id',
            'title' => ['plan_code', 'title'], 'route' => 'bcp.plans.show', 'initiate' => 'The plan owner',
            'conditions' => ['plan_type' => ['label' => 'Plan type', 'values' => ['bcp' => 'BCP', 'dr' => 'DR', 'crisis' => 'Crisis']]],
            'default' => 'One approval by anyone with "approve bcp-plans" other than the plan owner.',
        ],
        'bcp.bia' => [
            'module' => 'Business Continuity', 'label' => 'BIA approve & apply', 'engine' => 'generic', 'owner_cannot_approve' => true,
            'description' => 'A business impact analysis is approved and its RTO/RPO applied to the business service.',
            'permission' => 'approve bcp-plans', 'model' => BiaRecord::class, 'initiator' => 'owner_id', 'owner' => 'owner_id',
            'title' => ['process_name'], 'route' => 'bcp.bia', 'initiate' => 'Recording a BIA',
            'conditions' => ['criticality' => ['label' => 'Criticality', 'values' => ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low']]],
            'default' => 'One approval by anyone with "approve bcp-plans" other than the process owner.',
        ],
        'drift.acceptance' => [
            'module' => 'Continuous Monitoring', 'label' => 'Drift risk acceptance', 'engine' => 'generic', 'owner_cannot_approve' => true,
            'description' => 'Accepting the risk of a configuration drift instead of remediating it.',
            'permission' => 'approve monitoring', 'model' => ConfigDriftEvent::class, 'initiator' => 'owner_id', 'owner' => 'owner_id',
            'title' => ['drift_code', 'title'], 'route' => 'drift.show', 'initiate' => 'The drift owner',
            'conditions' => ['severity' => ['label' => 'Severity', 'values' => ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low']]],
            'default' => 'One acceptance by anyone with "approve monitoring" other than the drift owner.',
        ],
        'access_review.signoff' => [
            'module' => 'Continuous Monitoring', 'label' => 'Access review sign-off', 'engine' => 'generic',
            'description' => 'Completing a user-access recertification campaign once every entitlement has a decision.',
            'permission' => 'approve monitoring', 'model' => AccessReviewCampaign::class, 'initiator' => 'owner_id', 'owner' => 'owner_id',
            'title' => ['campaign_code', 'name'], 'route' => 'access-reviews.show', 'initiate' => 'Launching a campaign',
            'conditions' => ['category' => ['label' => 'Campaign category', 'values' => ['application' => 'Application', 'infrastructure' => 'Infrastructure', 'privileged' => 'Privileged', 'physical' => 'Physical', 'vendor' => 'Vendor']]],
            'default' => 'One sign-off by anyone with "approve monitoring".',
        ],
        'board_pack.approval' => [
            'module' => 'KRIs & Dashboards', 'label' => 'Board pack approval', 'engine' => 'generic',
            'description' => 'A generated board pack is approved before it goes to the board or committee.',
            'permission' => 'approve reports', 'model' => BoardPackRun::class, 'initiator' => 'prepared_by',
            'title' => ['title'], 'route' => 'board-packs.show', 'initiate' => 'Generating a board pack',
            'default' => 'One approval by anyone with "approve reports" other than the preparer.',
        ],
        'ea.maturity' => [
            'module' => 'Enterprise Architecture', 'label' => 'EA maturity assessment', 'engine' => 'generic',
            'description' => 'The CBN EA maturity (CMMI) assessment is approved as the bank\'s rated maturity.',
            'permission' => 'approve ea', 'model' => MaturityAssessment::class, 'initiator' => 'submitted_by',
            'title' => ['title'], 'route' => 'ea.maturity.show', 'initiate' => 'Submitting the assessment',
            'default' => 'One approval by anyone with "approve ea" other than the submitter.',
        ],
    ];

    public const APPROVER_TYPES = [
        'role' => 'Anyone holding a role',
        'user' => 'A named person',
        'job_title' => 'Anyone with a job title',
        'owner' => 'The record owner',
        'permission' => 'Anyone holding a permission',
    ];

    public static function get(string $process): array
    {
        return self::PROCESSES[$process] ?? throw new \InvalidArgumentException("Unknown approval process {$process}.");
    }

    public static function generic(): array
    {
        return array_filter(self::PROCESSES, fn ($p) => $p['engine'] === 'generic');
    }

    /** "KHB-POL-012 Acceptable Use Policy" from the process's title attributes. */
    public static function label(string $process, $subject): string
    {
        $def = self::get($process);

        return ($def['title_prefix'] ?? '').collect($def['title'] ?? ['id'])->map(fn ($a) => $subject->{$a})->filter()->implode(' ');
    }
}

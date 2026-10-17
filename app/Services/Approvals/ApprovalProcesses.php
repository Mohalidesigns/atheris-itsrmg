<?php

namespace App\Services\Approvals;

use App\Models\AccessReviewCampaign;
use App\Models\BcpPlan;
use App\Models\BiaRecord;
use App\Models\BoardPackRun;
use App\Models\ConfigDriftEvent;
use App\Models\Ea\MaturityAssessment;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\Renewal;
use App\Models\Finance\SupplementaryRequest;
use App\Models\Finance\Termination;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\SoaVersion;
use App\Services\Finance\DoaBandService;

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
        'finance.budget_approval' => [
            'module' => 'IT Financial Management', 'label' => 'Budget version approval', 'engine' => 'generic', 'owner_cannot_approve' => true,
            'description' => 'A budget version is approved before Finance baselines it (COBIT APO06.03).',
            'permission' => 'approve it-finance', 'model' => Budget::class, 'initiator' => 'submitted_by_id', 'owner' => 'owner_id',
            'title' => ['budget_code', 'version_label', 'title'], 'route' => 'finance.budgets.show', 'initiate' => 'Submitting a budget version',
            'conditions' => [
                'amount_band' => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
                'cost_centre_id' => ['label' => 'Cost centre', 'values' => 'finance.cost_centres'],
                'cycle_type' => ['label' => 'Cycle type', 'values' => ['annual' => 'Annual', 'mid_year_reforecast' => 'Mid-year reforecast', 'rolling_forecast' => 'Rolling forecast', 'supplementary' => 'Supplementary']],
            ],
            'default' => 'One approval by anyone with "approve it-finance" other than the submitter and the budget owner.',
        ],
        'finance.budget_transfer' => [
            'module' => 'IT Financial Management', 'label' => 'Budget transfer (virement)', 'engine' => 'generic',
            'description' => 'Moving approved budget between lines, cost centres or periods is approved before it is applied.',
            'permission' => 'approve it-finance', 'model' => BudgetTransfer::class, 'initiator' => 'requested_by_id',
            'title' => ['transfer_code'], 'route' => 'finance.transfers.index', 'initiate' => 'Submitting a budget transfer',
            'conditions' => [
                'amount_band' => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
                'crosses_cost_centre' => ['label' => 'Crosses cost centres', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'capex_opex' => ['label' => 'CapEx / OpEx', 'values' => ['capex' => 'CapEx', 'opex' => 'OpEx', 'mixed' => 'Mixed']],
            ],
            'default' => 'One approval by anyone with "approve it-finance" other than the requester.',
        ],
        'finance.supplementary_budget' => [
            'module' => 'IT Financial Management', 'label' => 'Supplementary budget', 'engine' => 'generic',
            'description' => 'A mid-year top-up of the approved budget is approved before it is applied.',
            'permission' => 'approve it-finance', 'model' => SupplementaryRequest::class, 'initiator' => 'requested_by_id',
            'title' => ['request_code', 'title'], 'route' => 'finance.supplementary.index', 'initiate' => 'Submitting a supplementary request',
            'conditions' => [
                'amount_band' => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
                'category_id' => ['label' => 'TBM category', 'values' => 'finance.categories'],
                'capex_opex' => ['label' => 'CapEx / OpEx', 'values' => ['capex' => 'CapEx', 'opex' => 'OpEx']],
            ],
            'default' => 'One approval by anyone with "approve it-finance" other than the requester.',
        ],
        'finance.contract_renewal' => [
            'module' => 'IT Financial Management', 'label' => 'Contract renewal decision', 'engine' => 'generic', 'owner_cannot_approve' => true,
            'description' => 'A renewal recommendation (renew, renegotiate, replace, terminate, extend) is approved before the next term is created (FIN-06.05).',
            'permission' => 'approve it-finance-contracts', 'view_permission' => 'view it-finance', 'model' => Renewal::class, 'initiator' => 'requested_by_id', 'owner' => 'contract_owner_id',
            'title' => ['renewal_code', 'contract_label'], 'route' => 'finance.renewals.show', 'initiate' => 'Submitting a renewal recommendation',
            'conditions' => [
                'amount_band' => ['label' => 'Amount band (DoA, proposed value)', 'values' => 'finance.doa_bands'],
                'decision' => ['label' => 'Recommendation', 'values' => ['renew_as_is' => 'Renew as is', 'renew_renegotiated' => 'Renew (renegotiated)', 'replace' => 'Replace', 'terminate' => 'Terminate', 'extend_short_term' => 'Short-term extension']],
                'contract_type' => ['label' => 'Contract type', 'values' => self::CONTRACT_TYPE_LABELS],
                'uplift_band' => ['label' => 'Price uplift', 'values' => ['decrease' => 'Decrease or flat', '0-5' => 'Up to 5 %', '5-10' => '5 % to 10 %', '10+' => 'Above 10 %']],
                'requires_cfo' => ['label' => 'Needs CFO (high value or uplift above threshold)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
            ],
            'default' => 'One approval by anyone with "approve it-finance-contracts" other than the requester and the contract owner.',
        ],
        'finance.contract_termination' => [
            'module' => 'IT Financial Management', 'label' => 'Contract early termination', 'engine' => 'generic', 'owner_cannot_approve' => true,
            'description' => 'Ending a contract before its end date is approved before the termination is applied (FIN-06, plan H2).',
            'permission' => 'approve it-finance-contracts', 'view_permission' => 'view it-finance', 'model' => Termination::class, 'initiator' => 'requested_by_id', 'owner' => 'contract_owner_id',
            'title' => ['termination_code', 'contract_label'], 'route' => 'finance.terminations.show', 'initiate' => 'Requesting an early termination',
            'conditions' => [
                'amount_band' => ['label' => 'Amount band (DoA, contract value)', 'values' => 'finance.doa_bands'],
                'contract_type' => ['label' => 'Contract type', 'values' => self::CONTRACT_TYPE_LABELS],
                'requires_cfo' => ['label' => 'Needs CFO (high value or after the notice deadline)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
            ],
            'default' => 'One approval by anyone with "approve it-finance-contracts" other than the requester, the contract owner and its creator.',
        ],
        'finance.expense_approval' => [
            'module' => 'IT Financial Management', 'label' => 'Expense request', 'engine' => 'generic', 'owner_cannot_approve' => true, 'owner_stage_without_permission' => true,
            'description' => 'An expense request is approved against the delegation of authority before it is committed (FIN-03.02).',
            'permission' => 'approve it-finance', 'view_permission' => 'view it-finance', 'model' => ExpenseRequest::class, 'initiator' => 'requested_by_id', 'owner' => 'endorser_id',
            'title' => ['request_code', 'title'], 'route' => 'finance.expenses.show', 'initiate' => 'Submitting an expense request',
            'conditions' => [
                'amount_band' => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
                'effective_band' => ['label' => 'DoA band after unbudgeted / over-budget escalation', 'values' => 'finance.doa_bands'],
                'capex_opex' => ['label' => 'CapEx / OpEx', 'values' => ['capex' => 'CapEx', 'opex' => 'OpEx']],
                'is_unbudgeted' => ['label' => 'Unbudgeted (no budget line)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'emergency' => ['label' => 'Emergency', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'overspend' => ['label' => 'Over the available budget', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'has_endorser' => ['label' => 'Has a cost-centre endorser', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'category_id' => ['label' => 'TBM category', 'values' => 'finance.categories'],
                'cost_centre_id' => ['label' => 'Cost centre', 'values' => 'finance.cost_centres'],
            ],
            'default' => 'One approval by anyone with "approve it-finance" other than the requester and whoever created the request.',
        ],
        'finance.invoice_approval' => [
            'module' => 'IT Financial Management', 'label' => 'Invoice approval', 'engine' => 'generic', 'owner_cannot_approve' => true, 'owner_stage_without_permission' => true,
            'description' => 'A vendor invoice is approved, after the three-way match, before the actual is posted (FIN-03.04).',
            'permission' => 'approve it-finance-invoices', 'view_permission' => 'view it-finance', 'model' => Invoice::class, 'initiator' => 'submitted_by_id', 'owner' => 'endorser_id',
            'title' => ['invoice_code', 'vendor_invoice_no'], 'route' => 'finance.invoices.show', 'initiate' => 'Submitting an invoice',
            'conditions' => [
                'amount_band' => ['label' => 'Amount band (DoA)', 'values' => 'finance.doa_bands'],
                'match_status' => ['label' => 'Three-way match', 'values' => ['matched' => 'Matched', 'variance' => 'Variance', 'override' => 'Variance overridden', 'unmatched' => 'No PO (not matched)']],
                'has_po' => ['label' => 'Has a purchase order', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'high_value' => ['label' => 'High value (invoice SoD band)', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'has_endorser' => ['label' => 'Has a cost-centre endorser', 'values' => ['yes' => 'Yes', 'no' => 'No']],
                'cost_centre_id' => ['label' => 'Cost centre', 'values' => 'finance.cost_centres'],
            ],
            'default' => 'One approval by anyone with "approve it-finance-invoices" other than the submitter, and above the SoD band not an approver of the purchase order.',
        ],
    ];

    /** @var array<string,string> Contract::TYPES humanised, for the renewal process's contract_type condition. */
    public const CONTRACT_TYPE_LABELS = [
        'licence' => 'Licence', 'subscription' => 'Subscription', 'saas' => 'SaaS', 'support_maintenance' => 'Support and maintenance',
        'managed_service' => 'Managed service', 'professional_services' => 'Professional services', 'lease' => 'Lease', 'telecom' => 'Telecom',
        'cloud_commitment' => 'Cloud commitment', 'hardware_warranty' => 'Hardware warranty', 'other' => 'Other',
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

    /**
     * Stage-condition options with per-bank value lists resolved (plan C28): a process may name a
     * marker such as `finance.doa_bands` instead of a fixed list.
     *
     * @return array<string, array{label:string, values: array<string,string>}>
     */
    public static function conditions(string $process, ?int $orgId = null): array
    {
        $out = [];
        foreach ((self::PROCESSES[$process]['conditions'] ?? []) as $field => $c) {
            $values = $c['values'];
            if (is_string($values)) {
                $values = $orgId === null ? [] : match ($values) {
                    'finance.doa_bands' => app(DoaBandService::class)->conditionValues($orgId),
                    'finance.cost_centres' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('code')->get()
                        ->mapWithKeys(fn ($c) => [(string) $c->id => $c->code.' '.$c->name])->all(),
                    'finance.categories' => Category::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('kind')->orderBy('code')->get()
                        ->mapWithKeys(fn ($c) => [(string) $c->id => $c->code.' '.$c->name])->all(),
                    default => [],
                };
            }
            $out[$field] = ['label' => $c['label'], 'values' => $values];
        }

        return $out;
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

<?php

namespace App\Policies\Finance;

use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\Benefit;
use App\Models\Finance\BenefitReading;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetComment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetScenario;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractDocument;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\ContractLink;
use App\Models\Finance\ContractTeamMember;
use App\Models\Finance\CostCentre;
use App\Models\Finance\DoaBandVersion;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\Forecast;
use App\Models\Finance\GoodsReceipt;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentChange;
use App\Models\Finance\InvestmentLink;
use App\Models\Finance\InvestmentScore;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\PeriodClose;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\RecurringExpense;
use App\Models\Finance\Renewal;
use App\Models\Finance\StageGate;
use App\Models\Finance\SupplementaryRequest;
use App\Models\Finance\Termination;
use App\Models\Finance\ValueReview;
use App\Models\Finance\VarianceNote;
use App\Models\User;
use App\Services\Finance\FinanceApprovals;
use Illuminate\Database\Eloquent\Model;

/**
 * Single policy bound to every App\Models\Finance model (ATH-FIN-001 §8), mapped in AppServiceProvider.
 * Configuration data is managed with `manage it-finance-settings`; reads need `view it-finance`.
 */
class FinancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view it-finance');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && $this->sameOrg($user, $model) && $this->costCentreVisible($user, $model);
    }

    /* Planning records (P1): budgets, lines, scenarios, comments, cycles, targets, transfers, supplementary requests. */

    public function createPlan(User $user): bool
    {
        return $user->can('create it-finance');
    }

    public function updatePlan(User $user, Model $model): bool
    {
        return $user->can('edit it-finance') && $this->sameOrg($user, $model) && $this->costCentreVisible($user, $model);
    }

    public function decidePlan(User $user, Model $model): bool
    {
        return $user->can('approve it-finance') && $this->sameOrg($user, $model);
    }

    public function baselinePlan(User $user, Budget $budget): bool
    {
        return $user->can('approve it-finance') && $this->sameOrg($user, $budget);
    }

    public function managePlanning(User $user, Model|string|null $model = null): bool
    {
        return $user->can('manage it-finance-settings') && (! $model instanceof Model || $this->sameOrg($user, $model));
    }

    public function proposeBands(User $user): bool
    {
        return $user->can('manage it-finance-settings');
    }

    public function checkBands(User $user, DoaBandVersion $version): bool
    {
        return ($user->can('approve it-finance') || $user->can('manage it-finance-settings'))
            && $this->sameOrg($user, $version) && (int) $version->proposed_by_id !== (int) $user->id;
    }

    /* Contracts and renewals (P2). */

    public function createContract(User $user): bool
    {
        return $user->can('create it-finance');
    }

    public function updateContract(User $user, Model $model): bool
    {
        return $user->can('edit it-finance') && $this->sameOrg($user, $model) && $this->costCentreVisible($user, $model);
    }

    public function decideRenewal(User $user, Renewal $renewal): bool
    {
        return $user->can('approve it-finance-contracts') && $this->sameOrg($user, $renewal);
    }

    public function decideTermination(User $user, Termination $termination): bool
    {
        return $user->can('approve it-finance-contracts') && $this->sameOrg($user, $termination);
    }

    public function terminateContract(User $user, Contract $contract): bool
    {
        return $user->can('approve it-finance-contracts') && $this->sameOrg($user, $contract) && $this->costCentreVisible($user, $contract);
    }

    /* Expenses, procurement, invoices and actuals (P3). */

    public function createExpense(User $user): bool
    {
        return $user->can('create it-finance');
    }

    public function updateExpense(User $user, Model $model): bool
    {
        return $user->can('edit it-finance') && $this->sameOrg($user, $model) && $this->costCentreVisible($user, $model)
            && ! ($model instanceof ExpenseRequest && in_array($model->status, ExpenseRequest::TERMINAL, true));
    }

    /** The approve permission, or being the named cost-centre owner of the stage now awaiting a decision (plan P3-6). */
    public function decideExpense(User $user, ExpenseRequest $expense): bool
    {
        return $this->sameOrg($user, $expense) && ($user->can('approve it-finance') || app(FinanceApprovals::class)->isCurrentOwnerApprover('finance.expense_approval', $expense, $user));
    }

    public function procure(User $user, Model $model): bool
    {
        return $user->can('create it-finance') && $this->sameOrg($user, $model);
    }

    public function captureInvoice(User $user): bool
    {
        return $user->can('create it-finance');
    }

    public function decideInvoice(User $user, Invoice $invoice): bool
    {
        return $this->sameOrg($user, $invoice) && ($user->can('approve it-finance-invoices') || app(FinanceApprovals::class)->isCurrentOwnerApprover('finance.invoice_approval', $invoice, $user));
    }

    public function payInvoice(User $user, Invoice $invoice): bool
    {
        return $user->can('approve it-finance-invoices') && $this->sameOrg($user, $invoice);
    }

    public function importActuals(User $user): bool
    {
        return $user->can('import it-finance') && $user->can('view it-finance-amounts');
    }

    public function manageAccruals(User $user): bool
    {
        return $user->can('close it-finance-periods');
    }

    public function create(User $user): bool
    {
        return $user->can('manage it-finance-settings');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can('manage it-finance-settings') && $this->sameOrg($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function closePeriod(User $user, Period $period): bool
    {
        return $user->can('close it-finance-periods') && $this->sameOrg($user, $period);
    }

    /* Monitoring, variance, period close and reports (P4). */

    public function overrideForecast(User $user, BudgetLine $line): bool
    {
        return $user->can('edit it-finance') && $user->can('view it-finance-amounts') && $this->sameOrg($user, $line) && $this->costCentreVisible($user, $line);
    }

    public function explainVariance(User $user, VarianceNote $note): bool
    {
        return $user->can('edit it-finance') && $this->sameOrg($user, $note) && $this->costCentreVisible($user, $note);
    }

    public function acknowledgeVariance(User $user, VarianceNote $note): bool
    {
        return $user->can('close it-finance-periods') && $this->sameOrg($user, $note);
    }

    public function requestReopen(User $user, Period $period): bool
    {
        return $this->closePeriod($user, $period);
    }

    public function decideReopen(User $user, PeriodClose $close): bool
    {
        return $user->can('approve it-finance') && $this->sameOrg($user, $close);
    }

    public function exportFinance(User $user): bool
    {
        return $user->can('export it-finance');
    }

    /* Investments and value realisation (P5). */

    public function createInvestment(User $user): bool
    {
        return $user->can('create it-finance');
    }

    /** Edit permission or being the investment's manager, within a visible investment. */
    public function updateInvestment(User $user, Model $model): bool
    {
        $inv = $this->investmentOf($model);

        return $inv !== null && $this->sameOrg($user, $inv) && ($user->can('edit it-finance') || (int) $inv->manager_id === (int) $user->id)
            && $user->can('view it-finance') && $this->costCentreVisible($user, $inv);
    }

    public function scoreInvestment(User $user, Investment $investment): bool
    {
        return ($user->can('edit it-finance') || $user->can('approve it-finance-investments')) && $this->sameOrg($user, $investment) && $this->costCentreVisible($user, $investment);
    }

    public function decideGate(User $user, StageGate $gate): bool
    {
        return $user->can('approve it-finance-investments') && $this->sameOrg($user, $gate);
    }

    public function recordBenefit(User $user, Benefit $benefit): bool
    {
        return $this->sameOrg($user, $benefit) && $user->can('view it-finance') && (($user->can('edit it-finance') && $this->updateInvestment($user, $benefit)) || (int) $benefit->owner_id === (int) $user->id
            || $this->updateInvestment($user, $benefit));
    }

    public function completeReview(User $user, ValueReview $review): bool
    {
        // The delivery manager never reviews his own project (PIR independence); the service also refuses the gate requester.
        $inv = $this->investmentOf($review);

        return $this->sameOrg($user, $review) && $user->can('view it-finance') && ! ($inv && (int) $inv->manager_id === (int) $user->id)
            && ($this->updateInvestment($user, $review) || (int) $review->reviewer_id === (int) $user->id);
    }

    private function investmentOf(Model $m): ?Investment
    {
        return match (true) {
            $m instanceof Investment => $m,
            $m instanceof FinanceDocument => $m->documentable()->withoutGlobalScopes()->first() instanceof Investment ? $m->documentable()->withoutGlobalScopes()->first() : null,
            isset($m->investment_id) => Investment::withoutGlobalScopes()->find($m->investment_id),
            $m instanceof BenefitReading => ($b = Benefit::withoutGlobalScopes()->find($m->benefit_id)) ? Investment::withoutGlobalScopes()->find($b->investment_id) : null,
            default => null,
        };
    }

    private function isInvestmentRecord(Model $m): bool
    {
        return $m instanceof Investment || $m instanceof BusinessCase || $m instanceof InvestmentScore || $m instanceof StageGate || $m instanceof Benefit
            || $m instanceof BenefitReading || $m instanceof ValueReview || $m instanceof InvestmentChange || $m instanceof InvestmentLink;
    }

    private function investmentVisible(User $user, Model $model): bool
    {
        $inv = $this->investmentOf($model);
        if (! $inv) {
            return false;
        }
        $ids = self::visibleCostCentreIds($user);

        return $ids === null || $user->can('approve it-finance-investments') || ($inv->cost_centre_id && in_array((int) $inv->cost_centre_id, $ids, true)) || $inv->involves($user);
    }

    /**
     * Cost-centre row restriction (§8): null means all; otherwise the ids the user owns plus every
     * descendant (possibly none). Applied to budgets, expenses and so on from P1.
     *
     * @return array<int>|null
     */
    public static function visibleCostCentreIds(User $user): ?array
    {
        if ($user->can('manage it-finance-settings') || $user->can('approve it-finance') || $user->can('close it-finance-periods')
            || $user->hasAnyRole(['Organization Admin', 'Auditor'])) {
            return null;
        }

        $all = CostCentre::withoutGlobalScopes()->where('organization_id', $user->organization_id)->get(['id', 'parent_id', 'owner_id']);
        $byParent = $all->groupBy('parent_id');
        $ids = [];
        $queue = $all->where('owner_id', $user->id)->pluck('id')->all();
        while ($queue) {
            $id = array_shift($queue);
            if (in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            foreach ($byParent->get($id, collect()) as $child) {
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /** Cost-centre row restriction for planning records; null visibility means every cost centre. */
    private function costCentreVisible(User $user, Model $model): bool
    {
        if ($model instanceof Contract || $model instanceof ContractTeamMember || $model instanceof ContractLink || $model instanceof ContractDocument
            || $model instanceof ContractEvent || $model instanceof Renewal || $model instanceof Termination) {
            return $this->contractVisible($user, $model);
        }
        if ($this->isInvestmentRecord($model)) {
            return $this->investmentVisible($user, $model);
        }
        if ($model instanceof FinanceDocument && $model->documentable_type === Investment::class) {
            return $this->investmentVisible($user, $model);
        }
        if ($this->isTransactional($model)) {
            return $this->transactionVisible($user, $model);
        }
        if ($model instanceof VarianceNote) {
            $ids = self::visibleCostCentreIds($user);

            return $ids === null || in_array((int) $model->cost_centre_id, $ids, true);
        }
        if ($model instanceof Forecast) {
            $ids = self::visibleCostCentreIds($user);

            return $ids === null || in_array((int) BudgetLine::withoutGlobalScopes()->whereKey($model->budget_line_id)->value('cost_centre_id'), $ids, true);
        }
        $planning = [Budget::class, BudgetLine::class, BudgetScenario::class, BudgetComment::class, BudgetTransfer::class, SupplementaryRequest::class];
        if (! in_array($model::class, $planning, true)) {
            return true;
        }
        $ids = self::visibleCostCentreIds($user);
        if ($ids === null) {
            return true;
        }
        $cc = match (true) {
            $model instanceof Budget, $model instanceof BudgetLine, $model instanceof SupplementaryRequest => [$model->cost_centre_id],
            $model instanceof BudgetScenario, $model instanceof BudgetComment => [Budget::withoutGlobalScopes()->whereKey($model->budget_id)->value('cost_centre_id')],
            $model instanceof BudgetTransfer => [BudgetLine::withoutGlobalScopes()->whereKey($model->from_line_id)->value('cost_centre_id'), $model->to_cost_centre_id,
                BudgetLine::withoutGlobalScopes()->whereKey($model->to_line_id)->value('cost_centre_id')],
            default => [],
        };
        if (($model instanceof BudgetTransfer || $model instanceof SupplementaryRequest) && (int) $model->requested_by_id === (int) $user->id) {
            return true;
        }

        return (bool) array_intersect(array_map('intval', array_filter($cc)), $ids);
    }

    private function isTransactional(Model $m): bool
    {
        return $m instanceof ExpenseRequest || $m instanceof PurchaseOrder || $m instanceof GoodsReceipt || $m instanceof Invoice || $m instanceof Actual
            || $m instanceof RecurringExpense || $m instanceof Accrual || $m instanceof FinanceDocument;
    }

    /** P3 records follow their cost centre; the requester and submitter always see their own; Procurement sees all procurement records (as contracts, C70). */
    private function transactionVisible(User $user, Model $model): bool
    {
        if ($model instanceof FinanceDocument) {
            $subject = $model->documentable()->withoutGlobalScopes()->first();

            return $subject !== null && $this->costCentreVisible($user, $subject);
        }
        $ids = self::visibleCostCentreIds($user);
        if ($ids === null) {
            return true;
        }
        if ($user->hasRole('Procurement Officer') && ($model instanceof ExpenseRequest || $model instanceof PurchaseOrder || $model instanceof GoodsReceipt || $model instanceof Invoice)) {
            return true;
        }
        $own = $model instanceof ExpenseRequest ? $model->requested_by_id : ($model instanceof Invoice ? $model->submitted_by_id : null);
        if ($own && (int) $own === (int) $user->id || (int) $model->created_by_id === (int) $user->id) {
            return true;
        }
        $cc = $model instanceof GoodsReceipt ? PurchaseOrder::withoutGlobalScopes()->whereKey($model->po_id)->value('cost_centre_id') : $model->cost_centre_id;

        return $cc && in_array((int) $cc, $ids, true);
    }

    /** Plan C70: every cost centre, Procurement, the contract's own people, its team, or its cost centre in the user's tree. */
    private function contractVisible(User $user, Model $model): bool
    {
        $contract = $model instanceof Contract ? $model : Contract::withoutGlobalScopes()->find($model->contract_id);
        if (! $contract) {
            return false;
        }
        $ids = self::visibleCostCentreIds($user);
        if ($ids === null || $user->hasRole('Procurement Officer')) {
            return true;
        }
        if ($contract->cost_centre_id && in_array((int) $contract->cost_centre_id, $ids, true)) {
            return true;
        }

        return $contract->involves($user);
    }

    private function sameOrg(User $user, Model $model): bool
    {
        return (int) $model->organization_id === (int) $user->organization_id;
    }
}

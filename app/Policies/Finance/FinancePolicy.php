<?php

namespace App\Policies\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetComment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetScenario;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractDocument;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\ContractLink;
use App\Models\Finance\ContractTeamMember;
use App\Models\Finance\CostCentre;
use App\Models\Finance\DoaBandVersion;
use App\Models\Finance\Period;
use App\Models\Finance\Renewal;
use App\Models\Finance\SupplementaryRequest;
use App\Models\User;
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

    public function terminateContract(User $user, Contract $contract): bool
    {
        return $user->can('approve it-finance-contracts') && $this->sameOrg($user, $contract) && $this->costCentreVisible($user, $contract);
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
            || $model instanceof ContractEvent || $model instanceof Renewal) {
            return $this->contractVisible($user, $model);
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

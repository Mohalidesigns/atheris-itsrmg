<?php

namespace App\Policies\Finance;

use App\Models\Finance\CostCentre;
use App\Models\Finance\Period;
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
        return $this->viewAny($user) && $this->sameOrg($user, $model);
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

    private function sameOrg(User $user, Model $model): bool
    {
        return (int) $model->organization_id === (int) $user->organization_id;
    }
}

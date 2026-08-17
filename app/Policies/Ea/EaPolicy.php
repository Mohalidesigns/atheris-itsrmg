<?php

namespace App\Policies\Ea;

use App\Models\User;

/**
 * EaPolicy — single policy bound to every EA model (see AppServiceProvider).
 *
 * ATH-EAR-002 §2.2 (RC-2) identified three compounding defects in the previous
 * implementation, all fixed here:
 *
 *   Defect A — the policy checked `ea.view` / `ea.write` / `ea.approve`, which
 *              RolesAndPermissionsSeeder never creates. It seeds the
 *              "{action} {module}" form: `view ea`, `create ea`, `edit ea`,
 *              `delete ea`, `approve ea`, `export ea`. The correct strings are
 *              now used.
 *   Defect B — the fallback checked roles `admin` / `architect`, neither of
 *              which exists, then fell through to `return $user->id > 0`,
 *              granting every authenticated user every EA permission. Both the
 *              phantom-role fallback and the default-allow are removed. Super
 *              Admin escalation is handled once, globally, by the `Gate::before`
 *              hook in AppServiceProvider.
 *   Defect C — the policy was never registered and the controller never called
 *              authorize(). AppServiceProvider now maps every EA model to this
 *              policy, EaController authorises every write, and every write
 *              route carries its own permission middleware.
 */
class EaPolicy
{
    public function viewAny(?User $user = null): bool { return $this->grant($user, 'view ea'); }

    public function view(?User $user, $model = null): bool { return $this->grant($user, 'view ea'); }

    public function create(?User $user = null): bool { return $this->grant($user, 'create ea'); }

    public function update(?User $user, $model = null): bool { return $this->grant($user, 'edit ea'); }

    public function delete(?User $user, $model = null): bool { return $this->grant($user, 'delete ea'); }

    public function approve(?User $user, $model = null): bool { return $this->grant($user, 'approve ea'); }

    public function export(?User $user, $model = null): bool { return $this->grant($user, 'export ea'); }

    /**
     * Administrative EA operations — feed syncs, bulk import, ArchiMate
     * exchange, MCP configuration. Deliberately gated on `delete ea`, the
     * highest-privilege catalogue grant, so only Enterprise Architects and
     * administrators can pull external data into the repository.
     */
    public function admin(?User $user = null): bool { return $this->grant($user, 'delete ea'); }

    /**
     * The single grant path. No role fallbacks, no default-allow: an absent
     * permission is a denial.
     */
    private function grant(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return (bool) $user->can($permission);
        } catch (\Throwable) {
            // Spatie not bootstrapped (e.g. a unit test with no permission
            // tables). Fail closed — the previous implementation failed open.
            return false;
        }
    }
}

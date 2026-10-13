<?php

namespace App\Http\Controllers\Change;

use App\Http\Controllers\Controller;
use App\Models\Change\ChangeApprovalStage;
use App\Models\Change\ChangeCabBoard;
use App\Models\Change\ChangeFreezeWindow;
use App\Models\Change\ChangeRequest;
use App\Models\Change\ChangeStandardTemplate;
use App\Models\User;
use App\Services\Change\ChangeApprovalRouter;
use App\Services\Change\ChangePolicySeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/** Approval policy (stages), standard change templates and freeze windows (ATH-CHG-001 §2.2, §2.5, §7). */
class ChangePolicyController extends Controller
{
    public function __construct(private ChangeApprovalRouter $router) {}

    /* ================================= Stages ================================= */

    public function policy(Request $r)
    {
        $orgId = $r->user()->organization_id;
        ChangePolicySeeder::ensureDefaults($orgId);

        return Inertia::render('Changes/Policy', [
            'stages' => ChangeApprovalStage::orderBy('sequence')->orderBy('id')->get(),
            'boards' => ChangeCabBoard::orderBy('type')->get(['id', 'name', 'type', 'quorum']),
            'roles' => Role::orderBy('name')->pluck('name'),
            'users' => User::where('organization_id', $r->user()->organization_id)->orderBy('name')->get(['id', 'name', 'job_title']),
            'matrix' => $this->router->matrix($orgId),
            'keys' => ChangeApprovalStage::KEYS,
            'approverTypes' => ChangeApprovalStage::APPROVER_TYPES,
            'canManage' => $r->user()->can('approve changes'),
        ]);
    }

    public function policyStore(Request $r)
    {
        $data = $this->validatedStage($r);
        ChangeApprovalStage::create($data + ['organization_id' => $r->user()->organization_id]);

        return back()->with('success', "Stage \"{$data['name']}\" added. It applies to changes submitted from now on.");
    }

    public function policyUpdate(Request $r, ChangeApprovalStage $stage)
    {
        $stage->update($this->validatedStage($r));

        return back()->with('success', "Stage \"{$stage->name}\" saved.");
    }

    public function policyDestroy(ChangeApprovalStage $stage)
    {
        if ($stage->key === 'change_manager') {
            return back()->with('error', 'The Change Manager authorisation stage cannot be removed; deactivate other stages instead.');
        }
        $stage->delete();

        return back()->with('success', 'Stage removed. Approvals already routed keep their snapshot.');
    }

    private function validatedStage(Request $r): array
    {
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'key' => ['required', 'string', 'max:32'],
            'sequence' => 'required|integer|min:1|max:255',
            'applies_to_types' => 'required|array|min:1',
            'applies_to_types.*' => Rule::in(ChangeRequest::TYPES),
            'min_risk_level' => ['required', Rule::in(ChangeRequest::RISK_LEVELS)],
            'approver_type' => ['required', Rule::in(ChangeApprovalStage::APPROVER_TYPES)],
            'approver_ref' => 'nullable|string|max:255',
            'quorum' => 'nullable|integer|min:1|max:20',
            'sla_hours' => 'required|integer|min:1|max:720',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['applies_to_types'] = array_values(array_unique($data['applies_to_types']));
        if ($data['approver_type'] === 'board' && ! ChangeCabBoard::where('id', (int) ($data['approver_ref'] ?? 0))->exists()) {
            throw ValidationException::withMessages(['approver_ref' => 'Pick one of this bank\'s boards.']);
        }
        if ($data['approver_type'] === 'user' && ! User::where('organization_id', $r->user()->organization_id)->where('id', (int) ($data['approver_ref'] ?? 0))->exists()) {
            throw ValidationException::withMessages(['approver_ref' => 'Pick a user in this bank.']);
        }
        if (in_array($data['approver_type'], ['role', 'job_title'], true) && blank($data['approver_ref'] ?? null)) {
            throw ValidationException::withMessages(['approver_ref' => 'Name the role or job title.']);
        }
        if ($data['approver_type'] === 'owner') {
            $data['approver_ref'] = null;
        }

        return $data;
    }

    /* =============================== Templates =============================== */

    public function templates(Request $r)
    {
        return Inertia::render('Changes/Templates', [
            'templates' => ChangeStandardTemplate::with('approvedBy:id,name')->orderBy('name')->get()
                ->map(fn (ChangeStandardTemplate $t) => $t->toArray() + ['state' => $t->state(), 'approver' => $t->approvedBy?->name]),
            'categories' => ChangeRequest::CATEGORIES,
            'canApprove' => $r->user()->can('approve changes'),
            'canEdit' => $r->user()->can('edit changes'),
        ]);
    }

    public function templatesStore(Request $r)
    {
        $data = $this->validatedTemplate($r);
        $template = ChangeStandardTemplate::create($data + ['organization_id' => $r->user()->organization_id, 'usage_count' => 0]);

        return back()->with('success', "Template \"{$template->name}\" created. It needs approval before it can be used.");
    }

    public function templatesUpdate(Request $r, ChangeStandardTemplate $template)
    {
        $data = $this->validatedTemplate($r);
        // Changing the procedure invalidates the standing approval.
        $procedureChanged = collect(['implementation_plan', 'backout_plan', 'category', 'risk_level'])
            ->contains(fn ($k) => array_key_exists($k, $data) && (string) $data[$k] !== (string) $template->{$k});
        if ($procedureChanged) {
            $data['approved_at'] = null;
            $data['approved_by_id'] = null;
        }
        $template->update($data);

        return back()->with('success', "Template \"{$template->name}\" saved.".($procedureChanged ? ' The procedure changed, so it must be approved again.' : ''));
    }

    public function templatesApprove(Request $r, ChangeStandardTemplate $template)
    {
        $data = $r->validate(['review_due_at' => 'nullable|date|after:today']);
        $template->update([
            'approved_by_id' => $r->user()->id, 'approved_at' => now(), 'is_active' => true,
            'review_due_at' => $data['review_due_at'] ?? now()->addYear()->toDateString(),
        ]);

        return back()->with('success', "Template \"{$template->name}\" approved until {$template->review_due_at->format('j M Y')}.");
    }

    private function validatedTemplate(Request $r): array
    {
        return $r->validate([
            'name' => 'required|string|max:160',
            'category' => ['required', Rule::in(ChangeRequest::CATEGORIES)],
            'description' => 'nullable|string|max:5000',
            'implementation_plan' => 'required|string|max:10000',
            'backout_plan' => 'required|string|max:10000',
            'risk_level' => ['required', Rule::in(['low', 'medium'])],
            'expected_downtime_minutes' => 'nullable|integer|min:0|max:100000',
            'review_due_at' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);
    }

    /* ============================= Freeze windows ============================= */

    public function freezeIndex(Request $r)
    {
        return Inertia::render('Changes/FreezeWindows', [
            'windows' => ChangeFreezeWindow::with('creator:id,name')->orderBy('starts_at')->get()
                ->sortBy(fn ($w) => [$w->ends_at->lt(now()) ? 1 : 0, $w->starts_at->getTimestamp()])->values()
                ->map(fn ($w) => $w->toArray() + ['is_current' => $w->isCurrent(), 'is_past' => $w->ends_at->lt(now())]),
            'categories' => ChangeRequest::CATEGORIES,
            'canManage' => $r->user()->can('approve changes'),
        ]);
    }

    public function freezeStore(Request $r)
    {
        $data = $this->validatedFreeze($r);
        $window = ChangeFreezeWindow::create($data + ['organization_id' => $r->user()->organization_id, 'created_by_id' => $r->user()->id]);

        return back()->with('success', "Freeze window \"{$window->name}\" added.");
    }

    public function freezeUpdate(Request $r, ChangeFreezeWindow $window)
    {
        $window->update($this->validatedFreeze($r));

        return back()->with('success', "Freeze window \"{$window->name}\" saved.");
    }

    public function freezeDestroy(ChangeFreezeWindow $window)
    {
        $window->delete();

        return back()->with('success', 'Freeze window removed.');
    }

    private function validatedFreeze(Request $r): array
    {
        $data = $r->validate([
            'name' => 'required|string|max:160',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'scope' => ['required', Rule::in(['all', 'categories'])],
            'categories' => 'nullable|array',
            'categories.*' => Rule::in(ChangeRequest::CATEGORIES),
            'reason' => 'nullable|string|max:2000',
            'allow_emergency' => 'nullable|boolean',
        ]);
        $data['allow_emergency'] = (bool) ($data['allow_emergency'] ?? true);
        $data['categories'] = $data['scope'] === 'categories' ? array_values(array_unique($data['categories'] ?? [])) : null;
        if ($data['scope'] === 'categories' && empty($data['categories'])) {
            throw ValidationException::withMessages(['categories' => 'Pick at least one category, or freeze all changes.']);
        }

        return $data;
    }
}

<?php

namespace App\Services\Finance;

use App\Models\Evidence;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractDocument;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\ContractLink;
use App\Models\Finance\ContractTeamMember;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Renewal;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The contract register (FIN-06.01 to .03, .07, .08): creation with FX conversion and computed
 * window/notice dates, team, links, documents in the evidence vault, early termination and the
 * vendor summary hand-off to Vendor Management (plan C68).
 */
class ContractService
{
    use LogsFinanceEvents;

    /** Attributes a request may set on a contract (everything else is system-managed). */
    private const FILLABLE = [
        'title', 'type', 'vendor_id', 'legal_entity_id', 'cost_centre_id', 'budget_line_id', 'category_id', 'owner_id', 'technical_owner_id',
        'procurement_contact_id', 'description', 'commenced_on', 'ends_on', 'term_months', 'notice_period_days', 'renewal_type', 'auto_renew',
        'renewal_lead_days', 'notification_offsets', 'currency', 'contract_value', 'billing_frequency', 'amount_per_billing', 'licence_metric',
        'licence_quantity', 'deployed_quantity', 'payment_terms_days', 'price_escalation_pct', 'data_processing_role', 'dpa_status',
        'sla_summary', 'exit_terms', 'key_clauses', 'external_reference',
    ];

    private const STEP_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12];

    public function __construct(
        private readonly ContractStatusDeriver $deriver,
        private readonly CurrencyService $fx,
        private readonly FinanceSettings $settings,
        private readonly FinanceApprovals $approvals,
    ) {}

    public function create(array $data, User $by): Contract
    {
        $orgId = (int) $by->organization_id;
        $attrs = $this->normalise($orgId, $data, null, $by);

        return DB::transaction(function () use ($orgId, $attrs, $by, $data) {
            $c = new Contract($attrs);
            $c->forceFill([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'contract_code' => Contract::generateNextCode($orgId), 'status' => 'draft',
            ]);
            $this->computeDates($c);

            return $this->persistNew($c, $by, ! empty($data['activate']));
        });
    }

    private function persistNew(Contract $c, User $by, bool $activate): Contract
    {
        $c->save();
        ContractEvent::record($c, 'created', ['code' => $c->contract_code], $by);
        $this->audit($c, $by, 'created', "Contract {$c->contract_code} created");
        $activate && $this->activate($c, $by);
        $this->syncVendorSummary($c->vendor_id);

        return $c->refresh();
    }

    public function update(Contract $c, array $data, User $by): Contract
    {
        $c->is_terminal && $this->fail('Renewed, terminated and replaced contracts are read-only; edit the successor.');
        $attrs = $this->normalise((int) $c->organization_id, $data, $c, $by);
        $this->assertNotLockedByRenewal($c, $attrs);

        return DB::transaction(function () use ($c, $attrs, $by) {
            $oldVendor = $c->vendor_id;
            $c->fill($attrs);
            $this->computeDates($c);
            $c->save();
            $change = $this->deriver->apply($c, FinanceClock::today(), $by);
            $this->audit($c, $by, 'updated', "Contract {$c->contract_code} updated", $change ?? []);
            $this->syncVendorSummary($c->vendor_id);
            $oldVendor !== $c->vendor_id && $this->syncVendorSummary($oldVendor);

            return $c->refresh();
        });
    }

    /** Only a draft contract can be deleted. */
    public function delete(Contract $c, User $by): void
    {
        $c->status === 'draft' || $this->fail('Only a draft contract can be deleted.');
        $this->audit($c, $by, 'deleted', "Draft contract {$c->contract_code} deleted");
        $c->delete();
    }

    public function activate(Contract $c, ?User $by): Contract
    {
        $c->status === 'draft' || $this->fail("A {$c->status} contract cannot be activated.");
        foreach (['vendor_id' => 'a vendor', 'owner_id' => 'an owner', 'commenced_on' => 'a commencement date', 'ends_on' => 'an end date'] as $col => $what) {
            $c->{$col} || $this->fail("Give the contract {$what} before activating it.");
        }
        DB::transaction(function () use ($c, $by) {
            $c->forceFill(['status' => 'active'])->save();
            ContractEvent::record($c, 'status_changed', ['from' => 'draft', 'to' => 'active'], $by);
            $this->audit($c, $by, 'activated', "Contract {$c->contract_code} activated");
            $this->deriver->apply($c, FinanceClock::today(), $by);
            $this->syncVendorSummary($c->vendor_id);
        });

        return $c->refresh();
    }

    /**
     * Notice deadline = end - notice period; the renewal window opens `lead days` BEFORE that deadline, so the team has
     * the full lead time to decide. A changed end or deadline re-arms the reminder series and is logged on the timeline.
     */
    public function computeDates(Contract $c): void
    {
        $ends = CarbonImmutable::parse($c->ends_on->toDateString());
        $notice = $ends->subDays((int) $c->notice_period_days);
        $c->notice_deadline_on = $notice->toDateString();
        $c->renewal_window_opens_on = $notice->subDays($c->effective_lead_days)->toDateString();

        if ($c->exists && ($c->isDirty('ends_on') || $c->isDirty('notice_deadline_on'))) {
            $c->forceFill(['escalated_at' => null, 'last_notified_offset' => null]);
            ContractEvent::record($c, 'dates_changed', [
                'ends_on' => ['from' => $c->getOriginal('ends_on') ? CarbonImmutable::parse($c->getOriginal('ends_on'))->toDateString() : null, 'to' => $c->ends_on->toDateString()],
                'notice_deadline_on' => ['from' => $c->getOriginal('notice_deadline_on') ? CarbonImmutable::parse($c->getOriginal('notice_deadline_on'))->toDateString() : null, 'to' => $c->notice_deadline_on->toDateString()],
            ], null);
        }
    }

    /** Terms that an in-flight (submitted or approved) renewal decision was made on cannot move under it. */
    private function assertNotLockedByRenewal(Contract $c, array $attrs): void
    {
        if (! Renewal::withoutGlobalScopes()->where('contract_id', $c->id)->whereIn('status', ['submitted', 'approved'])->exists()) {
            return;
        }
        $changed = [];
        foreach (['owner_id', 'notice_period_days', 'renewal_type', 'renewal_lead_days'] as $col) {
            array_key_exists($col, $attrs) && (string) $attrs[$col] !== (string) $c->{$col} && $changed[] = $col;
        }
        foreach (['commenced_on', 'ends_on'] as $col) {
            array_key_exists($col, $attrs) && $attrs[$col] !== $c->{$col}?->toDateString() && $changed[] = $col;
        }
        array_key_exists('auto_renew', $attrs) && (bool) $attrs['auto_renew'] !== (bool) $c->auto_renew && $changed[] = 'auto_renew';
        array_key_exists('price_escalation_pct', $attrs) && bccomp((string) ($attrs['price_escalation_pct'] ?? '0'), (string) ($c->price_escalation_pct ?? '0'), 4) !== 0 && $changed[] = 'price_escalation_pct';
        array_key_exists('contract_value', $attrs) && $changed[] = 'contract_value'; // normalise() only emits money when the value or currency changed
        $changed && $this->fail('These terms are locked while a renewal is awaiting or has passed approval: '.implode(', ', $changed).'. Withdraw the renewal first.', $changed[0]);
    }

    /** @return array{from:string,to:string}|null */
    public function recompute(Contract $c, ?User $by = null): ?array
    {
        $this->computeDates($c);
        $c->save();

        return $this->deriver->apply($c, FinanceClock::today(), $by);
    }

    public function addTeamMember(Contract $c, int $userId, string $role, User $by): ContractTeamMember
    {
        in_array($role, ContractTeamMember::ROLES, true) || $this->fail('Unknown team role.', 'role');
        $this->orgUser((int) $c->organization_id, $userId) || $this->fail('Pick a user of this bank.', 'user_id');
        ContractTeamMember::withoutGlobalScopes()->where('contract_id', $c->id)->where('user_id', $userId)->exists() && $this->fail('That person is already on the team.', 'user_id');
        $m = ContractTeamMember::withoutGlobalScopes()->create([
            'organization_id' => $c->organization_id, 'created_by_id' => $by->id, 'contract_id' => $c->id, 'user_id' => $userId, 'role' => $role,
        ]);
        ContractEvent::record($c, 'team_changed', ['action' => 'added', 'user_id' => $userId, 'role' => $role], $by);
        $this->audit($c, $by, 'team_added', "Team member added to {$c->contract_code}", ['user_id' => $userId, 'role' => $role]);

        return $m;
    }

    public function removeTeamMember(ContractTeamMember $m, User $by): void
    {
        $c = Contract::withoutGlobalScopes()->findOrFail($m->contract_id);
        $m->delete();
        ContractEvent::record($c, 'team_changed', ['action' => 'removed', 'user_id' => $m->user_id], $by);
        $this->audit($c, $by, 'team_removed', "Team member removed from {$c->contract_code}", ['user_id' => $m->user_id]);
    }

    public function addLink(Contract $c, string $type, int $id, ?string $note, User $by): ContractLink
    {
        $class = ContractLink::TYPES[$type] ?? $this->fail('Unknown link type.', 'type');
        $orgId = (int) $c->organization_id;
        $shared = in_array($type, ['ea_application', 'business_service'], true); // platform-shared reference rows have a NULL organization
        $exists = $class::withoutGlobalScopes()->whereKey($id)
            ->where(fn ($q) => $shared ? $q->where('organization_id', $orgId)->orWhereNull('organization_id') : $q->where('organization_id', $orgId))->exists();
        $exists || $this->fail('That record does not exist in this bank.', 'id');
        ContractLink::withoutGlobalScopes()->where('contract_id', $c->id)->where('linkable_type', $class)->where('linkable_id', $id)->exists()
            && $this->fail('That record is already linked.', 'id');
        $l = ContractLink::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'contract_id' => $c->id, 'linkable_type' => $class, 'linkable_id' => $id, 'note' => $note ? Str::limit($note, 255, '') : null,
        ]);
        ContractEvent::record($c, 'link_changed', ['action' => 'added', 'type' => $type, 'id' => $id], $by);
        $this->audit($c, $by, 'link_added', "Link added to {$c->contract_code}", ['type' => $type, 'id' => $id]);

        return $l;
    }

    public function removeLink(ContractLink $l, User $by): void
    {
        $c = Contract::withoutGlobalScopes()->findOrFail($l->contract_id);
        $l->delete();
        ContractEvent::record($c, 'link_changed', ['action' => 'removed', 'type' => $l->type_key, 'id' => $l->linkable_id], $by);
        $this->audit($c, $by, 'link_removed', "Link removed from {$c->contract_code}", ['type' => $l->type_key, 'id' => $l->linkable_id]);
    }

    /** Stores the file in the evidence vault (hashed) and attaches it to the contract. */
    public function addDocument(Contract $c, array $data, ?UploadedFile $file, User $by): ContractDocument
    {
        in_array($data['kind'] ?? null, ContractDocument::KINDS, true) || $this->fail('Unknown document kind.', 'kind');
        $title = trim((string) ($data['title'] ?? ''));
        ($title === '' || mb_strlen($title) > 200) && $this->fail('Give the document a title (max 200 characters).', 'title');
        $orgId = (int) $c->organization_id;

        return DB::transaction(function () use ($c, $data, $file, $by, $title, $orgId) {
            $path = $name = $mime = $size = $sha = null;
            if ($file) {
                $name = $file->getClientOriginalName();
                $mime = $file->getClientMimeType();
                $size = $file->getSize();
                $sha = hash_file('sha256', $file->getRealPath());
                $path = $file->storeAs("evidence/{$orgId}/contracts", Str::random(40).'.'.($file->getClientOriginalExtension() ?: 'bin'), 'local');
            }
            $evidence = Evidence::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'evidenceable_type' => Contract::class, 'evidenceable_id' => $c->id, 'title' => $title,
                'description' => "Contract document ({$data['kind']}) for {$c->contract_code}", 'type' => 'document', 'file_path' => $path, 'file_name' => $name,
                'file_size' => $size, 'mime_type' => $mime, 'status' => 'approved', 'uploaded_by' => $by->id,
                'valid_from' => $data['signed_on'] ?? null, 'valid_until' => $data['expires_on'] ?? null,
            ]);
            $doc = ContractDocument::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'contract_id' => $c->id, 'evidence_id' => $evidence->id, 'kind' => $data['kind'],
                'title' => $title, 'sha256' => $sha, 'signed_on' => $data['signed_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null,
            ]);
            ContractEvent::record($c, 'document_added', ['document_id' => $doc->id, 'kind' => $doc->kind, 'title' => $title, 'sha256' => $sha], $by);
            $this->audit($c, $by, 'document_added', "Document added to {$c->contract_code}", ['title' => $title, 'sha256' => $sha]);

            return $doc;
        });
    }

    /**
     * Validates an early-termination request and returns the normalised date (plan C65, H2): renewable status, a reason,
     * segregation of duties and the date bounds. Applying the termination is {@see applyTermination}, on final approval.
     */
    public function assertTerminationAllowed(Contract $c, string $reason, ?string $terminatedOn, User $by): string
    {
        in_array($c->status, Contract::RENEWABLE, true) || $this->fail("A {$c->status} contract cannot be terminated.");
        blank(trim($reason)) && $this->fail('Give a reason for the termination.', 'reason');
        $this->assertCanTerminate($c, $by);
        $on = $terminatedOn ? CarbonImmutable::parse($terminatedOn)->toDateString() : FinanceClock::today()->toDateString();
        $floor = $this->terminationFloor($c);
        $on < $floor && $this->fail("The termination date cannot be before {$floor}.", 'terminated_on');
        $on > $c->ends_on->toDateString() && $this->fail('The termination date cannot be after the contract end date ('.$c->ends_on->toDateString().').', 'terminated_on');

        return $on;
    }

    public function terminationFloor(Contract $c): string
    {
        return max($c->commenced_on->toDateString(), FinanceClock::today()->subDays((int) config('finance.contracts.terminate_backdate_days', 30))->toDateString());
    }

    /** Closes the contract on its approved early termination: withdraws open renewals, records the decision. Run inside the approval transaction. */
    public function applyTermination(Contract $c, string $reason, string $on, User $by, array $extra = []): Contract
    {
        $c = Contract::withoutGlobalScopes()->lockForUpdate()->findOrFail($c->id);
        in_array($c->status, Contract::RENEWABLE, true) || $this->fail("A {$c->status} contract cannot be terminated.");
        foreach (Renewal::withoutGlobalScopes()->where('contract_id', $c->id)->whereIn('status', Renewal::ACTIVE)->get() as $r) {
            app(RenewalService::class)->withdrawForClosure($r, $by);
        }
        $c->forceFill([
            'status' => 'terminated', 'terminated_on' => $on, 'termination_reason' => trim($reason),
            'decision' => 'terminate', 'decision_at' => now(), 'decision_by_id' => $by->id,
        ])->save();
        ContractEvent::record($c, 'terminated', ['reason' => trim($reason), 'terminated_on' => $on] + $extra, $by);
        $this->audit($c, $by, 'terminated', "Contract {$c->contract_code} terminated", ['terminated_on' => $on]);
        $this->syncVendorSummary($c->vendor_id);

        return $c->refresh();
    }

    /** Minimal segregation of duties: the owner, the creator and whoever recommended or requested an open renewal cannot terminate. */
    public function assertCanTerminate(Contract $c, User $by): void
    {
        $id = (int) $by->id;
        $parties = Renewal::withoutGlobalScopes()->where('contract_id', $c->id)->whereIn('status', Renewal::ACTIVE)->get()
            ->flatMap(fn (Renewal $r) => [$r->requested_by_id, $r->recommended_by_id])->filter()->map(fn ($v) => (int) $v);
        if ((int) $c->owner_id === $id || (int) $c->created_by_id === $id || $parties->contains($id)) {
            $this->fail('The contract owner, its creator and the requester or recommender of an open renewal cannot terminate it (segregation of duties). Ask another authorised person.', 'terminated_on');
        }
    }

    public function event(Contract $c, string $type, array $payload = [], ?User $user = null, ?CarbonInterface $at = null): ContractEvent
    {
        return ContractEvent::record($c, $type, $payload, $user, $at);
    }

    /** Team, owner, technical owner and procurement contact: active users of the contract's bank, once each. */
    public function recipients(Contract $c): Collection
    {
        $ids = ContractTeamMember::withoutGlobalScopes()->where('contract_id', $c->id)->pluck('user_id')
            ->merge([$c->owner_id, $c->technical_owner_id, $c->procurement_contact_id])->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return User::withoutGlobalScopes()->where('organization_id', $c->organization_id)->where('is_active', true)->whereIn('id', $ids)->get()->values();
    }

    public function financeUsers(int $orgId): Collection
    {
        $roles = config('finance.contracts.finance_roles', []);
        if (! $roles) {
            return collect();
        }

        return User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->role($roles)->get()->values();
    }

    /** @return array<int, array{due_on:string,amount:string,base_amount:string}> */
    public function billingSchedule(Contract $c, int $n = 12): array
    {
        $step = self::STEP_MONTHS[$c->billing_frequency] ?? null;
        if (! $step) {
            return [];
        }
        $start = CarbonImmutable::parse($c->commenced_on->toDateString());
        $end = $c->ends_on->toDateString();
        $periods = max(1, (int) ceil(max((int) $c->term_months, 1) / $step));
        $amount = $c->amount_per_billing !== null ? (string) $c->amount_per_billing : bcadd(bcdiv((string) $c->contract_value, (string) $periods, 8), '0.005', 2);
        $today = FinanceClock::today()->toDateString();
        $out = [];
        for ($i = 0; $i < 600 && count($out) < $n; $i++) {
            $due = $start->addMonthsNoOverflow($i * $step)->toDateString();
            if ($due > $end) {
                break;
            }
            if ($due >= $today) {
                $out[] = ['due_on' => $due, 'amount' => $amount, 'base_amount' => bcadd(bcmul($amount, (string) $c->fx_rate, 8), '0.005', 2)];
            }
        }

        return $out;
    }

    /** Vendor Management keeps a summary of the vendor's primary live finance contract (plan C68). */
    public function syncVendorSummary(?int $vendorId): void
    {
        if (! $vendorId) {
            return;
        }
        $primary = Contract::withoutGlobalScopes()->where('vendor_id', $vendorId)->whereIn('status', Contract::LIVE)
            ->orderByDesc('base_contract_value')->orderBy('id')->first();
        if (! $primary) {
            return;
        }
        $vendor = Vendor::withoutGlobalScopes()->where('organization_id', $primary->organization_id)->find($vendorId);
        $vendor?->forceFill([
            'contract_start' => $primary->commenced_on->toDateString(), 'contract_end' => $primary->ends_on->toDateString(),
            'contract_value' => $primary->contract_value, 'contract_currency' => $primary->currency,
        ])->save();
    }

    /**
     * Validates and normalises request data into contract attributes (FX conversion, defaults, same-bank FKs).
     *
     * @return array<string,mixed>
     */
    private function normalise(int $orgId, array $data, ?Contract $existing, ?User $by = null): array
    {
        $in = Arr::only($data, self::FILLABLE);
        $isNew = $existing === null;
        $get = fn (string $k, $default = null) => array_key_exists($k, $in) ? $in[$k] : ($existing?->{$k} ?? $default);

        if ($isNew || array_key_exists('title', $in)) {
            $title = trim((string) ($in['title'] ?? ''));
            ($title === '' || mb_strlen($title) > 200) && $this->fail('Give the contract a title (max 200 characters).', 'title');
            $in['title'] = $title;
        }
        if ($isNew || array_key_exists('type', $in)) {
            in_array($in['type'] ?? null, Contract::TYPES, true) || $this->fail('Pick a contract type.', 'type');
        }
        foreach (['renewal_type' => Contract::RENEWAL_TYPES, 'billing_frequency' => Contract::BILLING, 'data_processing_role' => Contract::DP_ROLES, 'dpa_status' => Contract::DPA] as $col => $allowed) {
            if (array_key_exists($col, $in) && ! in_array($in[$col], $allowed, true)) {
                $this->fail("Unknown {$col}.", $col);
            }
        }
        if (! empty($in['licence_metric']) && ! in_array($in['licence_metric'], Contract::LICENCE_METRICS, true)) {
            $this->fail('Unknown licence metric.', 'licence_metric');
        }

        $fk = [
            'vendor_id' => Vendor::class, 'legal_entity_id' => LegalEntity::class, 'cost_centre_id' => CostCentre::class,
            'budget_line_id' => BudgetLine::class, 'category_id' => Category::class,
        ];
        foreach ($fk as $col => $class) {
            if (! empty($in[$col]) && ! $class::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey((int) $in[$col])->exists()) {
                $this->fail('That selection does not belong to this bank.', $col);
            }
            array_key_exists($col, $in) && ($in[$col] = $in[$col] ?: null);
        }
        if ($by) { // cost centres (and the budget lines under them) the actor cannot see cannot be attached
            $visible = FinancePolicy::visibleCostCentreIds($by);
            if ($visible !== null) {
                $ccId = ! empty($in['cost_centre_id']) ? (int) $in['cost_centre_id'] : null;
                if ($ccId && $ccId !== (int) $existing?->cost_centre_id && ! in_array($ccId, $visible, true)) {
                    $this->fail('You cannot attach a cost centre outside your access.', 'cost_centre_id');
                }
                $blId = ! empty($in['budget_line_id']) ? (int) $in['budget_line_id'] : null;
                if ($blId && $blId !== (int) $existing?->budget_line_id) {
                    $lineCc = (int) BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($blId)->value('cost_centre_id');
                    ! in_array($lineCc, $visible, true) && $this->fail('You cannot attach a budget line outside your access.', 'budget_line_id');
                }
            }
        }
        foreach (['owner_id', 'technical_owner_id', 'procurement_contact_id'] as $col) {
            if (! empty($in[$col]) && ! $this->orgUser($orgId, (int) $in[$col])) {
                $this->fail('Pick a user of this bank.', $col);
            }
            array_key_exists($col, $in) && ($in[$col] = $in[$col] ?: null);
        }

        $commenced = $get('commenced_on');
        $ends = $get('ends_on');
        ($commenced && $ends) || $this->fail('Give the commencement and end dates.', $commenced ? 'ends_on' : 'commenced_on');
        $c = CarbonImmutable::parse($commenced instanceof CarbonInterface ? $commenced->toDateString() : $commenced);
        $e = CarbonImmutable::parse($ends instanceof CarbonInterface ? $ends->toDateString() : $ends);
        $e->lt($c) && $this->fail('The end date must be on or after the commencement date.', 'ends_on');
        $in['commenced_on'] = $c->toDateString();
        $in['ends_on'] = $e->toDateString();

        if ($isNew && empty($in['term_months'])) {
            $in['term_months'] = $this->monthsBetween($c, $e);
        } elseif (! $isNew && (array_key_exists('commenced_on', $in) || array_key_exists('ends_on', $in)) && empty($data['term_months'])) {
            $in['term_months'] = $this->monthsBetween($c, $e);
        }
        $in['term_months'] = max(1, (int) ($in['term_months'] ?? $existing?->term_months ?? 1));
        $termDays = (int) $c->diffInDays($e) + 1;
        if ($isNew && ! array_key_exists('notice_period_days', $in)) {
            $notice = min((int) config('finance.contracts.default_notice_period_days'), $termDays); // the default never exceeds a short term
        } else {
            $notice = (int) $get('notice_period_days', 0);
            $notice > $termDays && $this->fail('The notice period cannot be longer than the contract term.', 'notice_period_days');
        }
        $in['notice_period_days'] = $notice;

        foreach (['notification_offsets'] as $col) {
            if (array_key_exists($col, $in)) {
                $in[$col] = $in[$col] ? array_values(array_unique(array_map('intval', (array) $in[$col]))) : null;
            }
        }
        if (array_key_exists('auto_renew', $in)) {
            $in['auto_renew'] = (bool) $in['auto_renew'];
        }

        // Money: re-quote at today's spot rate whenever the value or the currency changed (plan C58).
        $currency = strtoupper((string) ($in['currency'] ?? $existing?->currency ?? $this->settings->baseCurrency($orgId)));
        $value = (string) ($in['contract_value'] ?? $existing?->contract_value ?? '0');
        $changed = $isNew || (array_key_exists('currency', $in) && strtoupper((string) $in['currency']) !== $existing->currency)
            || (array_key_exists('contract_value', $in) && bccomp($value, (string) $existing->contract_value, 2) !== 0);
        if ($changed) {
            (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $value)) && $this->fail('The contract value must be a positive amount with up to 2 decimals.', 'contract_value');
            $conv = $this->fx->convert($orgId, $value, $currency, FinanceClock::today(), 'spot');
            $in['currency'] = $currency;
            $in['contract_value'] = $conv['amount'];
            $in['fx_rate'] = $conv['fx_rate'];
            $in['fx_rate_type'] = $conv['rate_type'];
            $in['fx_rate_date'] = $conv['rate_date'];
            $in['base_contract_value'] = $conv['base_amount'];
        }

        return $in;
    }

    private function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $end = $to->addDay();
        $m = (int) floor($from->diffInMonths($end));
        $from->addMonths($m)->lt($end) && $m++;

        return max(1, $m);
    }

    private function orgUser(int $orgId, int $userId): bool
    {
        return User::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($userId)->exists();
    }
}

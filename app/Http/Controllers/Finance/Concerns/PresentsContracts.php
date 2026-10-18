<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\Finance\Contract;
use App\Models\Finance\ContractDocument;
use App\Models\Finance\ContractEvent;
use App\Models\Finance\ContractLink;
use App\Models\Finance\ContractTeamMember;
use App\Models\Finance\Renewal;
use App\Models\User;
use App\Services\Approvals\ApprovalProcesses;
use App\Services\Finance\FinanceClock;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Shapes shared by the contract, renewal and calendar pages (props contract, plan §7). Decimals stay
 * strings; without `view it-finance-amounts` every amount is removed server-side. Self-contained on
 * purpose: it must not be combined with PresentsBudgets (method names collide).
 */
trait PresentsContracts
{
    private const CONTRACT_MONEY_KEYS = [
        'contract_value', 'base_contract_value', 'amount_per_billing', 'annualised_base_value', 'next_term_forecast', 'proposed_value',
        'proposed_base_value', 'base_value', 'amount', 'base_amount', 'current_base_amount', 'amount_band', 'amount_band_label',
        'value_base_total', 'annualised_base_total', 'fx_rate', 'proposed_fx_rate',
        'expense_actual_base', 'actual_to_date', 'value_band', 'uplift_pct', 'uplift_base_pct', 'fx_effect_pct', 'uplift_band', 'requires_cfo', 'price_escalation_pct',
    ];

    private const CONTRACT_MONEY_LISTS = ['billing', 'vendorSpend'];

    /** Renders a contracts page; without `view it-finance-amounts` every amount is removed server-side. */
    protected function renderContracts(string $component, array $props)
    {
        $can = request()->user()->can('view it-finance-amounts');
        $props['can'] = array_merge((array) ($props['can'] ?? []), ['viewAmounts' => $can]);
        if (! $can) {
            $props = $this->maskContractMoney(json_decode(json_encode($props), true));
        }

        return Inertia::render($component, $props);
    }

    protected function maskContractMoney(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::CONTRACT_MONEY_LISTS, true)) {
                $data[$k] = [];
            } elseif ($k === 'band' && is_array($v)) {
                $data[$k] = ['label' => null, 'min' => null, 'max' => null];
            } elseif (is_string($k) && in_array($k, self::CONTRACT_MONEY_KEYS, true) && ! is_array($v)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->maskContractMoney($v);
            }
        }

        return $data;
    }

    protected function userMini(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }

    /** @return array<string,mixed> */
    protected function contractRow(Contract $c): array
    {
        $c->loadMissing(['vendor:id,name', 'owner:id,name', 'technicalOwner:id,name', 'costCentre:id,code,name', 'openRenewal']);

        return [
            'id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title, 'type' => $c->type, 'status' => $c->status,
            'vendor' => $c->vendor ? ['id' => $c->vendor->id, 'name' => $c->vendor->name] : null,
            'owner' => $this->userMini($c->owner), 'technical_owner' => $this->userMini($c->technicalOwner),
            'cost_centre' => $c->costCentre ? ['id' => $c->costCentre->id, 'code' => $c->costCentre->code, 'name' => $c->costCentre->name] : null,
            'commenced_on' => $c->commenced_on?->toDateString(), 'ends_on' => $c->ends_on?->toDateString(), 'term_months' => $c->term_months,
            'notice_period_days' => $c->notice_period_days, 'renewal_window_opens_on' => $c->renewal_window_opens_on?->toDateString(),
            'notice_deadline_on' => $c->notice_deadline_on?->toDateString(), 'days_to_end' => $c->days_to_end, 'days_to_notice' => $c->days_to_notice,
            'renewal_type' => $c->renewal_type, 'auto_renew' => $c->auto_renew, 'is_auto' => $c->is_auto, 'currency' => $c->currency,
            'contract_value' => (string) $c->contract_value, 'base_contract_value' => (string) $c->base_contract_value,
            'annualised_base_value' => $c->annualised_base_value, 'amount_band' => $c->amount_band, 'amount_band_label' => $c->amount_band_label,
            'decision' => $c->decision,
            'open_renewal' => $c->openRenewal ? ['id' => $c->openRenewal->id, 'renewal_code' => $c->openRenewal->renewal_code, 'status' => $c->openRenewal->status] : null,
            'in_passed' => $this->inPassed($c), 'licence_position' => $c->licence_position,
        ];
    }

    protected function inPassed(Contract $c): bool
    {
        if ($c->decision !== null || $c->status === 'draft' || $c->is_terminal) {
            return false;
        }
        $today = FinanceClock::today()->toDateString();

        return $c->notice_deadline_on->toDateString() < $today || $c->ends_on->toDateString() < $today;
    }

    /** @return array<string,mixed> */
    protected function contractFull(Contract $c): array
    {
        $c->loadMissing(['legalEntity:id,code,name', 'category:id,code,name', 'procurementContact:id,name', 'budgetLine', 'renewedFrom:id,contract_code,title', 'renewedTo:id,contract_code,title', 'decisionBy:id,name']);
        $mini = fn ($x) => $x ? ['id' => $x->id, 'code' => $x->code, 'name' => $x->name] : null;
        $cm = fn ($x) => $x ? ['id' => $x->id, 'contract_code' => $x->contract_code, 'title' => $x->title] : null;
        $line = $c->budgetLine;

        return $this->contractRow($c) + [
            'legal_entity' => $mini($c->legalEntity), 'category' => $mini($c->category), 'procurement_contact' => $this->userMini($c->procurementContact),
            'budget_line' => $line ? ['id' => $line->id, 'budget_id' => $line->budget_id, 'label' => "#{$line->line_no} {$line->description}", 'current_base_amount' => (string) $line->current_base_amount] : null,
            'description' => $c->description, 'renewal_lead_days' => $c->renewal_lead_days, 'effective_lead_days' => $c->effective_lead_days,
            'notification_offsets' => $c->notification_offsets, 'effective_offsets' => $c->effective_offsets, 'fx_rate' => (string) $c->fx_rate,
            'fx_rate_date' => $c->fx_rate_date?->toDateString(), 'billing_frequency' => $c->billing_frequency, 'amount_per_billing' => $c->amount_per_billing === null ? null : (string) $c->amount_per_billing,
            'licence_metric' => $c->licence_metric, 'licence_quantity' => $c->licence_quantity === null ? null : (string) $c->licence_quantity,
            'deployed_quantity' => $c->deployed_quantity === null ? null : (string) $c->deployed_quantity, 'payment_terms_days' => $c->payment_terms_days,
            'price_escalation_pct' => $c->price_escalation_pct === null ? null : (string) $c->price_escalation_pct, 'next_term_forecast' => $c->next_term_forecast,
            'data_processing_role' => $c->data_processing_role, 'dpa_status' => $c->dpa_status, 'sla_summary' => $c->sla_summary, 'exit_terms' => $c->exit_terms,
            'key_clauses' => $c->key_clauses ?? [], 'external_reference' => $c->external_reference,
            'lease_assessment' => $c->lease_assessment, 'lease_assessment_note' => $c->lease_assessment_note, 'is_ifrs16_lease' => $c->is_ifrs16_lease, 'needs_lease_assessment' => $c->needs_lease_assessment,
            'renewed_from' => $cm($c->renewedFrom), 'renewed_to' => $cm($c->renewedTo),
            'decision_at' => $c->decision_at?->toIso8601String(), 'decision_by' => $this->userMini($c->decisionBy),
            'terminated_on' => $c->terminated_on?->toDateString(), 'termination_reason' => $c->termination_reason,
            'last_notified_offset' => $c->last_notified_offset, 'escalated_at' => $c->escalated_at?->toIso8601String(), 'days_to_window' => $c->days_to_window,
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    protected function renewalSummary(Renewal $r): array
    {
        $r->loadMissing(['contract.vendor:id,name', 'contract.owner:id,name', 'requestedBy:id,name', 'approvedBy:id,name', 'executedBy:id,name', 'newContract:id,contract_code,title']);
        $c = $r->contract;

        return [
            'id' => $r->id, 'renewal_code' => $r->renewal_code, 'status' => $r->status, 'recommendation' => $r->recommendation,
            'opened_on' => $r->opened_on?->toDateString(), 'justification' => $r->justification, 'benchmark_notes' => $r->benchmark_notes,
            'alternatives_considered' => $r->alternatives_considered, 'proposed_term_months' => $r->proposed_term_months,
            'proposed_commences_on' => $r->proposed_commences_on?->toDateString(), 'proposed_value' => $r->proposed_value === null ? null : (string) $r->proposed_value,
            'proposed_currency' => $r->proposed_currency, 'proposed_base_value' => $r->proposed_base_value === null ? null : (string) $r->proposed_base_value,
            'uplift_pct' => $r->uplift_pct === null ? null : (string) $r->uplift_pct,
            'uplift_base_pct' => $r->uplift_base_pct === null ? null : (string) $r->uplift_base_pct, 'fx_effect_pct' => $r->fx_effect_pct === null ? null : (string) $r->fx_effect_pct, 'uplift_band' => $r->uplift_band,
            'amount_band' => $r->amount_band, 'amount_band_label' => $r->amount_band_label, 'requires_cfo' => $r->requires_cfo, 'is_auto' => $r->is_auto,
            'requested_by' => $this->userMini($r->requestedBy), 'submitted_at' => $r->submitted_at?->toIso8601String(),
            'approved_by' => $this->userMini($r->approvedBy), 'approved_at' => $r->approved_at?->toIso8601String(), 'rejection_reason' => $r->rejection_reason,
            'executed_by' => $this->userMini($r->executedBy), 'executed_at' => $r->executed_at?->toIso8601String(),
            'new_contract' => $r->newContract ? ['id' => $r->newContract->id, 'contract_code' => $r->newContract->contract_code, 'title' => $r->newContract->title] : null,
            'contract' => [
                'id' => $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title, 'vendor' => $c->vendor ? ['id' => $c->vendor->id, 'name' => $c->vendor->name] : null,
                'owner' => $this->userMini($c->owner), 'ends_on' => $c->ends_on?->toDateString(), 'notice_deadline_on' => $c->notice_deadline_on?->toDateString(),
                'days_to_notice' => $c->days_to_notice, 'base_contract_value' => (string) $c->base_contract_value, 'annualised_base_value' => $c->annualised_base_value, 'status' => $c->status,
            ],
        ];
    }

    /** @return array<int,array{id:int,role:string,user:array<string,mixed>}> */
    protected function teamPayload(Contract $c): array
    {
        return ContractTeamMember::query()->where('contract_id', $c->id)->with('user:id,name,job_title,email')->orderBy('id')->get()
            ->map(fn (ContractTeamMember $m) => ['id' => $m->id, 'role' => $m->role, 'user' => ['id' => $m->user?->id, 'name' => $m->user?->name, 'job_title' => $m->user?->job_title, 'email' => $m->user?->email]])->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function linksPayload(Contract $c): array
    {
        return ContractLink::query()->where('contract_id', $c->id)->with(['linkable' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('id')->get()->map(function (ContractLink $l) {
            $t = $l->linkable;
            $key = $l->type_key;
            [$label, $href] = match ($key) {
                'asset' => [$t ? trim("{$t->asset_id_code} {$t->name}") : null, $t ? route('assets.show', $t->id) : null],
                'ea_application' => [$t ? trim("{$t->code} {$t->name}") : null, $t ? route('ea.applications.show', $t->id) : null],
                'business_service' => [$t ? trim("{$t->code} {$t->name}") : null, null],
                'risk' => [$t ? trim("{$t->risk_id_code} {$t->title}") : null, $t ? route('risks.show', $t->id) : null],
                'investment' => [$t ? trim("{$t->investment_code} {$t->title}") : null, $t ? route('finance.investments.show', $t->id) : null],
                default => [null, null],
            };

            return ['id' => $l->id, 'type' => $key, 'linkable_id' => $l->linkable_id, 'label' => $label ?? 'Removed record', 'href' => $href, 'note' => $l->note];
        })->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function documentsPayload(Contract $c): array
    {
        return ContractDocument::query()->where('contract_id', $c->id)->with(['evidence', 'creator:id,name'])->orderByDesc('id')->get()->map(function (ContractDocument $d) use ($c) {
            $has = $d->evidence?->file_path;

            return [
                'id' => $d->id, 'kind' => $d->kind, 'title' => $d->title, 'signed_on' => $d->signed_on?->toDateString(), 'expires_on' => $d->expires_on?->toDateString(),
                'sha256' => $d->sha256, 'file_name' => $d->evidence?->file_name,
                'download_url' => $has ? route('finance.contracts.documents.download', [$c->id, $d->id]) : null,
                'uploaded_by' => $this->userMini($d->creator), 'created_at' => $d->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    protected function eventsPayload(Contract $c, ?array $types = null): array
    {
        return ContractEvent::query()->where('contract_id', $c->id)->when($types, fn ($q) => $q->whereIn('type', $types))->with('user:id,name')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(200)->get()
            ->map(fn (ContractEvent $e) => ['id' => $e->id, 'type' => $e->type, 'label' => $this->eventLabel($e->type), 'payload' => $e->payload ?? [],
                'user' => $this->userMini($e->user), 'occurred_at' => $e->occurred_at?->toIso8601String()])->values()->all();
    }

    protected function eventLabel(string $type): string
    {
        return [
            'created' => 'Contract created', 'notified' => 'Renewal reminder sent', 'escalated' => 'Escalated to leadership', 'window_opened' => 'Renewal window opened',
            'notice_deadline_passed' => 'Notice deadline passed', 'decision_recorded' => 'Renewal decision recorded', 'renewed' => 'Renewed', 'terminated' => 'Terminated',
            'expired' => 'Expired', 'document_added' => 'Document added', 'status_changed' => 'Status changed', 'dates_changed' => 'Dates changed', 'renewal_opened' => 'Renewal opened',
            'renewal_submitted' => 'Renewal submitted for approval', 'renewal_rejected' => 'Renewal rejected', 'renewal_withdrawn' => 'Renewal withdrawn',
            'termination_requested' => 'Termination requested', 'termination_approved' => 'Termination approved', 'termination_rejected' => 'Termination rejected',
            'termination_withdrawn' => 'Termination withdrawn', 'approved_unexecuted_alert' => 'Approved renewal not executed',
            'team_changed' => 'Team changed', 'link_changed' => 'Links changed',
        ][$type] ?? Str::headline($type);
    }

    /** @return array<string,array<int,array{value:string,label:string}>> */
    protected function optionsPayload(): array
    {
        $opts = fn (array $values, ?array $labels = null) => collect($values)->map(fn ($v) => ['value' => $v, 'label' => $labels[$v] ?? ucfirst(str_replace('_', ' ', $v))])->values()->all();
        $decisions = ApprovalProcesses::PROCESSES['finance.contract_renewal']['conditions']['decision']['values'];

        return [
            'types' => $opts(Contract::TYPES, ApprovalProcesses::CONTRACT_TYPE_LABELS),
            'statuses' => $opts(Contract::STATUSES, ['in_renewal_window' => 'In renewal window', 'notice_deadline_passed' => 'Notice deadline passed']),
            'renewalTypes' => $opts(Contract::RENEWAL_TYPES),
            'billingFrequencies' => $opts(Contract::BILLING, ['semi_annual' => 'Semi-annual', 'one_off' => 'One-off']),
            'licenceMetrics' => $opts(Contract::LICENCE_METRICS, ['tb' => 'TB']),
            'dpRoles' => $opts(Contract::DP_ROLES),
            'dpaStatuses' => $opts(Contract::DPA),
            'decisions' => $opts(Contract::DECISIONS, $decisions),
            'teamRoles' => $opts(ContractTeamMember::ROLES),
            'documentKinds' => $opts(ContractDocument::KINDS, ['dpa' => 'DPA', 'sla' => 'SLA']),
            'linkTypes' => $opts(array_keys(ContractLink::TYPES), ['ea_application' => 'EA application']),
            'renewalStatuses' => $opts(Renewal::STATUSES),
            'leaseAssessments' => $opts(Contract::LEASE_ASSESSMENTS, ['not_lease' => 'Not a lease', 'ifrs16_lease' => 'IFRS 16 lease', 'short_term_exempt' => 'Short-term exempt', 'low_value_exempt' => 'Low-value exempt']),
        ];
    }
}

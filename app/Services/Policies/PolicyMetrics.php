<?php

namespace App\Services\Policies;

use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\PolicyException;
use Illuminate\Support\Facades\DB;

/**
 * One set of Policy Management numbers, read by the policy register, attestations, exceptions,
 * Control Standards and the executive dashboard. Every query goes through tenant-scoped models.
 */
class PolicyMetrics
{
    public function summary(): array
    {
        $policies = Policy::query()->get(['id', 'status', 'review_date', 'is_mandatory']);
        $active = $policies->where('status', 'active');
        $mapped = DB::table('control_policy')->whereIn('policy_id', $active->pluck('id'))->distinct()->pluck('policy_id')->flip();

        $campaign = PolicyAttestation::query()->whereIn('policy_id', $active->pluck('id'))->whereIn('status', ['pending', 'completed']);
        $due = (clone $campaign)->count();
        $done = (clone $campaign)->where('status', 'completed')->count();

        $exceptions = PolicyException::query()->get(['id', 'status', 'expires_on', 'risk_rating']);
        $live = $exceptions->where('status', 'approved');

        return [
            'total' => $policies->where('status', '!=', 'retired')->count(),
            'by_status' => collect(Policy::STATUSES)->mapWithKeys(fn ($s) => [$s => $policies->where('status', $s)->count()])->all(),
            'active' => $active->count(),
            'review_overdue' => $active->filter(fn ($p) => $p->review_date && $p->review_date->lt(today()))->count(),
            'awaiting_approval' => $policies->where('status', 'in_review')->count(),
            'attestation_rate' => $due ? (int) round($done / $due * 100) : null,
            'attestations_pending' => $due - $done,
            'attestations_overdue' => PolicyAttestation::query()->overdue()->whereIn('policy_id', $active->pluck('id'))->count(),
            'unmapped_active' => $active->reject(fn ($p) => isset($mapped[$p->id]))->count(),
            'exceptions_requested' => $exceptions->where('status', 'requested')->count(),
            'exceptions_live' => $live->filter(fn ($e) => $e->expires_on->gte(today()))->count(),
            'exceptions_expiring' => $live->filter(fn ($e) => $e->expires_on->gte(today()) && $e->expires_on->lte(today()->addDays(30)))->count(),
            'exceptions_high_risk' => $live->whereIn('risk_rating', ['critical', 'high'])->count(),
        ];
    }
}

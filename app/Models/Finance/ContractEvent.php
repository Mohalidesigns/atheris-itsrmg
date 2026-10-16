<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use App\Services\Finance\FinanceClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** The immutable timeline of a contract (ATH-FIN-001 §4.4, plan C67). */
class ContractEvent extends Model
{
    use FinanceRecord;

    public const TYPES = [
        'created', 'notified', 'escalated', 'window_opened', 'notice_deadline_passed', 'decision_recorded', 'renewed', 'terminated',
        'expired', 'document_added', 'status_changed', 'dates_changed',
        'renewal_opened', 'renewal_submitted', 'renewal_rejected', 'renewal_withdrawn', 'team_changed', 'link_changed',
        'termination_requested', 'termination_approved', 'termination_rejected', 'termination_withdrawn', 'approved_unexecuted_alert',
    ];

    protected $table = 'fin_contract_events';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['workflow' => 'Contract events are immutable.']));
        static::deleting(fn () => throw ValidationException::withMessages(['workflow' => 'Contract events are immutable.']));
    }

    /** Appends an event to a contract's timeline, whatever the auth context (sweeps run unauthenticated). */
    public static function record(Contract $contract, string $type, array $payload = [], ?User $user = null, ?CarbonInterface $at = null): self
    {
        return static::withoutGlobalScopes()->create([
            'organization_id' => $contract->organization_id, 'contract_id' => $contract->id, 'type' => $type,
            'payload' => $payload ?: null, 'user_id' => $user?->id, 'occurred_at' => $at ?? FinanceClock::now(),
        ]);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subscription — B1, the ownership model.
 *
 * ATH-EAR-002 §2.3: "Atheris has no Person↔object ownership concept at all in
 * the EA schema. Ownership is a free-text field, not a relationship." That
 * matters more here than elsewhere because EA is a crowdsourced discipline —
 * LeanIX's freshness model rests entirely on Subscriptions with Responsible /
 * Accountable / Observer roles, and Ardoq resolves broadcast audiences by
 * traversing `Owns` and `Is Expert In` references.
 *
 * Everything downstream in Phase 1 depends on this table: survey audiences,
 * who may approve a quality seal, and where notifications route.
 */
class Subscription extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_subscriptions';

    protected $guarded = [];

    public const ROLES = ['responsible', 'accountable', 'consulted', 'observer'];

    /** The roles that may approve a quality seal. */
    public const APPROVER_ROLES = ['responsible', 'accountable'];

    public const ROLE_LABELS = [
        'responsible' => 'Responsible',
        'accountable' => 'Accountable',
        'consulted' => 'Consulted',
        'observer' => 'Observer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForEntity($query, string $type, int|string $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function scopeApprovers($query)
    {
        return $query->whereIn('role', self::APPROVER_ROLES);
    }

    public function isApprover(): bool
    {
        return in_array($this->role, self::APPROVER_ROLES, true);
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? ucfirst($this->role);
    }
}

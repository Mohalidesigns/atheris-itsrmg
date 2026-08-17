<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A proposed write that has not happened yet.
 *
 * ATH-EAR-002 WS 4.5 asks for "GraphQL API + MCP scoped writes with
 * draft-and-approve (Ardoq's Scenario-merge pattern)". An agent with a token
 * can propose; only a human with `approve ea` can merge. The repository is the
 * system of record for a regulatory return, so an unattended mutation path is
 * not acceptable regardless of how well the agent behaves.
 */
class DraftChange extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_draft_changes';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'before' => 'array',
        'validation_json' => 'array',
        'decided_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function proposer()
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function entityLabel(): string
    {
        return class_basename($this->entity_type).($this->entity_id ? " #{$this->entity_id}" : ' (new)');
    }
}

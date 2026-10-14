<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account's entitlement in a campaign, with the reviewer's keep / revoke / modify decision. */
class AccessReviewItem extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['privileged' => 'boolean', 'last_login_at' => 'datetime', 'decided_at' => 'datetime', 'revocation_confirmed_at' => 'datetime'];

    public const DECISIONS = ['keep', 'revoke', 'modify'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AccessReviewCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** A revoke or modify decision still waiting for IT to confirm the change was made. */
    public function awaitingConfirmation(): bool
    {
        return in_array($this->decision, ['revoke', 'modify'], true) && ! $this->revocation_confirmed_at;
    }
}

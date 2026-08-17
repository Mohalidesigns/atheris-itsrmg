<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use App\Services\Ea\EntityRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * SurveyResponse — one (recipient × entity) ask, carrying its own magic-link
 * token.
 *
 * This is where §5.4 B2 beats the incumbent: "magic-link responses **from
 * non-licensed users**". LeanIX can only send surveys to active licensed users,
 * which §3.4 calls "a structural crowdsourcing ceiling made worse by Viewer
 * licences costing money". `recipient_user_id` is therefore nullable — the
 * email address is the identity, and the token is the credential.
 */
class SurveyResponse extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_survey_responses';

    protected $guarded = [];

    protected $casts = [
        'answers' => 'array',
        'opened_at' => 'datetime',
        'submitted_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public const PENDING = 'pending';

    public const OPENED = 'opened';

    public const SUBMITTED = 'submitted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public function campaign()
    {
        return $this->belongsTo(SurveyCampaign::class, 'campaign_id');
    }

    public function recipientUser()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * A 64-character URL-safe token. Long enough that enumeration is not a
     * concern; the token is the only credential a non-licensed respondent has,
     * so it is generated per (recipient × entity) rather than per recipient.
     */
    public static function newToken(): string
    {
        return Str::random(64);
    }

    public function isOutstanding(): bool
    {
        return in_array($this->state, [self::PENDING, self::OPENED], true);
    }

    public function entityLabel(): string
    {
        return EntityRegistry::describe($this->entity_type, $this->entity_id);
    }

    public function responseUrl(): string
    {
        return route('ea.portal.respond', $this->token);
    }
}

<?php

namespace App\Models\Capa;

use App\Models\Issue;
use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CAPA stage 2: an immediate mitigation taken before root cause analysis (IT-GOV-CAPA-001 §4). */
class ContainmentAction extends Model
{
    use BelongsToOrganization;

    protected $table = 'capa_containment_actions';

    protected $guarded = [];

    protected $casts = ['performed_at' => 'datetime'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }
}

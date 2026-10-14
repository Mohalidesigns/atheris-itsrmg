<?php

namespace App\Models\Approvals;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ApprovalDecision extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

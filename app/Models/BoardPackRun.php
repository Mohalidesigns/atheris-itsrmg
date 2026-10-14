<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A generated board pack (ATH-VAL-014): a snapshot of the bank's figures for each of the template's
 * sections, taken at generation so the pack the board read can be reproduced. Prepared by one
 * person, approved by another.
 */
class BoardPackRun extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime', 'approved_at' => 'datetime', 'content' => 'array'];

    public function template()
    {
        return $this->belongsTo(BoardPackTemplate::class, 'template_id');
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

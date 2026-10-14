<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ConsentPurpose extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_consent_purposes';

    protected $guarded = [];

    protected $casts = [
        'linked_flow_ids' => 'array',
        'cross_border' => 'boolean',
    ];
}

<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Solution extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_solutions';

    protected $guarded = [];

    public function pattern()
    {
        return $this->belongsTo(Pattern::class);
    }

    public function initiative()
    {
        return $this->belongsTo(Initiative::class);
    }
}

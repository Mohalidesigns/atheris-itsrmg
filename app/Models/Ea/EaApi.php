<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class EaApi extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_apis';

    protected $guarded = [];

    public function interface()
    {
        return $this->belongsTo(EaInterface::class, 'interface_id');
    }
}

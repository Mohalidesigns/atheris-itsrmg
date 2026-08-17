<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class EaInterface extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_interfaces';

    protected $guarded = [];

    protected $casts = ['pii_carrying' => 'boolean'];

    public function sourceApp()
    {
        return $this->belongsTo(EaApplication::class, 'source_app_id');
    }

    public function targetApp()
    {
        return $this->belongsTo(EaApplication::class, 'target_app_id');
    }

    public function apis()
    {
        return $this->hasMany(EaApi::class, 'interface_id');
    }
}

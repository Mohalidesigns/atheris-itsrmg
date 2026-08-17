<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Capability extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_capabilities';

    protected $guarded = [];

    protected $casts = ['last_verified_at' => 'date'];

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function plateau()
    {
        return $this->belongsTo(Plateau::class, 'plateau_id');
    }

    public function relationships()
    {
        return $this->hasMany(Relationship::class, 'source_id')
            ->where('source_type', self::class);
    }
}

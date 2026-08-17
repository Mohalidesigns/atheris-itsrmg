<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Plateau extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_plateaux';

    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    public function initiatives()
    {
        return $this->hasMany(Initiative::class);
    }

    public function applications()
    {
        return $this->hasMany(EaApplication::class, 'plateau_id');
    }

    public function techComponents()
    {
        return $this->hasMany(TechComponent::class, 'plateau_id');
    }

    public function capabilities()
    {
        return $this->hasMany(Capability::class, 'plateau_id');
    }
}

<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class AdmDeliverable extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_adm_deliverables';

    protected $guarded = [];

    protected $casts = ['due_date' => 'date'];

    public function initiative()
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }
}

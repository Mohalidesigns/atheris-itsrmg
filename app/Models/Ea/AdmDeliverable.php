<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AdmDeliverable extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_adm_deliverables';

    protected $guarded = [];

    protected $casts = ['due_date' => 'date'];

    public function initiative()
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }
}

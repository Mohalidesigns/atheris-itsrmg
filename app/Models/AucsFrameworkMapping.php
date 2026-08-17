<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AucsFrameworkMapping extends Model
{
    protected $guarded = [];

    public function control()
    {
        return $this->belongsTo(AucsControl::class, 'aucs_control_id');
    }

    public function clause()
    {
        return $this->belongsTo(FrameworkClause::class, 'framework_clause_id');
    }
}

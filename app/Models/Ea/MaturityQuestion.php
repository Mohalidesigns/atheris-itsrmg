<?php

namespace App\Models\Ea;

use Illuminate\Database\Eloquent\Model;

class MaturityQuestion extends Model
{
    protected $table = 'ea_maturity_questions';

    protected $guarded = [];

    public function domain()
    {
        return $this->belongsTo(MaturityDomain::class, 'domain_id');
    }
}

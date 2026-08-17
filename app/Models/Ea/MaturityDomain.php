<?php

namespace App\Models\Ea;

use Illuminate\Database\Eloquent\Model;

class MaturityDomain extends Model
{
    protected $table = 'ea_maturity_domains';

    protected $guarded = [];

    public function questions()
    {
        return $this->hasMany(MaturityQuestion::class, 'domain_id');
    }
}

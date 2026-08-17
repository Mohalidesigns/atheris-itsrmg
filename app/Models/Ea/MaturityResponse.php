<?php

namespace App\Models\Ea;

use Illuminate\Database\Eloquent\Model;

class MaturityResponse extends Model
{
    protected $table = 'ea_maturity_responses';

    protected $guarded = [];

    protected $casts = ['evidence_refs' => 'array'];

    public function question()
    {
        return $this->belongsTo(MaturityQuestion::class);
    }
}

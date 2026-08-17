<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KriBreach extends Model
{
    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function kri()
    {
        return $this->belongsTo(Kri::class);
    }
}

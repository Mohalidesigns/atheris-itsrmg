<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TprmSecurityRating extends Model
{
    protected $guarded = [];

    protected $casts = ['captured_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}

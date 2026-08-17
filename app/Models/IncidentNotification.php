<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'delivery_receipt' => 'array',
        'signed_at' => 'datetime',
        'deadline_at' => 'datetime',
    ];

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }
}

<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class KriReading extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['recorded_at' => 'datetime', 'value' => 'float'];

    public function kri()
    {
        return $this->belongsTo(Kri::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

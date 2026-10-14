<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A KRI outside appetite: opened by an amber or red reading, resolved when it is green again. */
class KriBreach extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'resolved_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    public function kri()
    {
        return $this->belongsTo(Kri::class);
    }

    public function reading()
    {
        return $this->belongsTo(KriReading::class, 'reading_id');
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function issue()
    {
        return $this->belongsTo(Issue::class);
    }
}

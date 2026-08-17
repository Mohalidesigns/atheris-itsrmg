<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class EvidencePack extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_evidence_packs';

    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime', 'overall_score' => 'decimal:2'];

    public function assessment()
    {
        return $this->belongsTo(MaturityAssessment::class);
    }
}

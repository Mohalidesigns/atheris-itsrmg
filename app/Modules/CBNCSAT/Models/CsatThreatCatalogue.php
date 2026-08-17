<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;

class CsatThreatCatalogue extends Model
{
    protected $table = 'csat_threat_catalogue';

    protected $fillable = [
        'threat_name', 'threat_source', 'threat_category',
        'typical_likelihood', 'typical_impact', 'description',
        'cbn_relevance_note', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}

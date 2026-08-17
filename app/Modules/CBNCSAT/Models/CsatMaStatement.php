<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsatMaStatement extends Model
{
    protected $table = 'csat_ma_statements';

    protected $fillable = [
        'domain_code', 'domain_name', 'factor_code', 'factor_name',
        'component_code', 'component_name', 'maturity_level', 'sequence',
        'statement_text', 'population_count', 'framework_version', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'maturity_level' => 'integer',
        ];
    }

    public const DOMAIN_NAMES = [
        1 => 'Cyber Risk Management & Oversight',
        2 => 'Threat Intelligence & Collaboration',
        3 => 'Cybersecurity Controls',
        4 => 'External Dependency Management',
        5 => 'Cyber Incident Management & Resilience',
    ];

    public const MATURITY_LEVELS = [
        0 => 'Sub-Baseline',
        1 => 'Baseline',
        2 => 'Evolving',
        3 => 'Intermediate',
        4 => 'Advanced',
        5 => 'Innovative',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(CsatMaResponse::class, 'statement_id');
    }
}

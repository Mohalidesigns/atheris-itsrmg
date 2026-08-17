<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsatIrQuestion extends Model
{
    protected $table = 'csat_ir_questions';

    protected $fillable = [
        'category_code', 'category_name', 'question_number', 'question_text',
        'level_1_criteria', 'level_2_criteria', 'level_3_criteria',
        'level_4_criteria', 'level_5_criteria',
        'cbn_specific', 'framework_version', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cbn_specific' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public const CATEGORY_NAMES = [
        1 => 'Technologies and Connection Types',
        2 => 'Delivery Channels',
        3 => 'Online/Mobile Products and Technology Services',
        4 => 'Organisational Characteristics',
        5 => 'External Threats',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(CsatIrResponse::class, 'question_id');
    }
}

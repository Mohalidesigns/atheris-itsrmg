<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MaturityAssessment extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_maturity_assessments';

    protected $guarded = [];

    protected $casts = ['overall_score' => 'decimal:2'];

    public function responses()
    {
        return $this->hasMany(MaturityResponse::class, 'assessment_id');
    }
}

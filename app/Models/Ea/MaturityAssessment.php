<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A CBN EA maturity assessment (ATH-VAL-015): draft -> submitted -> approved, or superseded. */
class MaturityAssessment extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_maturity_assessments';

    protected $guarded = [];

    protected $casts = [
        'overall_score' => 'decimal:2',
        'targets' => 'array',
        'results' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'submitted', 'approved', 'superseded'];

    public function responses()
    {
        return $this->hasMany(MaturityResponse::class, 'assessment_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class CourseOfAction extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_courses_of_action';

    protected $guarded = [];

    public function initiative()
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }
}

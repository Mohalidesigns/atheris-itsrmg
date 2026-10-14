<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CourseOfAction extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_courses_of_action';

    protected $guarded = [];

    public function initiative()
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }
}

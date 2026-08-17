<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Relationship extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_relationships';

    protected $guarded = [];

    protected $casts = ['attrs' => 'array'];

    public function source()
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function target()
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'target_id');
    }
}

<?php

namespace App\Models\Ea;

use Illuminate\Database\Eloquent\Model;

class InitiativeDependency extends Model
{
    protected $table = 'ea_initiative_dependencies';

    protected $guarded = [];

    public function predecessor()
    {
        return $this->belongsTo(Initiative::class, 'predecessor_id');
    }

    public function successor()
    {
        return $this->belongsTo(Initiative::class, 'successor_id');
    }
}

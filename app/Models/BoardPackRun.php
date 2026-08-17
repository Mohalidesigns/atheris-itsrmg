<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class BoardPackRun extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime'];

    public function template()
    {
        return $this->belongsTo(BoardPackTemplate::class, 'template_id');
    }
}

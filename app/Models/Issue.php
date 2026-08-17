<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['due_date' => 'date'];

    public function events()
    {
        return $this->hasMany(IssueEvent::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}

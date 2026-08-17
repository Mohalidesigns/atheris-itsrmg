<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['schema' => 'array'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SharedVendorDirectory extends Model
{
    protected $table = 'shared_vendor_directory';

    protected $guarded = [];

    protected $casts = ['contacts' => 'array'];
}

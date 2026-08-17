<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceItem extends Model
{
    protected $table = 'content_marketplace_items';

    protected $guarded = [];

    protected $casts = ['manifest_json' => 'array'];
}

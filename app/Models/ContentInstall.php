<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class ContentInstall extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['installed_at' => 'datetime'];

    public function item()
    {
        return $this->belongsTo(MarketplaceItem::class, 'marketplace_item_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared Nigerian Vendor Directory: platform-level, Atheris-maintained reference entries (no
 * organization). A bank links its own vendor record to an entry; the entry never holds bank data.
 */
class SharedVendorDirectory extends Model
{
    protected $table = 'shared_vendor_directory';

    protected $guarded = [];

    protected $casts = ['contacts' => 'array'];

    /** The signed-in bank's vendor records linked to this entry (Vendor is tenant-scoped). */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'shared_directory_id');
    }
}

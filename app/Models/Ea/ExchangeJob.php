<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExchangeJob extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_exchange_jobs';

    protected $guarded = [];
}

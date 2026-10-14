<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GlossaryTerm extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_glossary_terms';

    protected $guarded = [];
}

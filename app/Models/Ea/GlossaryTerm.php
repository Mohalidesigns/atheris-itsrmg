<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class GlossaryTerm extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_glossary_terms';

    protected $guarded = [];
}

<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;

/** A saved CSV column mapping for GL extracts (plan P3-12; replaces the spec's per-IntegrationConnection mapping). */
class ImportMapping extends Model
{
    use FinanceRecord;

    public const REQUIRED = ['posted_on', 'gl_code', 'cost_centre_code', 'amount'];

    public const OPTIONAL = ['description', 'currency', 'reference', 'vendor', 'contract_code'];

    protected $table = 'fin_import_mappings';

    protected $guarded = [];

    protected $casts = ['columns' => 'array', 'decimal_comma' => 'boolean', 'is_default' => 'boolean', 'has_header' => 'boolean'];
}

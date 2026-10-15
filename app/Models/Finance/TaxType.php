<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A tax (VAT, WHT, ...) applied to expenses and invoices (ATH-FIN-001 FIN-01.06). */
class TaxType extends Model
{
    use FinanceRecord;

    public const KINDS = ['vat', 'wht', 'sales_tax', 'none'];

    public const APPLIES_TO = ['expense', 'invoice'];

    protected $table = 'fin_tax_types';

    protected $guarded = [];

    protected $casts = [
        'rate' => 'decimal:4',
        'applies_to' => 'array',
        'is_inclusive' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function appliesTo(string $doc): bool
    {
        return in_array($doc, $this->applies_to ?? [], true);
    }
}

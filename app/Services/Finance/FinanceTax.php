<?php

namespace App\Services\Finance;

use App\Models\Finance\TaxType;

/**
 * VAT and withholding tax on an expense or invoice (FIN-01.06, plan P3-3). The budget cost is subtotal + VAT (input VAT is not
 * recoverable for a VAT-exempt bank); WHT reduces what is paid, not the cost. An inclusive VAT type is extracted from the gross.
 */
class FinanceTax
{
    private const WORK = 12;

    public function __construct(private readonly BudgetPhasing $phasing) {}

    /** @return array{subtotal:string,tax_amount:string,wht_amount:string,total_amount:string,payable_amount:string} */
    public function compute(string $net, string $currency, ?TaxType $vat, ?TaxType $wht, int $decimals = 2): array
    {
        $net = $this->phasing->round($net, $decimals);
        $subtotal = $net;
        $tax = bcadd('0', '0', $decimals);
        if ($vat && $vat->kind !== 'none' && bccomp((string) $vat->rate, '0', 4) > 0) {
            $rate = bcdiv((string) $vat->rate, '100', self::WORK);
            if ($vat->is_inclusive) {
                $subtotal = $this->phasing->round(bcdiv($net, bcadd('1', $rate, self::WORK), self::WORK), $decimals);
                $tax = bcsub($net, $subtotal, $decimals);
            } else {
                $tax = $this->phasing->round(bcmul($net, $rate, self::WORK), $decimals);
            }
        }
        $total = $vat && $vat->is_inclusive ? $net : bcadd($subtotal, $tax, $decimals);
        $whtAmount = bcadd('0', '0', $decimals);
        if ($wht && $wht->kind === 'wht' && bccomp((string) $wht->rate, '0', 4) > 0) {
            $whtAmount = $this->phasing->round(bcmul($subtotal, bcdiv((string) $wht->rate, '100', self::WORK), self::WORK), $decimals);
        }

        return ['subtotal' => $subtotal, 'tax_amount' => $tax, 'wht_amount' => $whtAmount, 'total_amount' => $total, 'payable_amount' => bcsub($total, $whtAmount, $decimals)];
    }
}

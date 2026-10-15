import { formatMoney } from '@/Utils/finance';

/** Amount in its own currency; when a base-currency equivalent differs it shows as a tooltip and optional second line. */
export default function Money({ value, currency, base, baseCurrency, decimals = 2, stacked = false, className = '' }) {
    const cur = currency || baseCurrency || 'NGN';
    const differs = base !== undefined && base !== null && currency && baseCurrency && currency !== baseCurrency;
    const baseText = differs ? `${formatMoney(base, baseCurrency, { decimals })} base` : undefined;
    return (
        <span title={baseText} className={`whitespace-nowrap tabular-nums ${className}`}>
            {formatMoney(value, cur, { decimals })}
            {differs && stacked && <span className="block text-[11px] text-[#718096]">{baseText}</span>}
        </span>
    );
}

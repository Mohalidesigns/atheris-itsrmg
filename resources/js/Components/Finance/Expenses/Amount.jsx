import { MASK, formatMoney } from '@/Utils/finance';

/** Record-currency amount; base-currency equivalent as a secondary line and tooltip. Masked values render as the mask. */
export default function Amount({ value, currency, base, baseCurrency, money = true, decimals = 2, secondary = true, className = '' }) {
    if (money === false || value === null || value === undefined) {
        return <span className={`tabular-nums ${className}`}>{money === false || value === null ? MASK : '—'}</span>;
    }
    const foreign = base !== undefined && base !== null && baseCurrency && currency && currency !== baseCurrency;
    const baseText = foreign ? formatMoney(base, baseCurrency, { decimals }) : null;
    return (
        <span className={`tabular-nums ${className}`} title={baseText ? `${baseText} in ${baseCurrency}` : undefined}>
            {formatMoney(value, currency || baseCurrency, { decimals })}
            {baseText && secondary && <span className="block text-[11px] font-normal text-[#718096]">{baseText}</span>}
        </span>
    );
}

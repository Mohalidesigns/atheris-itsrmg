import { humanize } from '@/Utils/risk';

export { humanize };

/** Numeric strings from the server are coerced; currency is an ISO 4217 code. */
export function formatMoney(value, currency = 'NGN', { decimals = 2 } = {}) {
    if (value === null || value === undefined || value === '') return '—';
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value));
    } catch {
        return `${currency} ${Number(value).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
    }
}

/** Up to 8 dp, trailing zeros trimmed down to a minimum of 4. */
export function formatRate(value) {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    let s = n.toFixed(8);
    s = s.replace(/0+$/, '');
    const dec = (s.split('.')[1] || '').length;
    if (dec < 4) s = n.toFixed(4);
    const [i, d] = s.split('.');
    return `${Number(i).toLocaleString()}.${d}`;
}

export function formatPct(value, decimals = 2) {
    if (value === null || value === undefined || value === '') return '—';
    return `${Number(value).toFixed(decimals).replace(/\.?0+$/, '')}%`;
}

export const PERIOD_TONES = { open: 'success', closed: 'neutral', locked: 'warning' };
export const FY_TONES = { planning: 'info', open: 'success', closed: 'neutral' };

export const FIELD = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
export const LABEL = 'block text-xs font-medium text-[#4A5568]';
export const BTN_PRIMARY = 'rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50';
export const BTN_SECONDARY = 'rounded-lg border border-gray-200 px-4 py-2 text-sm text-[#2D3748]';

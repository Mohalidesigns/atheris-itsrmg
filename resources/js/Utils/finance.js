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

export const BUDGET_TONES = { draft: 'neutral', submitted: 'info', under_review: 'warning', approved: 'success', baseline: 'success', revised: 'muted', rejected: 'error', withdrawn: 'muted' };
export const REQUEST_TONES = { draft: 'neutral', submitted: 'warning', approved: 'info', applied: 'success', rejected: 'error', withdrawn: 'muted' };
export const CYCLE_TONES = { planned: 'info', open: 'success', in_review: 'warning', closed: 'neutral' };
export const DOA_TONES = { pending: 'warning', scheduled: 'info', active: 'success', superseded: 'muted', rejected: 'error', withdrawn: 'muted' };
export const PRIORITY_TONES = { mandatory: 'error', regulatory: 'warning', committed: 'info', discretionary: 'neutral' };
export const RGT_LABELS = { run: 'Run', grow: 'Grow', transform: 'Transform' };

/** Exact decimal sum via integer cents (no float drift). Returns a 2dp string. */
export function toCents(v) {
    if (v === null || v === undefined || v === '') return 0n;
    const s = String(v).trim();
    const m = s.match(/^(-?)(\d*)(?:\.(\d*))?$/);
    if (!m) return 0n;
    const frac = ((m[3] || '') + '00').slice(0, 2);
    const n = BigInt((m[2] || '0') + frac);
    return m[1] ? -n : n;
}
export function fromCents(c) {
    const neg = c < 0n;
    const a = (neg ? -c : c).toString().padStart(3, '0');
    return `${neg ? '-' : ''}${a.slice(0, -2)}.${a.slice(-2)}`;
}
export function sumDecimal(values) {
    return fromCents(values.reduce((acc, v) => acc + toCents(v), 0n));
}
export function pctOf(a, b) {
    const d = Number(b);
    if (!d) return null;
    return (Number(a) / d) * 100;
}
export const sentence = (v) => humanize(String(v ?? ''));

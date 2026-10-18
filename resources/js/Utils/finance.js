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

/* ---------------------------------------------------------------- contracts, renewals, calendar (P2) */

export const CONTRACT_TONES = { draft: 'neutral', active: 'success', in_renewal_window: 'warning', notice_deadline_passed: 'error', expired: 'error', renewed: 'muted', terminated: 'muted', replaced: 'muted' };
export const RENEWAL_TONES = { open: 'info', submitted: 'warning', approved: 'success', executed: 'success', rejected: 'error', withdrawn: 'muted' };

/** Calendar colour semantics: green active, amber renewal window, red notice passed / overdue, grey closed, blue milestones. */
export const CAL_COLORS = {
    green: { chip: 'bg-[#DCEFE1] border-[#2D7D46] text-[#1B5530]', dot: 'bg-[#2D7D46]', bar: '#2D7D46', hex: '#2D7D46' },
    amber: { chip: 'bg-[#FCEBC4] border-[#E5A100] text-[#5F4300]', dot: 'bg-[#E5A100]', bar: '#E5A100', hex: '#E5A100' },
    red: { chip: 'bg-[#F9DEDC] border-[#B3261E] text-[#8C1D18]', dot: 'bg-[#B3261E]', bar: '#B3261E', hex: '#B3261E' },
    grey: { chip: 'bg-[#F1F2F4] border-[#A0A4AB] text-[#43474E]', dot: 'bg-[#A0A4AB]', bar: '#A0A4AB', hex: '#A0A4AB' },
    blue: { chip: 'bg-[#DFE6FB] border-[#1D4ED8] text-[#1E3A8A]', dot: 'bg-[#1D4ED8]', bar: '#1D4ED8', hex: '#1D4ED8' },
};

export const DECISION_LABELS = { renew_as_is: 'Renew as is', renew_renegotiated: 'Renew (renegotiated)', replace: 'Replace', terminate: 'Terminate', extend_short_term: 'Short-term extension' };
export const DECISION_ICONS = { renew_as_is: 'autorenew', renew_renegotiated: 'handshake', replace: 'swap_horiz', terminate: 'cancel', extend_short_term: 'more_time' };
export const CONTRACT_TYPE_LABELS = {
    licence: 'Licence', subscription: 'Subscription', saas: 'SaaS', support_maintenance: 'Support and maintenance', managed_service: 'Managed service',
    professional_services: 'Professional services', lease: 'Lease', telecom: 'Telecom', cloud_commitment: 'Cloud commitment', hardware_warranty: 'Hardware warranty', other: 'Other',
};

export const MASK = '•••';

/** Money that honours masking: server-nulled amounts render as ••• (never 0), absent values as a dash. */
export function moneyOrMask(value, currency, viewAmounts = true, opts = {}) {
    if (viewAmounts === false) return MASK;
    return formatMoney(value, currency, opts);
}

/** "in 12 days" / "today" / "3 days ago" / "tomorrow" from a signed day count. */
export function daysLabel(n) {
    if (n === null || n === undefined || Number.isNaN(Number(n))) return '';
    const d = Number(n);
    if (d === 0) return 'today';
    if (d === 1) return 'tomorrow';
    if (d === -1) return 'yesterday';
    return d > 0 ? `in ${d} days` : `${Math.abs(d)} days ago`;
}

/** ISO date (Y-m-d) in the viewer's locale; time-zone safe (no UTC shift). */
export function localDate(value, opts = { day: '2-digit', month: 'short', year: 'numeric' }) {
    if (!value) return '—';
    const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return String(value);
    return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])).toLocaleDateString(undefined, opts);
}

export const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
export const parseIso = (s) => { const [y, m, d] = String(s).slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d); };
export const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
export const diffDays = (a, b) => Math.round((parseIso(a) - parseIso(b)) / 86400000);

/* ---------------------------------------------------------------- expenses, procurement, invoices, actuals (P3) */

export const EXPENSE_TONES = { draft: 'neutral', submitted: 'warning', approved: 'info', rejected: 'error', committed: 'success', partially_received: 'info', received: 'success', invoiced: 'success', closed: 'muted', cancelled: 'muted', withdrawn: 'muted' };
export const PO_TONES = { open: 'info', partially_received: 'warning', received: 'success', closed: 'muted', cancelled: 'muted' };
export const INVOICE_TONES = { draft: 'neutral', submitted: 'warning', approved: 'info', rejected: 'error', scheduled: 'info', paid: 'success', disputed: 'error', cancelled: 'muted' };
export const MATCH_TONES = { unmatched: 'neutral', matched: 'success', variance: 'warning', override: 'tertiary' };
export const ACTUAL_TONES = { matched: 'success', unmatched: 'warning', unbudgeted: 'error', duplicate: 'muted' };
export const ACCRUAL_TONES = { proposed: 'warning', posted: 'success', reversed: 'muted', cancelled: 'muted' };
export const BATCH_TONES = { completed: 'success', completed_with_errors: 'warning', failed: 'error' };
export const toneOf = (map, v) => map[v] || 'neutral';
export const DOC_KINDS = ['quote', 'purchase_order', 'goods_receipt', 'invoice', 'delivery_note', 'approval', 'other'].map((v) => ({ value: v, label: humanize(v) }));

/* ---------------------------------------------------------------- monitoring (P4) */

export const SEVERITY_TONES = { red: 'error', amber: 'warning' };
/** Chip tone for a variance severity (null = within tolerance). */
export const severityTone = (s) => SEVERITY_TONES[s] || 'success';
export const VARIANCE_STATUS_TONES = { open: 'warning', explained: 'info', acknowledged: 'success', cleared: 'muted' };

/** Signed percentage with one decimal; null stays a dash. Masked pages should pass through MASK first. */
export function fmtPct(value, { sign = false, decimals = 1 } = {}) {
    if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—';
    const n = Number(value);
    return `${sign && n > 0 ? '+' : ''}${n.toFixed(decimals)}%`;
}

const CHECKLIST_ICONS = { pass: ['check_circle', 'success'], fail: ['cancel', 'error'], warn: ['warning', 'warning'], n_a: ['remove_circle', 'muted'] };
/** [icon, tone] for a close-checklist item status. */
export const checklistIcon = (status) => CHECKLIST_ICONS[status] || CHECKLIST_ICONS.n_a;

/* ---------------------------------------------------------------- investments and value realisation (P5) */

export const STAGES = ['idea', 'business_case', 'approved', 'in_delivery', 'delivered', 'benefits_tracking', 'closed'];
const STAGE_LABELS = { idea: 'Idea', business_case: 'Business case', approved: 'Approved', in_delivery: 'In delivery', delivered: 'Delivered', benefits_tracking: 'Benefits tracking', closed: 'Closed', cancelled: 'Cancelled' };
export const stageLabel = (s) => STAGE_LABELS[s] ?? humanize(String(s ?? ''));
const STAGE_TONES = { idea: 'neutral', business_case: 'info', approved: 'primary', in_delivery: 'warning', delivered: 'success', benefits_tracking: 'tertiary', closed: 'muted', cancelled: 'muted' };
export const stageTone = (s) => STAGE_TONES[s] ?? 'neutral';
const HEALTH_TONES = { on_track: 'success', at_risk: 'warning', off_track: 'error', on_hold: 'muted' };
export const healthTone = (h) => HEALTH_TONES[h] ?? 'neutral';
export const GATE_TONES = { submitted: 'warning', approved: 'success', rejected: 'error', withdrawn: 'muted', passed: 'success' };
export const BENEFIT_TONES = { planned: 'neutral', tracking: 'info', realised: 'success', partially_realised: 'warning', not_realised: 'error', cancelled: 'muted' };
export const REVIEW_TONES = { scheduled: 'neutral', due: 'warning', overdue: 'error', completed: 'success', cancelled: 'muted' };

/** Whole months as "3 years 4 months" / "40 months"; null stays a dash. */
export function fmtMonths(m) {
    if (m === null || m === undefined || m === '') return '—';
    const n = Number(m);
    if (n < 24) return `${n} month${n === 1 ? '' : 's'}`;
    const y = Math.floor(n / 12);
    const r = n % 12;
    return `${y} years${r ? ` ${r} months` : ''}`;
}

/** Investment money: masked → "Hidden", absent → dash. Base currency only (C144). */
export function invMoney(value, currency, viewAmounts = true, opts = {}) {
    if (viewAmounts === false || value === undefined) return viewAmounts === false ? 'Hidden' : '—';
    return formatMoney(value, currency, opts);
}
/** Plain number with fixed decimals (CPI etc.); null → "Hidden" when masked, dash otherwise. */
export function invNumber(value, viewAmounts = true, decimals = 2) {
    if (viewAmounts === false) return 'Hidden';
    if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—';
    return Number(value).toFixed(decimals);
}

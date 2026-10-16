import { addDays, isoDate, parseIso } from '@/Utils/finance';

export const VIEWS = [['month', 'Month'], ['quarter', 'Quarter'], ['year', 'Year'], ['timeline', 'Timeline']];

/** Events keyed by Y-m-d of their start (milestones are single-day). */
export function groupByDay(events) {
    const map = new Map();
    events.forEach((e) => {
        const k = String(e.start).slice(0, 10);
        if (!map.has(k)) map.set(k, []);
        map.get(k).push(e);
    });
    return map;
}

/** Monday-first weeks (arrays of 7 Dates) covering the month of `anchor` (Y-m-d). */
export function monthWeeks(anchor) {
    const a = parseIso(anchor);
    const first = new Date(a.getFullYear(), a.getMonth(), 1);
    const lead = (first.getDay() + 6) % 7;
    const start = addDays(first, -lead);
    const last = new Date(a.getFullYear(), a.getMonth() + 1, 0);
    const total = Math.ceil((lead + last.getDate()) / 7) * 7;
    return Array.from({ length: total / 7 }, (_, w) => Array.from({ length: 7 }, (_, d) => addDays(start, w * 7 + d)));
}

export const weekdayLabels = (style = 'short') => Array.from({ length: 7 }, (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(undefined, { weekday: style }));
export const monthName = (y, m, style = 'long') => new Date(y, m, 1).toLocaleDateString(undefined, { month: style });
export const longDay = (iso) => parseIso(iso).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

/** Y-m-d the page should request when stepping `dir` (-1/+1) in `view`. */
export function stepDate(view, anchor, dir) {
    const a = parseIso(anchor);
    const months = { month: 1, quarter: 3, year: 12, timeline: 6 }[view] ?? 1;
    return isoDate(new Date(a.getFullYear(), a.getMonth() + dir * months, 1));
}

export function periodLabel(view, anchor) {
    const a = parseIso(anchor);
    if (view === 'year') return String(a.getFullYear());
    if (view === 'quarter') { const q = Math.floor(a.getMonth() / 3); return `Q${q + 1} ${a.getFullYear()}`; }
    if (view === 'timeline') return `${monthName(a.getFullYear(), a.getMonth() - 1, 'short')} ${a.getFullYear()} to ${monthName(a.getFullYear(), a.getMonth() + 11, 'short')}`;
    return a.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

/** Normalise server option lists ({value,label} | {id,name|code} | string) to {value,label}. */
export function normOpts(list, humanise = (s) => s) {
    return (list ?? []).map((o) => {
        if (typeof o === 'string') return { value: o, label: humanise(o) };
        if ('value' in o) return { value: String(o.value), label: o.label ?? humanise(String(o.value)) };
        if ('key' in o) return { value: String(o.key), label: o.label ?? humanise(String(o.key)) };
        return { value: String(o.id), label: o.code && o.name ? `${o.code} ${o.name}` : (o.name ?? o.label ?? String(o.id)) };
    });
}

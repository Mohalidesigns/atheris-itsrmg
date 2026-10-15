import Icon from '@/Components/Material/Icon';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

/** Status colours shared by budgets, transfers and supplementary requests. */
export const STATUS_COLOUR = { draft: '#A0AEC0', submitted: '#1D4ED8', applied: '#0A1F44', under_review: '#E5A100', approved: '#2D7D46', baseline: '#0A1F44', revised: '#C9A86A', rejected: '#B3261E', withdrawn: '#CBD5E0' };
export const CHART_COLOURS = ['#0A1F44', '#C9A86A', '#1D4ED8', '#2D7D46', '#E5A100', '#A0AEC0'];
export const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);

export function compactMoney(value, currency) {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(value);
    } catch {
        return `${currency} ${Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value)}`;
    }
}

export function StatCard({ label, value, sublabel, icon, tint, onClick }) {
    const Tag = onClick ? 'button' : 'div';
    return (
        <Tag type={onClick ? 'button' : undefined} onClick={onClick} className="flex items-start justify-between gap-3 rounded-xl border border-gray-100 bg-white p-5 text-left shadow-sm transition hover:shadow">
            <div className="min-w-0">
                <p className="text-sm text-[#4A5568]">{label}</p>
                <p className="mt-1 truncate text-2xl font-semibold tabular-nums text-[#1B1B1F]">{value}</p>
                {sublabel && <p className="mt-1 truncate text-xs text-[#718096]">{sublabel}</p>}
            </div>
            <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${tint}`}><Icon name={icon} size={22} /></span>
        </Tag>
    );
}

export function DonutCard({ title, subtitle, data, centre, centreLabel, format, empty }) {
    const shown = data.filter((d) => d.value > 0);
    return (
        <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
            <div className="flex items-baseline justify-between border-b border-gray-100 px-5 py-3">
                <h3 className="text-base font-semibold text-[#1B1B1F]">{title}</h3>
                {subtitle && <span className="text-xs text-[#718096]">{subtitle}</span>}
            </div>
            {shown.length === 0 ? <p className="px-5 py-12 text-center text-sm text-[#718096]">{empty}</p> : (
                <div className="flex flex-col items-center gap-5 p-5 sm:flex-row">
                    <div className="relative h-40 w-40 shrink-0">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie data={shown} dataKey="value" nameKey="name" innerRadius={50} outerRadius={76} paddingAngle={shown.length > 1 ? 2 : 0} stroke="none">
                                    {shown.map((d) => <Cell key={d.key} fill={d.color} />)}
                                </Pie>
                                <Tooltip formatter={(v) => format(v)} />
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span className="text-xl font-semibold tabular-nums text-[#1B1B1F]">{centre}</span>
                            <span className="text-[11px] text-[#718096]">{centreLabel}</span>
                        </div>
                    </div>
                    <ul className="w-full min-w-0 space-y-2">
                        {shown.map((d) => (
                            <li key={d.key}>
                                <button type="button" disabled={!d.onClick} onClick={d.onClick} className="flex w-full items-center gap-2 text-left text-sm enabled:hover:underline">
                                    <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: d.color }} />
                                    <span className="truncate text-[#4A5568]">{d.name}</span>
                                    <span className="ml-auto whitespace-nowrap font-semibold tabular-nums text-[#1B1B1F]">{format(d.value)}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

/** Sums rows into donut slices by key, keeping the top `limit` and folding the rest into "Other". */
export function sliceBy(rows, keyOf, nameOf, valueOf, limit = 5) {
    const groups = Object.values(rows.reduce((acc, r) => {
        const k = keyOf(r);
        if (k === null || k === undefined) return acc;
        acc[k] = acc[k] || { key: k, name: nameOf(r), value: 0 };
        acc[k].value += valueOf(r);
        return acc;
    }, {})).sort((a, b) => b.value - a.value);
    const rest = groups.slice(limit).reduce((t, g) => t + g.value, 0);
    return [...groups.slice(0, limit), ...(rest > 0 ? [{ key: '__other', name: `Other (${groups.length - limit})`, value: rest }] : [])]
        .map((g, i) => ({ ...g, color: CHART_COLOURS[i % CHART_COLOURS.length] }));
}

const REQUEST_STATUSES = ['draft', 'submitted', 'approved', 'applied', 'rejected', 'withdrawn'];

/** Stat cards plus a status donut and two value donuts, shared by the transfers and supplementary registers. */
export function RequestOverview({ summary, rows, baseCurrency, money, onStatus, valueDonuts }) {
    const fmt = (v) => new Intl.NumberFormat(undefined, { style: 'currency', currency: baseCurrency, maximumFractionDigits: 0 }).format(v);
    const byStatus = REQUEST_STATUSES.map((st) => ({ key: st, name: st.charAt(0).toUpperCase() + st.slice(1), color: STATUS_COLOUR[st], value: rows.filter((r) => r.status === st).length, onClick: () => onStatus(st) }));
    const hidden = 'Amounts are hidden for your role';
    return (
        <>
            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard label="Drafts" value={summary.draft} sublabel="Not yet submitted" icon="edit_note" tint="bg-[#F0F1F4] text-[#43474E]" onClick={() => onStatus('draft')} />
                <StatCard label="Pending approval" value={summary.pending} sublabel="Waiting on the DoA band" icon="hourglass_top" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => onStatus('submitted')} />
                <StatCard label="Approved, not applied" value={summary.approved_unapplied} sublabel="Ready to post to baseline" icon="pending_actions" tint="bg-[#EAF0FB] text-[#1D4ED8]" onClick={() => onStatus('approved')} />
                <StatCard label="Applied" value={summary.applied_count} sublabel="Posted to baseline" icon="task_alt" tint="bg-[#E7F4EC] text-[#2D7D46]" onClick={() => onStatus('applied')} />
                <StatCard label="Applied value" value={money ? fmt(num(summary.applied_base)) : '—'} sublabel={baseCurrency} icon="payments" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
            </div>
            <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <DonutCard title="By status" subtitle={`${rows.length} shown`} data={byStatus} centre={rows.length} centreLabel="Total" format={(v) => v} empty="Nothing for this selection" />
                {valueDonuts.map((d) => {
                    const total = d.data.reduce((t, s) => t + s.value, 0);
                    return (
                        <DonutCard key={d.title} title={d.title} subtitle={d.subtitle ?? baseCurrency} data={money ? d.data : []} centre={money ? compactMoney(total, baseCurrency) : '—'} centreLabel="Total value"
                            format={fmt} empty={money ? 'No value for this selection' : hidden} />
                    );
                })}
            </div>
        </>
    );
}

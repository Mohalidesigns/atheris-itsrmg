import { CHART_COLOURS, compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import { Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis, BarChart } from 'recharts';

const full = (cur) => (v) => new Intl.NumberFormat(undefined, { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(v);

function Frame({ title, subtitle, children, label }) {
    return (
        <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
            <div className="flex items-baseline justify-between border-b border-gray-100 px-5 py-3">
                <h3 className="text-base font-semibold text-[#1B1B1F]">{title}</h3>
                {subtitle && <span className="text-xs text-[#718096]">{subtitle}</span>}
            </div>
            <div className="p-4" role="img" aria-label={label ?? title}>{children}</div>
        </div>
    );
}

/** Bars for budget and actual per period, lines for the cumulative budget and actual. */
export function MonthlyBvA({ data, currency }) {
    const rows = data.map((d) => ({ name: d.name, budget: num(d.budget), actual: d.actual === null ? null : num(d.actual), cumBudget: num(d.cumulative_budget), cumActual: d.cumulative_actual === null ? null : num(d.cumulative_actual) }));
    return (
        <Frame title="Budget vs actual by month" subtitle={currency} label="Monthly budget versus actual with cumulative lines">
            {rows.length === 0 ? <p className="py-12 text-center text-sm text-[#718096]">No periods to chart.</p> : (
                <div className="h-72">
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart data={rows} margin={{ left: 4, right: 8, top: 8 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                            <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                            <YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, currency)} width={64} />
                            <Tooltip formatter={full(currency)} />
                            <Legend wrapperStyle={{ fontSize: 12 }} />
                            <Bar dataKey="budget" name="Budget" fill="#C9A86A" radius={[2, 2, 0, 0]} />
                            <Bar dataKey="actual" name="Actual" fill="#0A1F44" radius={[2, 2, 0, 0]} />
                            <Line type="monotone" dataKey="cumBudget" name="Cumulative budget" stroke="#8A6A2E" strokeDasharray="4 3" dot={false} />
                            <Line type="monotone" dataKey="cumActual" name="Cumulative actual" stroke="#1D4ED8" strokeWidth={2} dot={false} connectNulls={false} />
                        </ComposedChart>
                    </ResponsiveContainer>
                </div>
            )}
        </Frame>
    );
}

/** Horizontal bars of budget and actual per category / tower. */
export function SpendByTower({ data, currency }) {
    const rows = data.map((d) => ({ name: d.name, budget: num(d.budget), actual: num(d.actual) })).sort((a, b) => b.actual - a.actual).slice(0, 8);
    return (
        <Frame title="Spend by category" subtitle={`${currency}, top ${rows.length}`} label="Budget and actual by category">
            {rows.length === 0 ? <p className="py-12 text-center text-sm text-[#718096]">No spend yet.</p> : (
                <div style={{ height: Math.max(180, rows.length * 38 + 40) }}>
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={rows} layout="vertical" margin={{ left: 8, right: 16 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" horizontal={false} />
                            <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, currency)} />
                            <YAxis type="category" dataKey="name" tick={{ fontSize: 11 }} width={110} />
                            <Tooltip formatter={full(currency)} />
                            <Legend wrapperStyle={{ fontSize: 12 }} />
                            <Bar dataKey="budget" name="Budget" fill="#C9A86A" />
                            <Bar dataKey="actual" name="Actual" fill="#0A1F44" />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </Frame>
    );
}

/** One stacked proportion bar with a legend (CapEx/OpEx, Run/Grow/Transform). */
export function SplitBar({ title, parts, currency }) {
    const total = parts.reduce((t, p) => t + num(p.value), 0);
    return (
        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <h3 className="mb-3 text-sm font-semibold text-[#1B1B1F]">{title}</h3>
            {total <= 0 ? <p className="text-xs text-[#718096]">No budget to split.</p> : (
                <>
                    <div className="flex h-3 overflow-hidden rounded bg-gray-100" role="img" aria-label={title}>
                        {parts.map((p, i) => <div key={p.key} style={{ width: `${(num(p.value) / total) * 100}%`, backgroundColor: CHART_COLOURS[i % CHART_COLOURS.length] }} title={`${p.label}: ${full(currency)(num(p.value))}`} />)}
                    </div>
                    <ul className="mt-3 grid grid-cols-1 gap-1 text-xs sm:grid-cols-3">
                        {parts.map((p, i) => (
                            <li key={p.key} className="flex items-center gap-2">
                                <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ backgroundColor: CHART_COLOURS[i % CHART_COLOURS.length] }} />
                                <span className="text-[#4A5568]">{p.label}</span>
                                <span className="ml-auto tabular-nums text-[#1B1B1F]">{Math.round((num(p.value) / total) * 100)}%</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}

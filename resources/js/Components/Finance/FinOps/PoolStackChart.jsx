import { compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import { humanize } from '@/Utils/finance';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const POOLS = ['hardware', 'software', 'labour', 'outside_services', 'facilities', 'telecom', 'cloud', 'other', 'unclassified'];
const COLOURS = { hardware: '#0A1F44', software: '#C9A86A', labour: '#1D4ED8', outside_services: '#2D7D46', facilities: '#E5A100', telecom: '#7C5CBF', cloud: '#0E8FA6', other: '#A0AEC0', unclassified: '#CBD5E0' };

/** Stacked horizontal bars of TCO by cost pool for the top subjects. */
export default function PoolStackChart({ rows, currency, limit = 15 }) {
    const data = [...rows].sort((a, b) => num(b.total) - num(a.total)).slice(0, limit)
        .map((r) => ({ name: r.label.length > 26 ? `${r.label.slice(0, 25)}…` : r.label, ...Object.fromEntries(POOLS.map((p) => [p, num(r.by_pool?.[p])])) }));
    const full = (v) => new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 0 }).format(v);
    return (
        <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
            <div className="flex items-baseline justify-between border-b border-gray-100 px-5 py-3"><h3 className="text-base font-semibold text-[#1B1B1F]">TCO by cost pool, top {Math.min(limit, rows.length)}</h3><span className="text-xs text-[#718096]">{currency}</span></div>
            <div className="p-4" role="img" aria-label="Stacked bar of total cost of ownership by cost pool">
                {data.length === 0 ? <p className="py-10 text-center text-sm text-[#718096]">Nothing to chart.</p> : (
                    <div style={{ height: Math.max(220, data.length * 28 + 70) }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={data} layout="vertical" margin={{ left: 8, right: 12 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" horizontal={false} />
                                <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, currency)} />
                                <YAxis type="category" dataKey="name" width={140} tick={{ fontSize: 11 }} />
                                <Tooltip formatter={full} />
                                <Legend wrapperStyle={{ fontSize: 11 }} formatter={(v) => humanize(v)} />
                                {POOLS.map((p) => <Bar key={p} dataKey={p} stackId="t" fill={COLOURS[p]} />)}
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </div>
        </div>
    );
}

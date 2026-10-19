import { compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

/** Daily cloud cost, tagged versus untagged stacked. `series` = [{date, cost, untagged}]. */
export default function CloudCostChart({ series, currency }) {
    const rows = series.map((s) => ({ date: s.date.slice(5), untagged: num(s.untagged), tagged: Math.max(0, num(s.cost) - num(s.untagged)) }));
    const full = (v) => new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 0 }).format(v);
    return (
        <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
            <div className="flex items-baseline justify-between border-b border-gray-100 px-5 py-3">
                <h3 className="text-base font-semibold text-[#1B1B1F]">Daily cloud cost</h3>
                <span className="text-xs text-[#718096]">{currency}</span>
            </div>
            <div className="p-4" role="img" aria-label="Daily cloud cost, tagged versus untagged">
                {rows.length === 0 ? <p className="py-12 text-center text-sm text-[#718096]">No cost lines in this range.</p> : (
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={rows} margin={{ left: 4, right: 8, top: 8 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                                <XAxis dataKey="date" tick={{ fontSize: 11 }} minTickGap={24} />
                                <YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, currency)} width={64} />
                                <Tooltip formatter={full} />
                                <Legend wrapperStyle={{ fontSize: 12 }} />
                                <Area type="monotone" dataKey="tagged" name="Tagged" stackId="1" stroke="#0A1F44" fill="#0A1F44" fillOpacity={0.8} />
                                <Area type="monotone" dataKey="untagged" name="Untagged" stackId="1" stroke="#E5A100" fill="#E5A100" fillOpacity={0.8} />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                )}
            </div>
        </div>
    );
}

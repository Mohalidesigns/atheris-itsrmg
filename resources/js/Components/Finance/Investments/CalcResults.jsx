import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import { fmtMonths, formatPct, invMoney } from '@/Utils/finance';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

function Metric({ label, value, hint }) {
    return (
        <div className="rounded-lg border border-gray-100 bg-white p-3">
            <dt className="text-xs text-[#718096]">{label}</dt>
            <dd className="mt-0.5 text-lg font-semibold tabular-nums text-[#0A1F44]">{value}</dd>
            {hint && <p className="text-[11px] text-[#718096]">{hint}</p>}
        </div>
    );
}

/** NPV, IRR, payback, ROI, TCO, cash-flow table and cumulative chart. `r` is the evaluate payload or a saved business case. */
export default function CalcResults({ r, currency, viewAmounts = true, busy = false, error = null }) {
    if (!viewAmounts) return <Card title="Results"><Empty>Hidden: amounts and returns are not visible to your role.</Empty></Card>;
    if (error) return <Card title="Results"><p role="alert" className="px-5 py-4 text-sm text-[#B3261E]">{error}</p></Card>;
    if (!r) return <Card title="Results"><Empty>Enter costs and benefits to see NPV, IRR and payback.</Empty></Card>;
    const m = (v) => invMoney(v, currency, true, { decimals: 0 });
    const flows = r.cash_flows ?? [];
    const chart = flows.map((f) => ({ year: `Y${f.year}`, cumulative: num(f.cumulative), discounted: num(f.discounted_cumulative) }));
    return (
        <Card title="Results" right={busy && <span className="text-xs text-[#718096]">Calculating…</span>}>
            <dl className="grid grid-cols-2 gap-3 px-5 py-4 lg:grid-cols-4">
                <Metric label="NPV" value={m(r.npv)} />
                <Metric label="IRR" value={r.irr_pct === null || r.irr_pct === undefined ? 'n/a' : formatPct(r.irr_pct)} hint={r.irr_multiple ? 'Several sign changes: more than one IRR may exist' : r.irr_pct === null || r.irr_pct === undefined ? 'No sign change in the cash flows' : null} />
                <Metric label="Payback" value={fmtMonths(r.payback_months)} hint={r.payback_months === null || r.payback_months === undefined ? 'Not within the horizon' : null} />
                <Metric label="Discounted payback" value={fmtMonths(r.discounted_payback_months)} />
                <Metric label="ROI" value={r.roi_pct === null || r.roi_pct === undefined ? 'n/a' : formatPct(r.roi_pct, 1)} />
                <Metric label="TCO" value={m(r.tco)} hint="Capex + opex + run, undiscounted" />
                <Metric label="One-off cost (BAC)" value={m(r.one_off_cost)} hint="Excludes run cost" />
                <Metric label="Total benefit" value={m(r.total_benefit)} />
            </dl>
            {flows.length > 0 && (
                <>
                    <div className="h-56 px-2" role="img" aria-label="Cumulative cash flow by year">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={chart} margin={{ top: 8, right: 16, bottom: 0, left: 8 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                                <XAxis dataKey="year" tick={{ fontSize: 11 }} />
                                <YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, currency)} width={70} />
                                <Tooltip formatter={(v) => invMoney(v, currency, true, { decimals: 0 })} />
                                <Legend wrapperStyle={{ fontSize: 11 }} />
                                <Line type="monotone" dataKey="cumulative" name="Cumulative" stroke="#0A1F44" strokeWidth={2} dot={false} />
                                <Line type="monotone" dataKey="discounted" name="Discounted cumulative" stroke="#C9A86A" strokeWidth={2} dot={false} />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                    <Table head={['Year', 'Cost', 'Benefit', 'Net', 'Discounted', 'Cumulative']}>
                        {flows.map((f) => (
                            <tr key={f.year}>
                                <td className="px-4 py-1.5">{f.year}</td>
                                {[f.cost, f.benefit, f.net, f.discounted, f.cumulative].map((v, i) => <td key={i} className={`whitespace-nowrap px-4 py-1.5 text-right tabular-nums ${i === 2 && num(v) < 0 ? 'text-[#B3261E]' : ''}`}>{m(v)}</td>)}
                            </tr>
                        ))}
                    </Table>
                </>
            )}
        </Card>
    );
}

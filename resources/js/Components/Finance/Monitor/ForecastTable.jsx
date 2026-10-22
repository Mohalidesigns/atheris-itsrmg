import Chip from '@/Components/Material/Chip';
import { MASK, fmtPct, localDate, moneyOrMask, humanize } from '@/Utils/finance';
import SeverityChip from './SeverityChip';

const varTone = (v) => (Number(v) > 0 ? 'text-[#B3261E]' : Number(v) < 0 ? 'text-[#2D7D46]' : '');

/** Lines with both EAC methods side by side; the active method is highlighted, overrides carry a Manual badge. */
export default function ForecastTable({ lines, totals, baseCurrency, money, onOverride, onClear }) {
    const fmt = (v) => moneyOrMask(v, baseCurrency, money, { decimals: 0 });
    const pct = (v) => (money ? fmtPct(v, { sign: true }) : MASK);
    const on = (line, m) => line.method === m ? 'bg-[#FFF8E6] font-semibold' : '';
    const th = 'whitespace-nowrap px-3 py-2 text-right font-medium';
    return (
        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table className="min-w-full text-left text-sm">
                <thead className="bg-gray-50 text-xs uppercase tracking-wide text-[#718096]">
                    <tr>
                        <th className="px-3 py-2 font-medium">Line</th>
                        <th className={th}>Baseline</th><th className={th}>Budget</th><th className={th}>Actual YTD</th>
                        <th className={th}>EAC remaining budget</th><th className={th}>EAC run rate</th><th className={th}>EAC</th>
                        <th className={th}>Variance</th><th className={th}>Var. %</th><th className={th}>Exhausted</th><th className="px-3 py-2" />
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                    {lines.length === 0 && <tr><td colSpan={11} className="px-5 py-10 text-center text-sm text-[#718096]">No baseline lines for this selection.</td></tr>}
                    {lines.map((l) => (
                        <tr key={l.line_id}>
                            <td className="min-w-[14rem] px-3 py-2"><span className="font-medium text-[#0A1F44]">{l.label}</span><span className="block text-[11px] text-[#718096]">{l.budget_code} · {l.cost_centre}</span></td>
                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{fmt(l.baseline)}</td>
                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{fmt(l.budget)}</td>
                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{fmt(l.actual_ytd)}</td>
                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${on(l, 'remaining_budget')}`}>{fmt(l.eac_remaining_budget)}</td>
                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${on(l, 'run_rate')}`}>{fmt(l.eac_run_rate)}</td>
                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${on(l, 'manual')}`}>
                                {fmt(l.eac)}
                                {l.manual ? <span className="ml-1 inline-block align-middle"><Chip size="xs" tone="tertiary" icon="edit" title={`${l.manual.reason}${l.manual.set_by ? ` (${l.manual.set_by}${l.manual.set_at ? `, ${localDate(l.manual.set_at)}` : ''})` : ''}`}>Manual</Chip></span>
                                    : <span className="block text-[10px] font-normal text-[#718096]">{humanize(l.method)}</span>}
                            </td>
                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${money ? varTone(l.variance) : ''}`}>{fmt(l.variance)}</td>
                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums"><span className={money ? varTone(l.variance_pct) : ''}>{pct(l.variance_pct)}</span> <SeverityChip severity={l.severity} /></td>
                            <td className="whitespace-nowrap px-3 py-2 text-right text-xs">{money ? (l.exhaustion_on ? localDate(l.exhaustion_on) : '—') : MASK}</td>
                            <td className="whitespace-nowrap px-3 py-2 text-right text-xs">
                                {l.can?.override && <button type="button" onClick={() => onOverride(l)} className="text-[#0A1F44] underline">{l.manual ? 'Change' : 'Override'}</button>}
                                {l.can?.override && l.manual && <button type="button" onClick={() => onClear(l)} className="ml-3 text-[#B3261E] underline">Clear</button>}
                            </td>
                        </tr>
                    ))}
                </tbody>
                {totals && (
                    <tfoot className="bg-[#F0F1F4] font-semibold">
                        <tr>
                            <td className="px-3 py-2 text-[#0A1F44]">Total</td>
                            {[totals.baseline, totals.budget, totals.actual_ytd, totals.eac_remaining_budget, totals.eac_run_rate, totals.eac, totals.variance].map((v, i) => <td key={i} className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{fmt(v)}</td>)}
                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{pct(totals.variance_pct)}</td>
                            <td colSpan={2} />
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}

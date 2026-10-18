import Icon from '@/Components/Material/Icon';
import { MASK, fmtPct, moneyOrMask } from '@/Utils/finance';
import SeverityChip from './SeverityChip';

const varTone = (v) => (Number(v) > 0 ? 'text-[#B3261E]' : Number(v) < 0 ? 'text-[#2D7D46]' : 'text-[#4A5568]');

/**
 * Budget-vs-actual pivot. Fixed columns carry the selected range; the `period` view adds a
 * budget / actual / variance group per period. Unallocated and totals rows stay at the foot.
 */
export default function BvaPivot({ matrix, view, baseCurrency, money, onDrill, onLine }) {
    const periods = view === 'period' ? (matrix.periods ?? []) : [];
    const fmt = (v) => moneyOrMask(v, baseCurrency, money, { decimals: 0 });
    const pct = (v) => (money ? fmtPct(v, { sign: true }) : MASK);
    const span = periods.length * 3;

    const cells = (r, bold = false) => (
        <>
            {periods.map((p) => {
                const bp = r.by_period?.[p.id] ?? {};
                return [
                    <td key={`${p.id}b`} className="whitespace-nowrap border-l border-gray-100 px-3 py-1.5 text-right tabular-nums">{fmt(bp.budget)}</td>,
                    <td key={`${p.id}a`} className="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{fmt(bp.actual)}</td>,
                    <td key={`${p.id}v`} className={`whitespace-nowrap px-3 py-1.5 text-right tabular-nums ${money ? varTone(bp.variance) : ''}`}>{fmt(bp.variance)}</td>,
                ];
            })}
            <td className={`whitespace-nowrap border-l border-gray-100 px-3 py-1.5 text-right tabular-nums ${bold ? 'font-semibold' : ''}`}>{fmt(r.budget)}</td>
            <td className={`whitespace-nowrap px-3 py-1.5 text-right tabular-nums ${bold ? 'font-semibold' : ''}`}>{fmt(r.actual)}</td>
            <td className="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{fmt(r.committed)}</td>
            <td className="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{fmt(r.available)}</td>
            <td className="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{fmt(r.forecast)}</td>
            <td className={`whitespace-nowrap px-3 py-1.5 text-right tabular-nums ${money ? varTone(r.variance) : ''} ${bold ? 'font-semibold' : ''}`}>{fmt(r.variance)}</td>
            <td className={`whitespace-nowrap px-3 py-1.5 text-right tabular-nums ${money ? varTone(r.variance_pct) : ''}`}>{pct(r.variance_pct)}</td>
        </>
    );

    return (
        <div className="max-h-[70vh] overflow-auto rounded-lg border border-gray-200 bg-white">
            <table className="min-w-full text-left text-sm">
                <thead className="sticky top-0 z-20 bg-gray-50 text-xs uppercase tracking-wide text-[#718096]">
                    {periods.length > 0 && (
                        <tr>
                            <th className="sticky left-0 z-30 bg-gray-50" />
                            {periods.map((p) => <th key={p.id} colSpan={3} className="border-l border-gray-200 px-3 py-1 text-center font-medium">{p.name}{p.elapsed === false ? ' (in progress)' : ''}</th>)}
                            <th colSpan={7} className="border-l border-gray-200 px-3 py-1 text-center font-medium">Selected range</th>
                            <th />
                        </tr>
                    )}
                    <tr>
                        <th className="sticky left-0 z-30 min-w-[12rem] bg-gray-50 px-3 py-2 font-medium">Group</th>
                        {periods.map((p) => [
                            <th key={`${p.id}b`} className="border-l border-gray-200 px-3 py-2 text-right font-medium">Budget</th>,
                            <th key={`${p.id}a`} className="px-3 py-2 text-right font-medium">Actual</th>,
                            <th key={`${p.id}v`} className="px-3 py-2 text-right font-medium">Var.</th>,
                        ])}
                        <th className="border-l border-gray-200 px-3 py-2 text-right font-medium">Budget</th>
                        <th className="px-3 py-2 text-right font-medium">Actual</th>
                        <th className="px-3 py-2 text-right font-medium" title="Annual, as of today (not phased by period)">Open commitments</th>
                        <th className="px-3 py-2 text-right font-medium">Available</th>
                        <th className="px-3 py-2 text-right font-medium">Forecast</th>
                        <th className="px-3 py-2 text-right font-medium">Variance</th>
                        <th className="px-3 py-2 text-right font-medium">Var. %</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                    {matrix.rows.length === 0 && <tr><td colSpan={9 + span + 1} className="px-5 py-10 text-center text-sm text-[#718096]">No budget lines match these filters.</td></tr>}
                    {matrix.rows.map((r) => {
                        const action = r.line_id ? () => onLine(r) : r.drill ? () => onDrill(r) : null;
                        return (
                            <tr key={r.key} className="bg-white hover:bg-[#FAFAFB]">
                                <td className="sticky left-0 z-10 bg-inherit px-3 py-1.5">
                                    {action ? (
                                        <button type="button" onClick={action} className="flex items-center gap-1 text-left font-medium text-[#0A1F44] hover:underline" title={r.line_id ? 'Show transactions' : 'Drill down'}>
                                            {r.label}<Icon name={r.line_id ? 'receipt_long' : 'subdirectory_arrow_right'} size={14} className="text-[#A0AEC0]" />
                                        </button>
                                    ) : <span className="font-medium text-[#2D3748]">{r.label}</span>}
                                    {r.sublabel && <span className="block text-[11px] text-[#718096]">{r.sublabel}</span>}
                                </td>
                                {cells(r)}
                                <td className="whitespace-nowrap px-3 py-1.5"><SeverityChip severity={r.severity} /></td>
                            </tr>
                        );
                    })}
                </tbody>
                <tfoot className="text-sm">
                    {matrix.unallocated && (
                        <tr className="h-9">
                            <td className="sticky bottom-[2.25rem] left-0 z-10 bg-[#FFF8E6] px-3 py-1.5 font-medium text-[#5F4300]">
                                <span className="flex items-center gap-1"><Icon name="help" size={14} />Unallocated actuals</span>
                                <span className="block text-[11px] font-normal">Unmatched or unbudgeted spend</span>
                            </td>
                            {periods.length > 0 && <td colSpan={span} className="sticky bottom-[2.25rem] bg-[#FFF8E6]" />}
                            <td className="sticky bottom-[2.25rem] bg-[#FFF8E6] px-3 py-1.5 text-right tabular-nums">{fmt(matrix.unallocated.budget)}</td>
                            <td className="sticky bottom-[2.25rem] bg-[#FFF8E6] px-3 py-1.5 text-right tabular-nums">{fmt(matrix.unallocated.actual)}</td>
                            <td colSpan={5} className="sticky bottom-[2.25rem] bg-[#FFF8E6]" />
                            <td className="sticky bottom-[2.25rem] bg-[#FFF8E6]" />
                        </tr>
                    )}
                    <tr className="h-9 font-semibold">
                        <td className="sticky bottom-0 left-0 z-10 bg-[#F0F1F4] px-3 py-1.5 text-[#0A1F44]">Total</td>
                        {periods.map((p) => {
                            const bp = matrix.totals.by_period?.[p.id] ?? {};
                            return [
                                <td key={`${p.id}b`} className="sticky bottom-0 whitespace-nowrap border-l border-gray-200 bg-[#F0F1F4] px-3 py-1.5 text-right tabular-nums">{fmt(bp.budget)}</td>,
                                <td key={`${p.id}a`} className="sticky bottom-0 whitespace-nowrap bg-[#F0F1F4] px-3 py-1.5 text-right tabular-nums">{fmt(bp.actual)}</td>,
                                <td key={`${p.id}v`} className="sticky bottom-0 whitespace-nowrap bg-[#F0F1F4] px-3 py-1.5 text-right tabular-nums">{fmt(bp.variance)}</td>,
                            ];
                        })}
                        {[matrix.totals.budget, matrix.totals.actual, matrix.totals.committed, matrix.totals.available, matrix.totals.forecast, matrix.totals.variance].map((v, i) => (
                            <td key={i} className={`sticky bottom-0 whitespace-nowrap bg-[#F0F1F4] px-3 py-1.5 text-right tabular-nums ${i === 0 ? 'border-l border-gray-200' : ''}`}>{fmt(v)}</td>
                        ))}
                        <td className="sticky bottom-0 whitespace-nowrap bg-[#F0F1F4] px-3 py-1.5 text-right tabular-nums">{pct(matrix.totals.variance_pct)}</td>
                        <td className="sticky bottom-0 bg-[#F0F1F4]" />
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

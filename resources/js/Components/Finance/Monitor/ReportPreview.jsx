import { Empty } from '@/Components/Finance/Settings/ui';
import { MASK, fmtPct, localDate, moneyOrMask } from '@/Utils/finance';

/** First rows of a report, typed per column; masked money and percentages show the mask. */
export default function ReportPreview({ preview, baseCurrency, money }) {
    if (!preview) return null;
    const cell = (col, v) => {
        if (col.type === 'money') return money ? moneyOrMask(v, baseCurrency, true, { decimals: 0 }) : MASK;
        if (col.type === 'pct') return money ? fmtPct(v) : MASK;
        if (col.type === 'date') return localDate(v);
        if (v === null || v === undefined || v === '') return '—';
        return String(v);
    };
    const num = (c) => ['money', 'pct', 'int'].includes(c.type);
    return (
        <div className="border-t border-gray-100">
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-xs text-[#4A5568]">
                <span>Preview: {preview.rows.length} of {preview.row_count} rows{preview.truncated ? ' (export is capped, narrow the filters)' : ''}</span>
            </div>
            {preview.rows.length === 0 ? <Empty>No rows for these filters.</Empty> : (
                <div className="max-h-96 overflow-auto">
                    <table className="min-w-full text-left text-xs">
                        <thead className="sticky top-0 bg-gray-50 uppercase tracking-wide text-[#718096]">
                            <tr>{preview.columns.map((c) => <th key={c.key} className={`whitespace-nowrap px-3 py-2 font-medium ${num(c) ? 'text-right' : ''}`}>{c.label}</th>)}</tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {preview.rows.map((r, i) => <tr key={i}>{preview.columns.map((c) => <td key={c.key} className={`px-3 py-1.5 ${num(c) ? 'whitespace-nowrap text-right tabular-nums' : ''}`}>{cell(c, r[c.key])}</td>)}</tr>)}
                        </tbody>
                        {preview.totals && (
                            <tfoot className="bg-[#F0F1F4] font-semibold">
                                <tr>{preview.columns.map((c, i) => <td key={c.key} className={`px-3 py-1.5 ${num(c) ? 'whitespace-nowrap text-right tabular-nums' : ''}`}>{i === 0 && preview.totals[c.key] === undefined ? 'Total' : preview.totals[c.key] === undefined ? '' : cell(c, preview.totals[c.key])}</td>)}</tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            )}
        </div>
    );
}

import { Card } from '@/Components/Finance/Settings/ui';
import { MASK, formatMoney, pctOf } from '@/Utils/finance';

/** Budget availability for a line: budget, committed, actual, available, with a utilisation bar. */
export default function AvailabilityCard({ availability, baseCurrency, money = true, extra, title = 'Budget availability' }) {
    if (!availability) return <Card title={title}><p className="px-5 py-6 text-center text-sm text-[#718096]">No budget line selected. This request is unbudgeted.</p></Card>;
    const a = availability;
    const m = (v) => (money && v !== null && v !== undefined ? formatMoney(v, baseCurrency, { decimals: 0 }) : MASK);
    const used = a.used_pct !== null && a.used_pct !== undefined ? Number(a.used_pct) : pctOf(Number(a.committed ?? 0) + Number(a.actual ?? 0), a.budget);
    const pct = Math.max(0, Math.min(100, used ?? 0));
    const tone = used >= 100 ? '#B3261E' : used >= 85 ? '#E5A100' : '#2D7D46';
    return (
        <Card title={title}>
            <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm sm:grid-cols-4">
                {[['Budget', a.budget], ['Committed', a.committed], ['Actual', a.actual], ['Available', a.available]].map(([k, v]) => (
                    <div key={k}><dt className="text-xs text-[#718096]">{k}</dt><dd className={`font-medium tabular-nums ${k === 'Available' && Number(v) < 0 ? 'text-[#B3261E]' : 'text-[#2D3748]'}`}>{m(v)}</dd></div>
                ))}
            </dl>
            {money && (
                <div className="px-5 pb-4">
                    <div className="h-2 overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label="Budget used"><div className="h-full" style={{ width: `${pct}%`, backgroundColor: tone }} /></div>
                    <p className="mt-1 text-xs text-[#718096]">{used !== null && used !== undefined ? `${used.toFixed(1)}% used` : ''}{a.period ? ` · ${a.period.name}: ${m(a.period.actual)} of ${m(a.period.budget)}` : ''}</p>
                </div>
            )}
            {extra}
        </Card>
    );
}

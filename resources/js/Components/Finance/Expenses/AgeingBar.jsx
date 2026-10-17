import { Card } from '@/Components/Finance/Settings/ui';
import { moneyOrMask } from '@/Utils/finance';

const BUCKETS = [['current', 'Current', '#2D7D46'], ['d1_30', '1-30 days', '#E5A100'], ['d31_60', '31-60 days', '#D97706'], ['d61_90', '61-90 days', '#C2410C'], ['d90_plus', 'Over 90 days', '#B3261E']];

/** Payables ageing: stacked bar plus legend, in base currency. */
export default function AgeingBar({ ageing, baseCurrency, money = true }) {
    const total = BUCKETS.reduce((s, [k]) => s + Number(ageing?.[k] ?? 0), 0);
    return (
        <Card title="Payables ageing (unpaid, base currency)">
            <div className="px-5 py-4">
                <div className="flex h-3 overflow-hidden rounded bg-gray-100" role="img" aria-label="Payables ageing">
                    {money && total > 0 && BUCKETS.map(([k, , c]) => Number(ageing[k]) > 0 && <div key={k} style={{ width: `${(Number(ageing[k]) / total) * 100}%`, backgroundColor: c }} />)}
                </div>
                <dl className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {BUCKETS.map(([k, label, c]) => <div key={k}><dt className="flex items-center gap-1 text-xs text-[#718096]"><span className="inline-block h-2 w-2 rounded-sm" style={{ backgroundColor: c }} />{label}</dt><dd className="text-sm font-medium tabular-nums text-[#2D3748]">{moneyOrMask(ageing?.[k], baseCurrency, money, { decimals: 0 })}</dd></div>)}
                </dl>
            </div>
        </Card>
    );
}

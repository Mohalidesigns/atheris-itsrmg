import { Card } from '@/Components/Finance/Settings/ui';
import { formatMoney, humanize } from '@/Utils/finance';

/** Approval context captured when the request started (DoA band, amount, etc.). */
export default function BandSnapshot({ context, baseCurrency }) {
    if (!context || typeof context !== 'object') return null;
    const entries = Object.entries(context).filter(([, v]) => v !== null && typeof v !== 'object');
    if (entries.length === 0) return null;
    return (
        <Card title="Approval context">
            <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm sm:grid-cols-3">
                {entries.map(([k, v]) => (
                    <div key={k}>
                        <dt className="text-xs text-[#718096]">{humanize(k)}</dt>
                        <dd className="text-[#2D3748]">{/amount|total/.test(k) && !isNaN(Number(v)) ? formatMoney(v, baseCurrency) : String(v)}</dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

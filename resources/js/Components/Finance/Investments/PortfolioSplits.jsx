import { Card } from '@/Components/Finance/Settings/ui';
import { CHART_COLOURS, num } from '@/Components/Finance/Budgets/Charts';
import { RGT_LABELS, formatPct, humanize, invMoney } from '@/Utils/finance';

function SplitList({ title, rows, total, baseCurrency, money }) {
    return (
        <Card title={title}>
            <ul className="space-y-3 px-5 py-4">
                {rows.map((r, i) => {
                    const share = total > 0 ? (r.count / total) * 100 : 0;
                    return (
                        <li key={r.key}>
                            <div className="mb-1 flex justify-between gap-2 text-xs text-[#4A5568]"><span>{r.label}</span><span className="tabular-nums">{r.count} · {invMoney(r.bac, baseCurrency, money, { decimals: 0 })}</span></div>
                            <div className="h-2 overflow-hidden rounded bg-gray-100"><div className="h-full" style={{ width: `${share}%`, backgroundColor: CHART_COLOURS[i % CHART_COLOURS.length] }} /></div>
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}

/** Run/grow/transform, driver and regulatory share by investment count (bars) with BAC alongside. */
export default function PortfolioSplits({ splits, baseCurrency, money }) {
    const rgt = ['run', 'grow', 'transform'].map((k) => ({ key: k, label: RGT_LABELS[k], count: num(splits.rgt?.[k]?.count), bac: splits.rgt?.[k]?.bac }));
    const drivers = Object.entries(splits.driver ?? {}).map(([k, v]) => ({ key: k, label: humanize(k), count: num(v.count), bac: v.bac }));
    const totalR = rgt.reduce((a, r) => a + r.count, 0);
    const totalD = drivers.reduce((a, r) => a + r.count, 0);
    const reg = splits.regulatory ?? { count: 0, bac: null, share_pct: null };
    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <SplitList title="Run, grow, transform" rows={rgt} total={totalR} baseCurrency={baseCurrency} money={money} />
            <SplitList title="By driver" rows={drivers} total={totalD} baseCurrency={baseCurrency} money={money} />
            <Card title="Regulatory share">
                <div className="px-5 py-6 text-center">
                    <p className="text-3xl font-semibold tabular-nums text-[#0A1F44]">{money ? (reg.share_pct === null || reg.share_pct === undefined ? '—' : formatPct(reg.share_pct, 0)) : 'Hidden'}</p>
                    <p className="mt-1 text-xs text-[#718096]">{reg.count} regulatory investment{reg.count === 1 ? '' : 's'} · {invMoney(reg.bac, baseCurrency, money, { decimals: 0 })}</p>
                </div>
            </Card>
        </div>
    );
}

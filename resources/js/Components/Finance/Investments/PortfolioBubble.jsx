import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { compactMoney } from '@/Components/Finance/Budgets/Charts';
import { RGT_LABELS, invMoney } from '@/Utils/finance';
import { CartesianGrid, Legend, ResponsiveContainer, Scatter, ScatterChart, Tooltip, XAxis, YAxis, ZAxis } from 'recharts';

const RGT_COLOURS = { run: '#74777F', grow: '#1D4ED8', transform: '#C9A86A' };

/** x = priority score, y = NPV, size = BAC, colour = run/grow/transform. Hidden when amounts are masked. */
export default function PortfolioBubble({ bubble, baseCurrency, money }) {
    if (!money) return <Card title="Value against priority"><Empty>Hidden: NPV and budget are not visible to your role.</Empty></Card>;
    const rows = bubble.filter((b) => b.x !== null && b.y !== null && b.x !== undefined && b.y !== undefined).map((b) => ({ ...b, x: Number(b.x), y: Number(b.y), z: Math.max(1, Number(b.z) || 1) }));
    return (
        <Card title="Value against priority">
            {rows.length === 0 ? <Empty>No scored investments with a business case yet.</Empty> : (
                <div className="h-80 px-2 pb-2" role="img" aria-label="Bubble chart of NPV against priority score">
                    <ResponsiveContainer width="100%" height="100%">
                        <ScatterChart margin={{ top: 12, right: 16, bottom: 20, left: 8 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                            <XAxis type="number" dataKey="x" name="Priority score" domain={[0, 100]} tick={{ fontSize: 11 }} label={{ value: 'Priority score', position: 'insideBottom', offset: -10, fontSize: 11 }} />
                            <YAxis type="number" dataKey="y" name="NPV" tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, baseCurrency)} width={70} />
                            <ZAxis type="number" dataKey="z" range={[60, 900]} name="BAC" />
                            <Tooltip cursor={{ strokeDasharray: '3 3' }} content={({ active, payload }) => {
                                if (!active || !payload?.length) return null;
                                const p = payload[0].payload;
                                return <div className="rounded border border-gray-200 bg-white p-2 text-xs shadow"><p className="font-semibold">{p.label}</p><p>Score {p.x}</p><p>NPV {invMoney(p.y, baseCurrency, true, { decimals: 0 })}</p><p>BAC {invMoney(p.z, baseCurrency, true, { decimals: 0 })}</p></div>;
                            }} />
                            <Legend wrapperStyle={{ fontSize: 11 }} />
                            {['run', 'grow', 'transform'].map((k) => <Scatter key={k} name={RGT_LABELS[k]} data={rows.filter((r) => r.rgt === k)} fill={RGT_COLOURS[k]} fillOpacity={0.75} />)}
                        </ScatterChart>
                    </ResponsiveContainer>
                </div>
            )}
        </Card>
    );
}

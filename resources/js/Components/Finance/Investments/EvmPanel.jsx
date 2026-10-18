import Chip from '@/Components/Material/Chip';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import HealthChip from '@/Components/Finance/Investments/HealthChip';
import { invMoney, invNumber, localDate, stageLabel } from '@/Utils/finance';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

function Tile({ label, value, hint, tone = '' }) {
    return (
        <div className="rounded-lg border border-gray-100 bg-white p-3">
            <dt className="text-xs text-[#718096]">{label}</dt>
            <dd className={`mt-0.5 truncate text-lg font-semibold tabular-nums ${tone || 'text-[#0A1F44]'}`}>{value}</dd>
            {hint && <p className="text-[11px] text-[#718096]">{hint}</p>}
        </div>
    );
}

/** Earned-value panel: BAC against allocated lines, AC, committed, EV (manager-reported % complete), CV, CPI, EAC, VAC. */
export default function EvmPanel({ investment, evm, baseCurrency, can, onProgress }) {
    const money = can.viewAmounts !== false;
    const m = (v) => invMoney(v, baseCurrency, money, { decimals: 0 });
    if (!evm) return <Card title="Cost and earned value"><Empty>No financial baseline yet.</Empty></Card>;
    const neg = (v) => (money && v !== null && v !== undefined && Number(v) < 0 ? 'text-[#B3261E]' : '');
    const cpi = evm.cpi === null || evm.cpi === undefined ? null : Number(evm.cpi);
    const gap = evm.funding_gap !== null && evm.funding_gap !== undefined && money && Number(evm.funding_gap) > 0;
    return (
        <div className="space-y-4">
            <Card title="Cost and earned value" right={<span className="flex items-center gap-2"><HealthChip value={evm.health} />{can.progress && <button type="button" onClick={onProgress} className="text-xs text-[#0A1F44] underline">Update progress</button>}</span>}>
                <dl className="grid grid-cols-2 gap-3 px-5 py-4 lg:grid-cols-4">
                    <Tile label="Budget at completion (BAC)" value={m(evm.bac)} hint={evm.bac === null && money ? 'Set when the business case is approved' : null} />
                    <Tile label="Allocated to lines" value={m(evm.allocated)} hint={gap ? `Funding gap ${m(evm.funding_gap)}` : money && evm.bac !== null ? 'Fully funded' : null} tone={gap ? 'text-[#8A6400]' : ''} />
                    <Tile label="Actual cost (AC)" value={m(evm.ac)} />
                    <Tile label="Committed" value={m(evm.committed)} />
                    <Tile label="Earned value (EV)" value={m(evm.ev)} hint={`${Number(evm.percent_complete ?? 0).toFixed(0)}% complete${investment.percent_complete_as_of ? `, as of ${localDate(investment.percent_complete_as_of)}` : ''}`} />
                    <Tile label="Cost variance (CV)" value={m(evm.cv)} tone={neg(evm.cv)} hint="EV minus AC" />
                    <Tile label="Cost performance (CPI)" value={invNumber(evm.cpi, money)} tone={money && cpi !== null && cpi < 0.95 ? 'text-[#B3261E]' : ''} hint={money && cpi === null ? 'No spend yet' : 'EV divided by AC'} />
                    <Tile label="Estimate at completion" value={m(evm.eac)} hint={evm.eac_method === 'manual' ? null : 'BAC divided by CPI'} />
                </dl>
                <div className="flex flex-wrap items-center gap-3 border-t border-gray-100 px-5 py-2 text-xs text-[#4A5568]">
                    {evm.eac_method === 'manual' && <Chip size="xs" tone="tertiary" icon="edit">Manual EAC{investment.eac_reason ? `: ${investment.eac_reason}` : ''}</Chip>}
                    <span>Variance at completion (VAC) <b className={`tabular-nums ${neg(evm.vac)}`}>{m(evm.vac)}</b></span>
                    {investment.percent_complete_as_of === null && <span>Progress has not been reported yet.</span>}
                </div>
            </Card>
            {money ? (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <Card title="Spend by stage">
                        {(evm.spend_by_stage ?? []).length === 0 ? <Empty>No spend recorded yet.</Empty> : (
                            <div className="h-56 px-2" role="img" aria-label="Spend by stage">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={evm.spend_by_stage.map((r) => ({ name: stageLabel(r.stage), amount: num(r.amount) }))} margin={{ top: 8, right: 16, left: 8 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" /><XAxis dataKey="name" tick={{ fontSize: 11 }} /><YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, baseCurrency)} width={70} />
                                        <Tooltip formatter={(v) => invMoney(v, baseCurrency, true, { decimals: 0 })} /><Bar dataKey="amount" name="Actual" fill="#0A1F44" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </Card>
                    <Card title="Planned against actual by year">
                        {(evm.spend_by_year ?? []).length === 0 ? <Empty>No yearly figures yet.</Empty> : (
                            <div className="h-56 px-2" role="img" aria-label="Planned against actual by year">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={evm.spend_by_year.map((r) => ({ year: String(r.year), planned: num(r.planned), actual: num(r.actual) }))} margin={{ top: 8, right: 16, left: 8 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" /><XAxis dataKey="year" tick={{ fontSize: 11 }} /><YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, baseCurrency)} width={70} />
                                        <Tooltip formatter={(v) => invMoney(v, baseCurrency, true, { decimals: 0 })} /><Legend wrapperStyle={{ fontSize: 11 }} />
                                        <Bar dataKey="planned" name="Planned" fill="#C9A86A" /><Bar dataKey="actual" name="Actual" fill="#0A1F44" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </Card>
                </div>
            ) : <p className="text-sm text-[#718096]">Charts are hidden: amounts are not visible to your role.</p>}
        </div>
    );
}

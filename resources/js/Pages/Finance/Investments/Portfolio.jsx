import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import HealthChip from '@/Components/Finance/Investments/HealthChip';
import InvestmentsNav from '@/Components/Finance/Investments/InvestmentsNav';
import CapabilitySpend from '@/Components/Finance/Investments/CapabilitySpend';
import PortfolioBubble from '@/Components/Finance/Investments/PortfolioBubble';
import PortfolioRanking from '@/Components/Finance/Investments/PortfolioRanking';
import PortfolioSplits from '@/Components/Finance/Investments/PortfolioSplits';
import KpiTile from '@/Components/Finance/Monitor/KpiTile';
import { compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { BTN_SECONDARY, FIELD, LABEL, formatPct, invMoney, invNumber, stageLabel } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export default function Portfolio(props) {
    const { filters, fiscalYears, envelope, ranking, splits, pipeline, costs, benefits, atRisk, bubble, capabilities, baseCurrency, can } = props;
    const money = can.viewAmounts !== false;
    const [env, setEnv] = useState(filters.envelope ?? '');
    const go = (next) => {
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        router.get(route('finance.portfolio'), clean, { preserveScroll: true, preserveState: true, replace: true });
    };
    const m = (v) => invMoney(v, baseCurrency, money, { decimals: 0 });
    const cost = [{ name: 'Portfolio', BAC: num(costs.bac), AC: num(costs.ac), EAC: num(costs.eac), Committed: num(costs.committed) }];

    return (
        <AuthenticatedLayout header="Investment Portfolio">
            <Head title="Investment Portfolio" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Investments & Value', href: route('finance.investments.index') }, { label: 'Portfolio' }]}
                title="Investment Portfolio" subtitle="Priority ranking against the funding envelope, mix, cost performance and benefits realisation" />
            <InvestmentsNav active="portfolio" />

            <FiltersBar cols="lg:grid-cols-4">
                <SelectFilter id="pf-fy" label="Funding year" value={filters.fiscal_year_id} onChange={(e) => go({ fiscal_year_id: e.target.value, envelope: env })} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                {money && (
                    <div className="col-span-2 lg:col-span-2">
                        <label className={LABEL} htmlFor="pf-env">Envelope what-if ({baseCurrency})</label>
                        <div className="flex gap-2">
                            <input id="pf-env" type="number" min="0" step="any" value={env} onChange={(e) => setEnv(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && go({ fiscal_year_id: filters.fiscal_year_id, envelope: env })} placeholder={envelope.default ?? ''} className={FIELD} />
                            <button type="button" onClick={() => go({ fiscal_year_id: filters.fiscal_year_id, envelope: env })} className={BTN_SECONDARY}>Apply</button>
                            {filters.envelope && <button type="button" onClick={() => { setEnv(''); go({ fiscal_year_id: filters.fiscal_year_id }); }} className="text-xs text-[#0A1F44] underline">Reset</button>}
                        </div>
                        <p className="mt-1 text-[11px] text-[#718096]">Default is the change-the-bank (grow and transform) baseline of the year: {m(envelope.default)}. A what-if value is not saved.</p>
                    </div>
                )}
            </FiltersBar>

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiTile label="Budget at completion" value={m(costs.bac)} icon="account_balance_wallet" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
                <KpiTile label="Actual cost" value={m(costs.ac)} sub={money ? `Committed ${m(costs.committed)}` : null} icon="payments" tint="bg-[#E7F4EC] text-[#2D7D46]" />
                <KpiTile label="Estimate at completion" value={m(costs.eac)} icon="trending_up" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <KpiTile label="Benefits realised" value={money ? (benefits.pct === null || benefits.pct === undefined ? '—' : formatPct(benefits.pct, 0)) : 'Hidden'} sub={money ? `${invMoney(benefits.realised, baseCurrency, true, { decimals: 0 })} of ${invMoney(benefits.planned, baseCurrency, true, { decimals: 0 })}` : null} icon="verified" />
            </div>

            <div className="space-y-4">
                <PortfolioRanking ranking={ranking} envelope={envelope} baseCurrency={baseCurrency} money={money} />
                <PortfolioSplits splits={splits} baseCurrency={baseCurrency} money={money} />

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <Card title="Pipeline by stage">
                        {pipeline.length === 0 ? <Empty>No investments in the pipeline.</Empty> : (
                            <div className="h-60 px-2" role="img" aria-label="Investments by stage">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={pipeline.map((p) => ({ name: stageLabel(p.stage), count: num(p.count), value: num(p.value) }))} margin={{ top: 8, right: 16, left: 0 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" /><XAxis dataKey="name" tick={{ fontSize: 10 }} interval={0} angle={-20} textAnchor="end" height={50} /><YAxis allowDecimals={false} tick={{ fontSize: 11 }} width={30} />
                                        <Tooltip formatter={(v, n) => (n === 'Value' ? invMoney(v, baseCurrency, money, { decimals: 0 }) : v)} /><Bar dataKey="count" name="Investments" fill="#0A1F44" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </Card>
                    <Card title="Planned against actual">
                        {!money ? <Empty>Hidden: amounts are not visible to your role.</Empty> : (
                            <div className="h-60 px-2" role="img" aria-label="Budget, actual and estimate at completion">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={cost} margin={{ top: 8, right: 16, left: 8 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" /><XAxis dataKey="name" tick={{ fontSize: 11 }} /><YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, baseCurrency)} width={70} />
                                        <Tooltip formatter={(v) => invMoney(v, baseCurrency, true, { decimals: 0 })} /><Legend wrapperStyle={{ fontSize: 11 }} />
                                        <Bar dataKey="BAC" fill="#C9A86A" /><Bar dataKey="AC" fill="#0A1F44" /><Bar dataKey="EAC" fill="#1D4ED8" /><Bar dataKey="Committed" fill="#A0AEC0" />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </Card>
                </div>

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <Card title="Benefits realisation">
                        {(benefits.by_investment ?? []).length === 0 ? <Empty>No benefits registered.</Empty> : (
                            <ul className="divide-y divide-gray-50">
                                {benefits.by_investment.map((b) => (
                                    <li key={b.id} className="px-5 py-2.5">
                                        <div className="mb-1 flex justify-between gap-2 text-sm"><Link className="truncate text-[#0A1F44] hover:underline" href={route('finance.investments.show', { investment: b.id, tab: 'benefits' })}>{b.title}</Link><span className="tabular-nums text-[#4A5568]">{money ? (b.pct === null || b.pct === undefined ? '—' : formatPct(b.pct, 0)) : 'Hidden'}</span></div>
                                        <div className="h-1.5 overflow-hidden rounded bg-gray-100"><div className="h-full bg-[#2D7D46]" style={{ width: `${money ? Math.max(0, Math.min(100, num(b.pct))) : 0}%` }} /></div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                    <Card title="At risk">
                        {atRisk.length === 0 ? <Empty>No investment is at risk.</Empty> : (
                            <ul className="divide-y divide-gray-50">
                                {atRisk.map((r) => (
                                    <li key={r.id} className="flex items-center gap-3 px-5 py-2.5">
                                        <div className="min-w-0 flex-1"><Link href={r.href} className="block truncate text-sm font-medium text-[#0A1F44] hover:underline"><span className="font-mono text-xs text-[#718096]">{r.investment_code}</span> {r.title}</Link>
                                            <p className="text-[11px] text-[#718096]">CPI {invNumber(r.cpi, money)}{r.days_late > 0 ? ` · ${r.days_late} days late` : ''}</p></div>
                                        <HealthChip value={r.health || r.status} size="xs" />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <PortfolioBubble bubble={bubble} baseCurrency={baseCurrency} money={money} />
                <CapabilitySpend capabilities={capabilities} baseCurrency={baseCurrency} money={money} />
            </div>
        </AuthenticatedLayout>
    );
}

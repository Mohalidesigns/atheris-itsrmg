import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { MonthlyBvA, SpendByTower, SplitBar } from '@/Components/Finance/Monitor/DashboardCharts';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import KpiTile from '@/Components/Finance/Monitor/KpiTile';
import SeverityChip from '@/Components/Finance/Monitor/SeverityChip';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { CONTRACT_TONES, MASK, stageLabel, daysLabel, fmtPct, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const HIDDEN = 'Hidden';

export default function Dashboard(props) {
    const { fiscalYears, selectedFiscalYear, asOfPeriod, audience, costCentres, filters, metrics: m, spendByCategory = [], monthlyBvA = [], topVariances = [], renewalsDue = [], pendingApprovals = [], can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [f, setF] = useState({ ...filters });
    const go = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        router.get(route('finance.dashboard'), clean, { preserveScroll: true, preserveState: true, replace: true });
    };
    const set = (k) => (e) => go({ ...f, [k]: e.target.value });
    const fmt = (v) => (money ? moneyOrMask(v, baseCurrency, true, { decimals: 0 }) : HIDDEN);
    const pct = (v) => (money ? fmtPct(v, { sign: true }) : HIDDEN);
    const monitorHref = (name, extra = {}) => route(name, { fiscal_year_id: selectedFiscalYear?.id, ...extra });
    const variance = Number(m.variance_ytd);

    return (
        <AuthenticatedLayout header="IT Finance Dashboard">
            <Head title="IT Finance Dashboard" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Dashboard' }]} title="IT Finance Dashboard"
                subtitle={`${selectedFiscalYear ? selectedFiscalYear.year_label : 'No fiscal year'}${asOfPeriod ? `, as of ${asOfPeriod.name}` : ''} · ${baseCurrency}`}
                actions={<Chip tone={audience === 'owner' ? 'tertiary' : 'primary'} icon={audience === 'owner' ? 'person' : 'account_balance'}>{audience === 'owner' ? 'Your cost centres' : 'All IT spend'}</Chip>} />

            <FiltersBar cols="lg:grid-cols-4">
                <SelectFilter id="d-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="d-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
            </FiltersBar>

            {!selectedFiscalYear ? <Card><Empty>No fiscal year with a baseline budget yet. Set one up under Budgets.</Empty></Card> : (
                <>
                    <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                        <KpiTile label="Budget" value={fmt(m.budget_total)} sub={money ? `YTD ${moneyOrMask(m.budget_ytd, baseCurrency, true, { decimals: 0 })}` : null} icon="account_balance_wallet" href={monitorHref('finance.monitor.bva')} />
                        <KpiTile label="Actual YTD" value={fmt(m.actual_ytd)} sub={money ? `Open commitments ${moneyOrMask(m.committed, baseCurrency, true, { decimals: 0 })}` : null} icon="payments" tint="bg-[#F7F1E6] text-[#8A6A2E]" href={monitorHref('finance.monitor.bva')} />
                        <KpiTile label="Forecast" value={fmt(m.forecast_fy)} icon="trending_up" tint="bg-[#EAF0FB] text-[#1D4ED8]" href={monitorHref('finance.monitor.forecast')} />
                        <KpiTile label="Variance YTD" value={money ? pct(m.variance_pct) : HIDDEN} sub={money ? `${variance > 0 ? 'Over' : variance < 0 ? 'Under' : 'On'} by ${moneyOrMask(Math.abs(variance), baseCurrency, true, { decimals: 0 })}` : null} icon="compare_arrows" tint={variance > 0 ? 'bg-[#F9DEDC] text-[#8C1D18]' : 'bg-[#E7F4EC] text-[#2D7D46]'} alert={money && variance > 0} href={monitorHref('finance.monitor.variances')} />
                        <KpiTile label="Available" value={fmt(m.available)} sub="After actuals and commitments" icon="savings" tint="bg-[#E7F4EC] text-[#2D7D46]" />
                        <KpiTile label="Unbudgeted" value={m.unbudgeted_count} sub={money ? moneyOrMask(m.unbudgeted_amount, baseCurrency, true, { decimals: 0 }) : HIDDEN} icon="report" tint="bg-[#F9DEDC] text-[#8C1D18]" alert={m.unbudgeted_count > 0} />
                        <KpiTile label="Overdue invoices" value={m.invoices_overdue} icon="receipt_long" tint="bg-[#FFF6E0] text-[#8A6400]" alert={m.invoices_overdue > 0} href={route('finance.invoices.index')} />
                        <KpiTile label="Renewals 30 / 60 / 90" value={`${m.renewals_due_30} / ${m.renewals_due_60} / ${m.renewals_due_90}`} sub={`${m.contracts_active} active contracts`} icon="event_repeat" tint="bg-[#FFF6E0] text-[#8A6400]" href={route('finance.calendar')} />
                        <KpiTile label="Notice passed" value={m.contracts_passed} sub="Contracts past their notice date" icon="alarm_off" tint="bg-[#F9DEDC] text-[#8C1D18]" alert={m.contracts_passed > 0} href={route('finance.contracts.index')} />
                        <KpiTile label="Variances" value={`${m.variances.red} red · ${m.variances.amber} amber`} sub={`${m.variances.unexplained_red} red unexplained`} icon="warning" tint="bg-[#FFF6E0] text-[#8A6400]" alert={m.variances.unexplained_red > 0} href={monitorHref('finance.monitor.variances')} />
                    </div>

                    {!money ? (
                        <p className="mb-4 rounded-lg border border-gray-200 bg-white px-5 py-6 text-center text-sm text-[#718096]"><Icon name="visibility_off" size={18} className="mr-1 inline" />Charts and amounts are hidden for your role. Counts and severities stay visible.</p>
                    ) : (
                        <>
                            <div className="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                                <MonthlyBvA data={monthlyBvA} currency={baseCurrency} />
                                <SpendByTower data={spendByCategory} currency={baseCurrency} />
                            </div>
                            <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <SplitBar title="CapEx / OpEx" currency={baseCurrency} parts={[{ key: 'capex', label: 'CapEx', value: m.capex_opex_split?.capex }, { key: 'opex', label: 'OpEx', value: m.capex_opex_split?.opex }]} />
                                <SplitBar title="Run / Grow / Transform" currency={baseCurrency} parts={['run', 'grow', 'transform'].map((k) => ({ key: k, label: humanize(k), value: m.rgt_split?.[k] }))} />
                            </div>
                        </>
                    )}

                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <Card title="Top variances" right={<Link href={monitorHref('finance.monitor.variances')} className="text-xs text-[#0A1F44] underline">All variances</Link>}>
                            {topVariances.length === 0 ? <Empty>No variances past the thresholds.</Empty> : (
                                <ul className="divide-y divide-gray-50">
                                    {topVariances.map((v) => (
                                        <li key={v.note_id} className="flex items-center gap-3 px-5 py-2.5">
                                            <div className="min-w-0 flex-1"><Link href={v.href} className="block truncate text-sm font-medium text-[#0A1F44] hover:underline">{v.label}</Link><p className="truncate text-[11px] text-[#718096]">{v.cost_centre}{v.explained ? ' · explained' : ' · awaiting explanation'}</p></div>
                                            <SeverityChip severity={v.severity} direction={v.direction} />
                                            <span className="w-24 shrink-0 text-right text-sm tabular-nums">{money ? fmtPct(v.variance_pct, { sign: true }) : MASK}<span className="block text-[11px] text-[#718096]">{money ? moneyOrMask(v.variance, baseCurrency, true, { decimals: 0 }) : ''}</span></span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                        <Card title="Renewals due" right={<Link href={route('finance.renewals.index')} className="text-xs text-[#0A1F44] underline">All renewals</Link>}>
                            {renewalsDue.length === 0 ? <Empty>No renewals due in the next 90 days.</Empty> : (
                                <ul className="divide-y divide-gray-50">
                                    {renewalsDue.map((r) => (
                                        <li key={r.id} className="flex items-center gap-3 px-5 py-2.5">
                                            <div className="min-w-0 flex-1"><Link href={r.href} className="block truncate text-sm font-medium text-[#0A1F44] hover:underline">{r.contract_code} {r.title}</Link><p className="truncate text-[11px] text-[#718096]">{r.vendor} · ends {localDate(r.ends_on)}</p></div>
                                            <div className="text-right text-xs"><Chip size="xs" tone={CONTRACT_TONES[r.status] || 'neutral'}>{humanize(r.status)}</Chip><p className="mt-0.5 text-[#718096]">Notice {daysLabel(r.days_to_notice)}</p></div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                        <Card title="Pending approvals">
                            {pendingApprovals.length === 0 ? <Empty>Nothing waiting on approval.</Empty> : (
                                <ul className="divide-y divide-gray-50">
                                    {pendingApprovals.map((a) => (
                                        <li key={a.id} className="flex items-center gap-3 px-5 py-2.5">
                                            <div className="min-w-0 flex-1"><Link href={a.url} className="block truncate text-sm font-medium text-[#0A1F44] hover:underline">{a.subject}</Link><p className="truncate text-[11px] text-[#718096]">{a.process_label}{a.step ? ` · ${a.step}` : ''}{a.due_at ? ` · due ${localDate(a.due_at)}` : ''}</p></div>
                                            {a.mine && <Chip size="xs" tone="tertiary" icon="person">Mine</Chip>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                        <Card title="Burn rate">
                            {!money ? <Empty>{HIDDEN}</Empty> : (
                                <dl className="grid grid-cols-3 gap-3 px-5 py-4 text-sm">
                                    <div><dt className="text-[11px] text-[#718096]">Monthly burn</dt><dd className="font-semibold tabular-nums">{moneyOrMask(m.burn?.monthly, baseCurrency, true, { decimals: 0 })}</dd></div>
                                    <div><dt className="text-[11px] text-[#718096]">Months of budget left</dt><dd className="font-semibold tabular-nums">{m.burn?.months_remaining === null || m.burn?.months_remaining === undefined ? '—' : Number(m.burn.months_remaining).toFixed(1)}</dd></div>
                                    <div><dt className="text-[11px] text-[#718096]">Budget exhausted</dt><dd className="font-semibold">{m.burn?.exhaustion_on ? localDate(m.burn.exhaustion_on) : '—'}</dd></div>
                                </dl>
                            )}
                        </Card>
                        {(m.investments_by_stage ?? []).length > 0 && (
                            <Card title="Investments" right={<Link href={route('finance.portfolio')} className="text-xs text-[#0A1F44] underline">Portfolio</Link>}>
                                <ul className="divide-y divide-gray-50">
                                    {m.investments_by_stage.map((r) => (
                                        <li key={r.stage} className="flex items-center justify-between gap-3 px-5 py-2 text-sm">
                                            <Link href={route('finance.investments.index', { stage: r.stage })} className="text-[#0A1F44] hover:underline">{stageLabel(r.stage)}</Link>
                                            <span className="tabular-nums text-[#4A5568]">{r.count}{money ? ` · ${moneyOrMask(r.bac, baseCurrency, true, { decimals: 0 })}` : ''}</span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="border-t border-gray-100 px-5 py-2 text-xs text-[#718096]">Benefits realised: <b>{m.benefits_realised_pct === null || m.benefits_realised_pct === undefined ? '—' : fmtPct(m.benefits_realised_pct, { decimals: 0 })}</b></p>
                            </Card>
                        )}
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import Chip from '@/Components/Material/Chip';
import HealthChip from '@/Components/Finance/Investments/HealthChip';
import InvestmentsNav from '@/Components/Finance/Investments/InvestmentsNav';
import RgtChip from '@/Components/Finance/Investments/RgtChip';
import StageChip from '@/Components/Finance/Investments/StageChip';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { SelectFilter, SearchFilter, Toggle } from '@/Components/Finance/Expenses/FilterField';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, formatPct, humanize, invMoney, invNumber, stageLabel } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ filters, investments, summary, fiscalYears, costCentres, options, baseCurrency, can }) {
    const money = can.viewAmounts !== false;
    const [f, setF] = useState({ ...filters, search: filters.search ?? '' });
    const apply = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== false));
        router.get(route('finance.investments.index'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };
    const set = (k) => (e) => apply({ ...f, [k]: e.target.value });
    const m = (v) => invMoney(v, baseCurrency, money, { decimals: 0 });
    const rows = investments.data ?? [];
    const links = Array.isArray(investments.meta?.links) ? investments.meta.links : Array.isArray(investments.links) ? investments.links : [];
    const stages = Object.entries(summary.by_stage ?? {}).filter(([, n]) => n > 0);

    return (
        <AuthenticatedLayout header="Investments & Value">
            <Head title="Investments & Value" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Investments & Value' }]} title="Investments & Value"
                subtitle="Programmes, projects and initiatives from idea to realised benefit (COBIT APO05, BAI01)"
                actions={can.create && <Link href={route('finance.investments.create')} className={BTN_PRIMARY}>New investment</Link>} />
            <InvestmentsNav active="investments" />

            <div className="mb-3 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="Investments" value={summary.count} sublabel="Visible to you" icon="rocket_launch" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <StatCard label="Budget at completion" value={m(summary.bac_total)} sublabel={baseCurrency} icon="account_balance_wallet" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
                <StatCard label="Actual cost" value={m(summary.ac_total)} sublabel="Life to date" icon="payments" tint="bg-[#E7F4EC] text-[#2D7D46]" />
                <StatCard label="At risk or off track" value={summary.at_risk} sublabel="Cost or schedule" icon="warning" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => apply({ ...f, status: 'at_risk' })} />
            </div>
            {stages.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-2" aria-label="Stage filters">
                    {stages.map(([s, n]) => (
                        <button key={s} type="button" aria-pressed={f.stage === s} onClick={() => apply({ ...f, stage: f.stage === s ? '' : s })}
                            className={`rounded-lg border px-2.5 py-1 text-xs ${f.stage === s ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#2D3748]'}`}>{stageLabel(s)}: <b>{n}</b></button>
                    ))}
                </div>
            )}

            <FiltersBar cols="lg:grid-cols-7">
                <SearchFilter id="iv-q" value={f.search} setValue={(v) => setF({ ...f, search: v })} apply={() => f.search !== (filters.search ?? '') && apply(f)} placeholder="Code or title" />
                <SelectFilter id="iv-st" label="Stage" value={f.stage} onChange={set('stage')}>{options.stages.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="iv-rgt" label="Run / grow / transform" value={f.rgt} onChange={set('rgt')}>{options.rgt.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="iv-dr" label="Driver" value={f.driver} onChange={set('driver')}>{options.drivers.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="iv-s" label="Status" value={f.status} onChange={set('status')}>{options.statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="iv-fy" label="Funding year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')}>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="iv-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
                <div className="flex items-end pb-2"><Toggle label="Regulatory only" checked={!!f.regulatory} onChange={(v) => apply({ ...f, regulatory: v })} /></div>
            </FiltersBar>

            <Card>
                <Table head={['Investment', 'Stage', 'Status', 'RGT', 'Sponsor', 'Manager', 'Rank', 'Score', 'BAC', 'AC', 'CPI', 'Benefits']}
                    empty={rows.length === 0 && <Empty>No investments match these filters.{can.create && <> <Link className="underline" href={route('finance.investments.create')}>Add an investment</Link>.</>}</Empty>}>
                    {rows.map((r) => (
                        <tr key={r.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(r.href)}>
                            <td className="min-w-[14rem] px-4 py-2 text-[#2D3748]">
                                <Link href={r.href} onClick={(e) => e.stopPropagation()} className="font-mono text-xs text-[#0A1F44] underline">{r.investment_code}</Link> {r.title}
                                <div className="mt-0.5 flex flex-wrap gap-1">
                                    {r.is_regulatory && <Chip size="xs" tone="warning">Regulatory</Chip>}
                                    {r.pending_gate && <Chip size="xs" tone="warning" icon="pending_actions">{r.pending_gate.gate === 'change' ? 'Change awaiting approval' : 'Gate awaiting approval'}</Chip>}
                                    <span className="text-[11px] text-[#718096]">{humanize(r.driver)}</span>
                                </div>
                            </td>
                            <td className="px-4 py-2"><StageChip stage={r.stage} size="xs" /></td>
                            <td className="px-4 py-2"><HealthChip value={r.stage === 'cancelled' ? null : (r.health && r.status !== 'on_hold' ? r.health : r.status)} size="xs" /></td>
                            <td className="px-4 py-2"><RgtChip rgt={r.rgt} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{r.sponsor?.name ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{r.manager?.name ?? '—'}</td>
                            <td className="px-4 py-2 text-right tabular-nums">{r.priority_rank ?? '—'}</td>
                            <td className="px-4 py-2 text-right tabular-nums">{r.priority_score ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums" title={money && r.planned_cost ? `Planned ${m(r.planned_cost)}` : undefined}>{m(r.bac ?? r.planned_cost)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{m(r.ac)}</td>
                            <td className="px-4 py-2 text-right tabular-nums">{invNumber(r.cpi, money)}</td>
                            <td className="px-4 py-2 text-right tabular-nums">{r.benefits_realised_pct === null || r.benefits_realised_pct === undefined ? '—' : formatPct(r.benefits_realised_pct, 0)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
            <Pagination links={links} />
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import Money from '@/Components/Finance/Budgets/Money';
import { CHART_COLOURS, DonutCard, STATUS_COLOUR, StatCard, compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BUDGET_TONES, FIELD, LABEL, formatMoney, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function Index({ filters, budgets, summary, fiscalYears, cycles, costCentres, statuses, baseCurrency, can }) {
    const [f, setF] = useState({ ...filters, q: filters.q ?? '' });
    const apply = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '' && v !== false));
        router.get(route('finance.budgets.index'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };
    const set = (k) => (e) => apply({ ...f, [k]: e.target.value });
    const money = can.viewAmounts !== false;
    const charts = useMemo(() => {
        const byStatus = statuses.map((st) => ({ key: st, name: humanize(st), color: STATUS_COLOUR[st] || '#A0AEC0', value: budgets.filter((b) => b.status === st).length, onClick: () => apply({ ...f, status: st }) }));
        const capex = budgets.reduce((t, b) => t + num(b.total_capex), 0);
        const opex = budgets.reduce((t, b) => t + num(b.total_opex), 0);
        const ccTotals = Object.values(budgets.reduce((acc, b) => {
            if (!b.cost_centre) return acc;
            const k = b.cost_centre.id;
            acc[k] = acc[k] || { key: k, name: `${b.cost_centre.code} ${b.cost_centre.name}`, value: 0, onClick: () => apply({ ...f, cost_centre_id: k }) };
            acc[k].value += num(b.total_base_amount);
            return acc;
        }, {})).sort((a, b) => b.value - a.value);
        const top = ccTotals.slice(0, 5);
        const rest = ccTotals.slice(5).reduce((t, c) => t + c.value, 0);
        const byCc = [...top, ...(rest > 0 ? [{ key: 'other', name: `Other (${ccTotals.length - 5})`, value: rest }] : [])].map((c, i) => ({ ...c, color: CHART_COLOURS[i % CHART_COLOURS.length] }));
        return { byStatus, capex, opex, byCc, ccTotal: ccTotals.reduce((t, c) => t + c.value, 0) };
    }, [budgets, statuses, f]);
    const fmt = (v) => formatMoney(v, baseCurrency, { decimals: 0 });
    const cyclesFor = cycles.filter((c) => !f.fiscal_year_id || String(c.fiscal_year_id) === String(f.fiscal_year_id));

    return (
        <AuthenticatedLayout header="Budgets">
            <Head title="Budgets" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets' }]} title="Budgets"
                subtitle="Versioned IT budgets by cost centre, planned in cycles and approved against delegation-of-authority bands"
                actions={can.create && <Link href={route('finance.budgets.create')} className={BTN_PRIMARY}>New budget</Link>} />
            <BudgetsNav active="budgets" />

            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard label="Budgets" value={summary.count} sublabel={`${summary.approved ?? 0} approved, ${summary.baseline ?? 0} baselined`} icon="account_balance_wallet" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <StatCard label="Drafts" value={summary.draft} sublabel="Still being planned" icon="edit_note" tint="bg-[#F0F1F4] text-[#43474E]" onClick={() => apply({ ...f, status: 'draft' })} />
                <StatCard label="In approval" value={summary.in_approval} sublabel="Submitted or under review" icon="hourglass_top" tint="bg-[#FFF6E0] text-[#8A6400]" />
                <StatCard label="Baseline total" value={fmt(summary.total_baseline_base)} sublabel="Locked baselines" icon="verified" tint="bg-[#E7F4EC] text-[#2D7D46]" />
                <StatCard label="In flight total" value={fmt(summary.total_in_flight_base)} sublabel="Draft to approved" icon="trending_up" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
            </div>

            <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <DonutCard title="Budgets by status" subtitle={`${budgets.length} shown`} data={charts.byStatus} centre={budgets.length} centreLabel="Total"
                    format={(v) => v} empty="No budgets match these filters" />
                <DonutCard title="CapEx vs OpEx" subtitle={baseCurrency} centre={money ? compactMoney(charts.capex + charts.opex, baseCurrency) : '—'} centreLabel="Total"
                    data={money ? [{ key: 'capex', name: 'CapEx', value: charts.capex, color: '#0A1F44' }, { key: 'opex', name: 'OpEx', value: charts.opex, color: '#C9A86A' }] : []}
                    format={fmt} empty={money ? 'No amounts planned yet' : 'Amounts are hidden for your role'} />
                <DonutCard title="By cost centre" subtitle={`Top ${Math.min(5, charts.byCc.length)}`} centre={money ? compactMoney(charts.ccTotal, baseCurrency) : '—'} centreLabel="Total"
                    data={money ? charts.byCc : []} format={fmt} empty={money ? 'No amounts planned yet' : 'Amounts are hidden for your role'} />
            </div>

            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-6">
                <div><label className={LABEL} htmlFor="f-fy">Fiscal year</label><select id="f-fy" value={f.fiscal_year_id ?? ''} onChange={set('fiscal_year_id')} className={FIELD}><option value="">All</option>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="f-cy">Cycle</label><select id="f-cy" value={f.cycle_id ?? ''} onChange={set('cycle_id')} className={FIELD}><option value="">All</option>{cyclesFor.map((c) => <option key={c.id} value={c.id}>{c.cycle_code}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="f-cc">Cost centre</label><select id="f-cc" value={f.cost_centre_id ?? ''} onChange={set('cost_centre_id')} className={FIELD}><option value="">All</option>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="f-st">Status</label><select id="f-st" value={f.status ?? ''} onChange={set('status')} className={FIELD}><option value="">All</option>{statuses.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="f-q">Search</label><input id="f-q" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && apply(f)} onBlur={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="Code or title" className={FIELD} /></div>
                <label className="flex items-end gap-2 pb-2 text-sm text-[#2D3748]"><input type="checkbox" checked={!!f.all_versions} onChange={(e) => apply({ ...f, all_versions: e.target.checked })} /> All versions</label>
            </div>

            <Card>
                <Table head={['Budget', 'Title', 'Cost centre', 'Cycle', 'Status', 'CapEx', 'OpEx', 'Total (base)', 'Band', 'Updated']}
                    empty={budgets.length === 0 && (
                        <Empty>No budgets match. {can.manageCycles ? <>Open a planning cycle first on the <Link className="underline" href={route('finance.cycles.index')}>Cycles</Link> tab.</> : 'Budgets are created inside an open planning cycle.'}</Empty>
                    )}>
                    {budgets.map((b) => (
                        <tr key={b.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(route('finance.budgets.show', b.id))}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link href={route('finance.budgets.show', b.id)} onClick={(e) => e.stopPropagation()} className="text-[#0A1F44] underline">{b.budget_code} {b.version_label}</Link></td>
                            <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">{b.title}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{b.cost_centre ? `${b.cost_centre.code} ${b.cost_centre.name}` : '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{b.cycle?.cycle_code ?? '—'}</td>
                            <td className="px-4 py-2"><Chip size="xs" tone={BUDGET_TONES[b.status] || 'neutral'}>{humanize(b.status)}</Chip></td>
                            <td className="px-4 py-2 text-right"><Money value={b.total_capex} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} /></td>
                            <td className="px-4 py-2 text-right"><Money value={b.total_opex} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} /></td>
                            <td className="px-4 py-2 text-right font-medium"><Money value={b.total_base_amount} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} /></td>
                            <td className="px-4 py-2">{b.amount_band_label && <Chip size="xs" tone="tertiary">{b.amount_band_label}</Chip>}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{formatDate(b.updated_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AuthenticatedLayout>
    );
}

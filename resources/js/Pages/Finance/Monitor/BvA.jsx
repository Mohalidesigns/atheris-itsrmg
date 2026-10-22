import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import BvaPivot from '@/Components/Finance/Monitor/BvaPivot';
import DrillBreadcrumbs from '@/Components/Finance/Monitor/DrillBreadcrumbs';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import MonitorNav from '@/Components/Finance/Monitor/MonitorNav';
import TransactionsDrawer from '@/Components/Finance/Monitor/TransactionsDrawer';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { BTN_SECONDARY, MASK, fmtPct, moneyOrMask } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const VIEWS = [['period', 'By period'], ['ytd', 'Year to date'], ['fy', 'Full year']];
const DIMS = [
    ['cost_centre_id', 'Cost centre', 'costCentres'], ['tower_id', 'TBM tower', 'towers'], ['cost_pool_id', 'Cost pool', 'costPools'],
    ['category_id', 'Category', 'categories'], ['gl_account_id', 'GL account', 'glAccounts'], ['legal_entity_id', 'Legal entity', 'legalEntities'], ['vendor_id', 'Vendor', 'vendors'],
];
const label = (d) => (d.code ? `${d.code} ${d.name}` : d.name);

export default function BvA(props) {
    const { filters, groupings, breadcrumbs, fiscalYears, periods, dimensions, thresholds, matrix, can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can.viewAmounts !== false;
    const [f, setF] = useState({ ...filters });
    const [line, setLine] = useState(null);
    const clean = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== false));
    const go = (next) => { setF(next); router.get(route('finance.monitor.bva'), clean(next), { preserveScroll: true, preserveState: true, replace: true }); };
    const set = (k) => (e) => go({ ...f, [k]: e.target.value, ...(k === 'fiscal_year_id' ? { from_period_id: '', to_period_id: '' } : {}) });
    const drill = (r) => router.get(route('finance.monitor.bva'), clean({ ...filters, [r.drill.param]: r.drill.value, group_by: r.drill.next_group_by }), { preserveScroll: true });
    const recon = matrix.reconciliation;
    const reconOk = recon && recon.difference === '0.00';
    const periodsOfFy = periods;
    const exportHref = (format) => route('finance.monitor.bva.export', clean({ ...filters, format }));
    const hasFilters = DIMS.some(([k]) => f[k]) || f.rgt || f.capex;

    return (
        <AuthenticatedLayout header="Budget Monitor">
            <Head title="Budget Monitor" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budget Monitor' }]} title="Budget Monitor"
                subtitle={`Budget versus actual in ${baseCurrency}. Variance is actual minus budget, so a positive figure is an overspend.`}
                actions={can.export && (
                    <details className="relative">
                        <summary className={`${BTN_SECONDARY} flex cursor-pointer list-none items-center gap-1`}><Icon name="download" size={18} />Export</summary>
                        <div className="absolute right-0 z-30 mt-1 w-40 rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg">
                            {[['csv', 'CSV'], ['xlsx', 'Excel (XLSX)'], ['pdf', 'PDF']].map(([k, t]) => <a key={k} href={exportHref(k)} className="block px-3 py-1.5 text-[#2D3748] hover:bg-gray-50">{t}</a>)}
                        </div>
                    </details>
                )} />
            <MonitorNav active="bva" params={{ fiscal_year_id: filters.fiscal_year_id }} />

            <FiltersBar cols="lg:grid-cols-5">
                <SelectFilter id="b-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="b-from" label="From period" value={f.from_period_id} onChange={set('from_period_id')} all="First">{periodsOfFy.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>
                <SelectFilter id="b-to" label="To period" value={f.to_period_id} onChange={set('to_period_id')} all="As of last ended">{periodsOfFy.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>
                <div>
                    <label className="block text-xs font-medium text-[#4A5568]" htmlFor="b-view">View</label>
                    <select id="b-view" value={f.view ?? 'ytd'} onChange={set('view')} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">{VIEWS.map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select>
                </div>
                <div>
                    <label className="block text-xs font-medium text-[#4A5568]" htmlFor="b-group">Group by</label>
                    <select id="b-group" value={f.group_by ?? ''} onChange={set('group_by')} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">{groupings.map((g) => <option key={g.key} value={g.key}>{g.label}</option>)}</select>
                </div>
            </FiltersBar>
            <details className="mb-4 rounded-lg border border-gray-200 bg-white" open={hasFilters}>
                <summary className="cursor-pointer px-4 py-2 text-sm text-[#0A1F44]">More filters{hasFilters ? ' (active)' : ''}</summary>
                <div className="grid grid-cols-2 gap-3 border-t border-gray-100 p-4 lg:grid-cols-5">
                    {DIMS.map(([k, text, dim]) => (
                        <SelectFilter key={k} id={`b-${k}`} label={text} value={f[k]} onChange={set(k)}>{(dimensions[dim] ?? []).map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}</SelectFilter>
                    ))}
                    <SelectFilter id="b-rgt" label="Run / Grow / Transform" value={f.rgt} onChange={set('rgt')}>{['run', 'grow', 'transform'].map((v) => <option key={v} value={v}>{v.charAt(0).toUpperCase() + v.slice(1)}</option>)}</SelectFilter>
                    <SelectFilter id="b-capex" label="CapEx / OpEx" value={f.capex} onChange={set('capex')}><option value="1">CapEx</option><option value="0">OpEx</option></SelectFilter>
                    {hasFilters && <div className="flex items-end"><button type="button" onClick={() => go({ fiscal_year_id: f.fiscal_year_id, from_period_id: f.from_period_id, to_period_id: f.to_period_id, view: f.view, group_by: f.group_by })} className={BTN_SECONDARY}>Clear filters</button></div>}
                </div>
            </details>

            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <DrillBreadcrumbs crumbs={breadcrumbs} />
                <div className="ml-auto flex flex-wrap items-center gap-2 text-xs text-[#4A5568]">
                    {thresholds && <span title="Overspend or underspend is flagged at these thresholds, subject to the absolute floor">Amber {fmtPct(thresholds.amber_pct, { decimals: 0 })} · Red {fmtPct(thresholds.red_pct, { decimals: 0 })} · Floor {money ? moneyOrMask(thresholds.abs_floor, baseCurrency, true, { decimals: 0 }) : MASK}</span>}
                    {recon && recon.difference !== null && recon.difference !== undefined && (
                        <Chip tone={reconOk ? 'success' : 'error'} icon={reconOk ? 'verified' : 'error'} title={money ? `Ledger ${recon.ledger_actual}, matrix ${recon.matrix_actual}` : undefined}>
                            {reconOk ? 'Reconciled to the ledger' : `Out of balance${money ? ` by ${moneyOrMask(recon.difference, baseCurrency, true)}` : ''}`}
                        </Chip>
                    )}
                </div>
            </div>

            {!filters.fiscal_year_id && matrix.rows.length === 0 && !matrix.unallocated ? (
                <p className="rounded-lg border border-gray-200 bg-white px-5 py-10 text-center text-sm text-[#718096]">No fiscal year with a baseline budget yet.</p>
            ) : (
                <BvaPivot matrix={matrix} view={f.view ?? 'ytd'} baseCurrency={baseCurrency} money={money} onDrill={drill} onLine={setLine} />
            )}
            {!money && <p className="mt-2 text-xs text-[#718096]">Amounts are hidden for your role. Severity and structure remain visible.</p>}

            <TransactionsDrawer line={line} params={clean({ from_period_id: filters.from_period_id, to_period_id: filters.to_period_id })} baseCurrency={baseCurrency} money={money} onClose={() => setLine(null)} />
        </AuthenticatedLayout>
    );
}

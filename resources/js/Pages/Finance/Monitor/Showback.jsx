import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Icon from '@/Components/Material/Icon';
import ChargebackCard from '@/Components/Finance/FinOps/ChargebackCard';
import ShowbackDetailDrawer from '@/Components/Finance/FinOps/ShowbackDetailDrawer';
import ShowbackTable from '@/Components/Finance/FinOps/ShowbackTable';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import MonitorNav from '@/Components/Finance/Monitor/MonitorNav';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_SECONDARY, cleanQuery, finMoney, lensLabel } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Showback(props) {
    const { filters, enabled, statement, detail, cloudMemo = [], chargeback, fiscalYears = [], periods = [], options, can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [open, setOpen] = useState(null); // { key, label }
    const go = (next) => router.get(route('finance.monitor.showback'), cleanQuery({ ...next, target: undefined }), { preserveScroll: true, preserveState: true, replace: true });
    const set = (k) => (e) => go({ ...filters, [k]: e.target.value, ...(k === 'fiscal_year_id' ? { from_period_id: '', to_period_id: '' } : {}) });
    const select = (row) => {
        setOpen({ key: row.target_key, label: row.label });
        router.reload({ only: ['detail'], data: cleanQuery({ ...filters, target: row.target_key }) });
    };
    const exportHref = (format) => route('finance.monitor.showback.export', cleanQuery({ ...filters, target: undefined, format }));

    return (
        <AuthenticatedLayout header="Showback">
            <Head title="Showback" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budget Monitor', href: route('finance.monitor.bva') }, { label: 'Showback' }]} title="Showback"
                subtitle={`IT cost attributed to business units, services, applications and entities, in ${baseCurrency}`}
                actions={can.export && <div className="flex gap-2">{['csv', 'xlsx', 'pdf'].map((f) => <a key={f} href={exportHref(f)} className={`${BTN_SECONDARY} uppercase`}>{f}</a>)}</div>} />
            <MonitorNav active="showback" params={{ fiscal_year_id: filters.fiscal_year_id }} />

            {!enabled && (
                <div role="status" className="mb-4 flex items-start gap-2 rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-4 py-3 text-sm text-[#5F4300]">
                    <Icon name="info" size={20} className="mt-0.5" /><span>Showback is switched off for this bank. Statements still compute, but chargeback export is refused. Enable it in Settings, General.</span>
                </div>
            )}
            {!money && <p className="mb-4 rounded-lg border border-gray-200 bg-white px-5 py-4 text-center text-sm text-[#718096]"><Icon name="visibility_off" size={18} className="mr-1 inline" />Amounts are hidden for your role.</p>}

            <FiltersBar cols="lg:grid-cols-5">
                <SelectFilter id="sb-fy" label="Fiscal year" value={filters.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="sb-fp" label="From period" value={filters.from_period_id} onChange={set('from_period_id')} all="First">{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>
                <SelectFilter id="sb-tp" label="To period" value={filters.to_period_id} onChange={set('to_period_id')} all="Latest">{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>
                <SelectFilter id="sb-tt" label="Lens" value={filters.target_type} onChange={set('target_type')} all="Business unit">{options.targetTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="sb-ba" label="Basis" value={filters.basis} onChange={set('basis')} all="Actual">{options.bases.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
            </FiltersBar>

            <div className="space-y-4">
                <ShowbackTable statement={statement} baseCurrency={baseCurrency} money={money} onSelect={select} selected={open?.key} />

                {cloudMemo.length > 0 && (
                    <Card title={`Cloud spend by ${lensLabel(statement.target_type).toLowerCase()} (FinOps memo)`}>
                        <Table head={[lensLabel(statement.target_type), 'FinOps cost']}>
                            {cloudMemo.map((m) => <tr key={m.target_key}><td className="px-4 py-2 text-[#2D3748]">{m.label}</td><td className="px-4 py-2 text-right tabular-nums">{finMoney(m.cost, baseCurrency, money, { decimals: 0 })}</td></tr>)}
                        </Table>
                        <p className="border-t border-gray-100 px-5 py-2 text-[11px] text-[#718096]">Memo only: cloud invoices are already in the statement above through the ledger.</p>
                    </Card>
                )}

                {chargeback && can.run && <ChargebackCard chargeback={chargeback} targetTypes={options.targetTypes} enabled={enabled} can={can} baseCurrency={baseCurrency} money={money} />}
                {!chargeback && <Card><Empty>Chargeback is not available to your role.</Empty></Card>}
            </div>

            <ShowbackDetailDrawer open={open?.key ?? null} label={open?.label} detail={detail} onClose={() => setOpen(null)} baseCurrency={baseCurrency} money={money} />
        </AuthenticatedLayout>
    );
}

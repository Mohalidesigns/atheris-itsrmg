import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Icon from '@/Components/Material/Icon';
import AttributeSpendModal from '@/Components/Finance/FinOps/AttributeSpendModal';
import PoolStackChart from '@/Components/Finance/FinOps/PoolStackChart';
import TcoDetailDrawer from '@/Components/Finance/FinOps/TcoDetailDrawer';
import TcoTable from '@/Components/Finance/FinOps/TcoTable';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import KpiTile from '@/Components/Finance/Monitor/KpiTile';
import MonitorNav from '@/Components/Finance/Monitor/MonitorNav';
import { SearchFilter, SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Table } from '@/Components/Finance/Settings/ui';
import { BTN_SECONDARY, cleanQuery, finMoney, fmtPct } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Tco(props) {
    const { filters, subjectAvailable = {}, rows = [], totals, rationalisation = [], fiscalYears = [], unattributed, can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [f, setF] = useState({ ...filters, search: filters.search ?? '' });
    const [detail, setDetail] = useState(null);
    const [attributing, setAttributing] = useState(false);
    const go = (next) => { setF(next); router.get(route('finance.monitor.tco'), cleanQuery(next), { preserveScroll: true, preserveState: true, replace: true }); };
    const set = (k) => (e) => go({ ...f, [k]: e.target.value });
    const subject = filters.subject || 'ea_application';
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    const subjects = props.subjects ?? rows.map((r) => ({ id: r.id, label: r.label }));

    return (
        <AuthenticatedLayout header="Application TCO">
            <Head title="Application TCO" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budget Monitor', href: route('finance.monitor.bva') }, { label: 'Application TCO' }]} title="Application TCO"
                subtitle={`Total cost of ownership per ${subject === 'ea_application' ? 'application' : 'business service'}: direct, allocated and cloud, in ${baseCurrency}`}
                actions={can.classify && <button type="button" onClick={() => setAttributing(true)} className={BTN_SECONDARY}>Attribute spend</button>} />
            <MonitorNav active="tco" params={{ fiscal_year_id: filters.fiscal_year_id }} />

            {!money && <p className="mb-4 rounded-lg border border-gray-200 bg-white px-5 py-4 text-center text-sm text-[#718096]"><Icon name="visibility_off" size={18} className="mr-1 inline" />Amounts are hidden for your role. Coverage and shares stay visible.</p>}

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiTile label="Attributed TCO" value={fmt(totals?.tco_total)} icon="apps" />
                <KpiTile label="IT total" value={fmt(totals?.it_total)} icon="account_balance" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
                <KpiTile label="Coverage" value={fmtPct(totals?.coverage_pct)} sub="Attributed share of IT cost" icon="donut_large" tint="bg-[#E7F4EC] text-[#2D7D46]" />
                <KpiTile label="Unattributed" value={fmt(totals?.unattributed)} icon="help" tint="bg-[#FFF6E0] text-[#8A6400]" alert={Number(totals?.coverage_pct) < 50} />
            </div>
            {Number(totals?.finops_excess) > 0 && <p className="mb-4 rounded-lg border border-[#F0DDA8] bg-[#FFF6E0] px-5 py-3 text-sm text-[#8A6400]"><Icon name="info" size={18} className="mr-1 inline" />FinOps cloud cost exceeds the cloud cost in the ledger by {fmt(totals.finops_excess)}. It is shown as a reconciling line so that attributed TCO, unattributed and this excess tie to the IT total.</p>}

            <FiltersBar cols="lg:grid-cols-4">
                <SelectFilter id="tc-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <div>
                    <span className="block text-xs font-medium text-[#4A5568]" id="tc-sj">View</span>
                    <div role="group" aria-labelledby="tc-sj" className="mt-1 flex gap-1">
                        {[['ea_application', 'Applications'], ['business_service', 'Business services']].map(([k, label]) => (
                            <button key={k} type="button" disabled={subjectAvailable[k] === false} aria-pressed={subject === k} onClick={() => go({ ...f, subject: k })} title={subjectAvailable[k] === false ? 'None recorded for this bank' : undefined}
                                className={`rounded-lg border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-40 ${subject === k ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#4A5568]'}`}>{label}</button>
                        ))}
                    </div>
                </div>
                <SelectFilter id="tc-ba" label="Basis" value={f.basis} onChange={set('basis')} all="Actual"><option value="budget">Budget</option></SelectFilter>
                <SearchFilter id="tc-se" value={f.search} setValue={(v) => setF({ ...f, search: v })} apply={() => f.search !== (filters.search ?? '') && go(f)} placeholder="Name or code" />
            </FiltersBar>
            {filters.basis !== 'budget' && <p className="mb-3 text-xs text-[#718096]">Actual basis: the ledger cloud pool is replaced by the FinOps cloud figure so cloud is never counted twice. Labour is a breakdown inside the total, and contracts are a memo.</p>}

            <div className="space-y-4">
                {money && rows.length > 0 && <PoolStackChart rows={rows} currency={baseCurrency} />}
                <TcoTable rows={rows} baseCurrency={baseCurrency} money={money} subject={subject} onSelect={setDetail} />
                {rationalisation.length > 0 && (
                    <Card title="Rationalisation candidates" right={<span className="text-xs text-[#718096]">Applications supporting the same capability, re-priced with measured cost</span>}>
                        <Table head={['Capability', 'Applications', 'Combined']}>
                            {rationalisation.map((g) => (
                                <tr key={g.capability}><td className="px-4 py-2 text-[#2D3748]">{g.capability}</td>
                                    <td className="px-4 py-2 text-xs text-[#4A5568]">{g.applications.map((a) => `${a.label} (${fmt(a.total)})`).join(', ')}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(g.combined_total)}</td></tr>
                            ))}
                        </Table>
                    </Card>
                )}
            </div>

            <TcoDetailDrawer item={detail} params={{ subject, fiscal_year_id: filters.fiscal_year_id, basis: filters.basis || 'actual' }} onClose={() => setDetail(null)} baseCurrency={baseCurrency} money={money} />
            <AttributeSpendModal show={attributing} onClose={() => setAttributing(false)} subject={subject} subjects={subjects} filters={cleanQuery(filters)} unattributed={unattributed} baseCurrency={baseCurrency} money={money} />
        </AuthenticatedLayout>
    );
}

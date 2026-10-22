import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import Chip from '@/Components/Material/Chip';
import CapStatusChip from '@/Components/Finance/Capitalisations/CapStatusChip';
import CapitalisationFormModal from '@/Components/Finance/Capitalisations/CapitalisationFormModal';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import KpiTile from '@/Components/Finance/Monitor/KpiTile';
import { SearchFilter, SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { cleanQuery, finMoney, fmtMonths, humanize, localDate, sourceLabel } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index(props) {
    const { filters, capitalisations, summary, candidates = [], assets = [], users = [], options, defaults, can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const preset = filters.source && filters.id ? candidates.find((c) => c.source_type === filters.source && String(c.id) === String(filters.id)) : null;
    const [source, setSource] = useState(preset ?? null);
    const [f, setF] = useState({ ...filters, search: filters.search ?? '' });
    const go = (next) => { setF(next); router.get(route('finance.capitalisations.index'), cleanQuery({ ...next, source: undefined, id: undefined }), { preserveScroll: true, preserveState: true, replace: true }); };
    const set = (k) => (e) => go({ ...f, [k]: e.target.value });
    const rows = capitalisations?.data ?? [];
    const links = Array.isArray(capitalisations?.meta?.links) ? capitalisations.meta.links : Array.isArray(capitalisations?.links) ? capitalisations.links : null;
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    const by = summary?.by_status ?? {};

    return (
        <AuthenticatedLayout header="Capitalisation">
            <Head title="Capitalisation" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Capitalisation' }]} title="Capitalisation"
                subtitle="Move qualifying capex to the asset register with approval and an amortisation schedule (IAS 38, IAS 16)" />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiTile label="Capitalised" value={fmt(summary?.capitalised_total)} sub={`${by.capitalised ?? 0} records`} icon="inventory_2" />
                <KpiTile label="Net book value" value={fmt(summary?.nbv_total)} icon="account_balance" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
                <KpiTile label="Amortisation this year" value={fmt(summary?.amortisation_this_fy)} icon="trending_down" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <KpiTile label="Awaiting approval" value={by.submitted ?? 0} sub={`${by.draft ?? 0} drafts`} icon="pending_actions" tint="bg-[#FFF6E0] text-[#8A6400]" alert={(by.submitted ?? 0) > 0} />
            </div>

            {can.create && (
                <Card title="Ready to capitalise" className="mb-4" right={<span className="text-xs text-[#718096]">Delivered investments and received capex with eligible cost remaining</span>}>
                    <Table head={['Source', 'Delivered', 'Eligible', 'Already capitalised', 'Remaining', '']} empty={candidates.length === 0 && <Empty>Nothing is waiting. Investments marked capitalisable and capex expenses appear here once delivered or received.</Empty>}>
                        {candidates.map((c) => (
                            <tr key={`${c.source_type}-${c.id}`}>
                                <td className="min-w-[12rem] px-4 py-2"><span className="text-[11px] text-[#718096]">{sourceLabel(c.source_type)}</span><span className="block text-[#2D3748]">{c.label}</span></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{c.delivered_on ? localDate(c.delivered_on) : '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(c.eligible_amount)}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums text-[#718096]">{fmt(c.already_capitalised)}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{fmt(c.remaining)}</td>
                                <td className="px-4 py-2 text-right"><button type="button" onClick={() => setSource(c)} className="text-xs text-[#0A1F44] underline">Capitalise</button></td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            <FiltersBar cols="lg:grid-cols-4">
                <SelectFilter id="cap-st" label="Status" value={f.status} onChange={set('status')}>{options.statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="cap-so" label="Source" value={f.source_type} onChange={set('source_type')}><option value="investment">Investment</option><option value="expense">Capex expense</option></SelectFilter>
                <SearchFilter id="cap-se" value={f.search} setValue={(v) => setF({ ...f, search: v })} apply={() => f.search !== (filters.search ?? '') && go(f)} placeholder="Code, title or asset" />
            </FiltersBar>

            <Card title="Capitalisations">
                <Table head={['Code', 'Title', 'Source', 'Standard', 'Status', 'Amount', 'Life', 'Available for use', 'NBV today', 'Asset']} empty={rows.length === 0 && <Empty>No capitalisations match these filters.</Empty>}>
                    {rows.map((c) => (
                        <tr key={c.id}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] hover:underline" href={c.href}>{c.capitalisation_code}</Link></td>
                            <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">{c.title}</td>
                            <td className="px-4 py-2 text-xs">{c.source?.href ? <Link className="text-[#0A1F44] underline" href={c.source.href}>{c.source.label}</Link> : c.source?.label}</td>
                            <td className="px-4 py-2"><Chip size="xs" tone="primary">{c.standard === 'ias_16' ? 'IAS 16' : 'IAS 38'}</Chip></td>
                            <td className="px-4 py-2"><CapStatusChip status={c.status} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(c.amount)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{fmtMonths(c.useful_life_months)}<span className="block text-[11px] text-[#718096]">{humanize(c.method)}</span></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(c.available_for_use_on)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{c.status === 'capitalised' ? fmt(c.nbv_today) : '—'}</td>
                            <td className="px-4 py-2 text-xs">{c.asset ? <Link className="text-[#0A1F44] underline" href={c.asset.href}>{c.asset.code}</Link> : '—'}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
            <Pagination links={links} />

            <CapitalisationFormModal show={source !== null} onClose={() => setSource(null)} source={source} options={options} defaults={defaults} assets={assets} users={users} baseCurrency={baseCurrency} money={money} />
        </AuthenticatedLayout>
    );
}

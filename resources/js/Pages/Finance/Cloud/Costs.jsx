import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import AssignUntaggedModal from '@/Components/Finance/FinOps/AssignUntaggedModal';
import CloudCostChart from '@/Components/Finance/FinOps/CloudCostChart';
import CloudNav from '@/Components/Finance/FinOps/CloudNav';
import LedgerReconCard from '@/Components/Finance/FinOps/LedgerReconCard';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import KpiTile from '@/Components/Finance/Monitor/KpiTile';
import { SearchFilter, SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { cleanQuery, finMoney, fmtPct, localDate } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Costs(props) {
    const { tab = 'costs', filters, summary, series = [], groups = [], untagged = [], ledger, accounts = [], tagKeys = {}, options, applications = [], businessServices = [], costCentres = [], can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [f, setF] = useState({ ...filters, search: filters.search ?? '' });
    const [assigning, setAssigning] = useState(null);
    const go = (next) => {
        setF(next);
        router.get(route('finance.cloud.costs'), cleanQuery({ ...next, tab }), { preserveScroll: true, preserveState: true, replace: true });
    };
    const set = (k) => (e) => go({ ...f, [k]: e.target.value });
    const fmt = (v, d = 0) => finMoney(v, baseCurrency, money, { decimals: d });
    const targets = { cost_centre: costCentres.map((c) => ({ id: c.id, label: `${c.code} ${c.name}` })), ea_application: applications, business_service: businessServices };
    const maxShare = Math.max(1, ...groups.map((g) => Number(g.share_pct) || 0));
    const mtdDelta = summary.prior_mtd && Number(summary.prior_mtd) > 0 ? ((Number(summary.mtd) - Number(summary.prior_mtd)) / Number(summary.prior_mtd)) * 100 : null;
    const groupLabel = options.groupBy.find((o) => o.value === f.group_by)?.label ?? 'Group';

    return (
        <AuthenticatedLayout header="Cloud & FinOps">
            <Head title="Cloud & FinOps" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Cloud & FinOps' }]} title="Cloud & FinOps"
                subtitle={`Cloud spend by account, service and owner, in ${baseCurrency}. A FinOps memo ledger: invoices stay the book of record`} />
            <CloudNav active={tab === 'untagged' ? 'untagged' : 'costs'} />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiTile label="Total in range" value={fmt(summary.total)} sub={`${summary.days} days`} icon="cloud" />
                <KpiTile label="Month to date" value={fmt(summary.mtd)} sub={money && mtdDelta !== null ? `${fmtPct(mtdDelta, { sign: true })} vs prior MTD ${fmt(summary.prior_mtd)}` : null} icon="calendar_month" tint="bg-[#F7F1E6] text-[#8A6A2E]" alert={money && mtdDelta !== null && mtdDelta > 30} />
                <KpiTile label="Untagged" value={fmt(summary.untagged)} sub={fmtPct(summary.untagged_pct)} icon="label_off" tint="bg-[#FFF6E0] text-[#8A6400]" alert={Number(summary.untagged_pct) > 20} />
                <KpiTile label="Accounts" value={accounts.length} icon="account_tree" tint="bg-[#E7F4EC] text-[#2D7D46]" />
            </div>

            <FiltersBar cols="lg:grid-cols-6">
                <SelectFilter id="cc-ac" label="Account" value={f.account_id} onChange={set('account_id')}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</SelectFilter>
                <div><label className="block text-xs font-medium text-[#4A5568]" htmlFor="cc-fr">From</label><input id="cc-fr" type="date" value={f.from ?? ''} onChange={set('from')} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" /></div>
                <div><label className="block text-xs font-medium text-[#4A5568]" htmlFor="cc-to">To</label><input id="cc-to" type="date" value={f.to ?? ''} onChange={set('to')} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" /></div>
                <SelectFilter id="cc-gb" label="Group by" value={f.group_by} onChange={set('group_by')} all="Service">{options.groupBy.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="cc-tg" label="Tagging" value={f.tagged} onChange={set('tagged')} all="All"><option value="tagged">Tagged</option><option value="untagged">Untagged</option></SelectFilter>
                <SearchFilter id="cc-se" value={f.search} setValue={(v) => setF({ ...f, search: v })} apply={() => f.search !== (filters.search ?? '') && go(f)} placeholder="Service or resource" />
            </FiltersBar>

            {!money && <p className="mb-4 rounded-lg border border-gray-200 bg-white px-5 py-4 text-center text-sm text-[#718096]">Amounts and the cost chart are hidden for your role. Shares and counts stay visible.</p>}

            {tab === 'costs' && (
                <div className="space-y-4">
                    {money && <CloudCostChart series={series} currency={baseCurrency} />}
                    <Card title={`Cost by ${groupLabel.toLowerCase()}`}>
                        <Table head={[groupLabel, 'Cost', 'Share', 'Untagged', 'Lines']} empty={groups.length === 0 && <Empty>No cloud cost lines match these filters. Import a provider export on the Accounts tab.</Empty>}>
                            {groups.map((g) => (
                                <tr key={g.key}>
                                    <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{g.label}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(g.cost)}</td>
                                    <td className="min-w-[9rem] px-4 py-2">
                                        <div className="flex items-center gap-2"><div className="h-2 flex-1 overflow-hidden rounded bg-gray-100"><div className="h-full bg-[#0A1F44]" style={{ width: `${Math.min(100, (Number(g.share_pct) / maxShare) * 100)}%` }} /></div><span className="w-12 text-right text-xs tabular-nums text-[#4A5568]">{fmtPct(g.share_pct)}</span></div>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums text-[#8A6400]">{fmt(g.untagged)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-[#718096]">{g.lines}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <LedgerReconCard ledger={ledger} baseCurrency={baseCurrency} money={money} />
                </div>
            )}

            {tab === 'untagged' && (
                <Card title="Untagged queue" right={<span className="text-xs text-[#718096]">Largest first. Only open-period lines can be re-attributed</span>}>
                    <Table head={['Account', 'Service', 'Resource', 'Region', 'Tags', 'Cost', 'Lines', 'Dates', '']} empty={untagged.length === 0 && <Empty>No untagged spend in this range.</Empty>}>
                        {untagged.map((u, i) => (
                            <tr key={`${u.cloud_account_id}-${u.service}-${u.resource_id}-${i}`}>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{u.account}</td>
                                <td className="px-4 py-2 text-[#2D3748]">{u.service}</td>
                                <td className="max-w-[16rem] truncate px-4 py-2 font-mono text-xs" title={u.resource_id}>{u.resource_id || '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{u.region || '—'}</td>
                                <td className="px-4 py-2 text-xs">{Object.keys(u.tags ?? {}).length === 0 ? <span className="text-[#718096]">none</span> : Object.entries(u.tags).slice(0, 3).map(([k, v]) => <Chip key={k} size="xs" tone="muted" className="mr-1">{k}={v}</Chip>)}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(u.cost)}</td>
                                <td className="px-4 py-2 text-right tabular-nums text-[#718096]">{u.lines}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{localDate(u.first_date)} to {localDate(u.last_date)}</td>
                                <td className="px-4 py-2 text-right text-xs">{can.assign && (u.in_open_period ? <button type="button" onClick={() => setAssigning(u)} className="text-[#0A1F44] underline">Assign</button> : <Chip size="xs" tone="muted">Closed</Chip>)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
            <AssignUntaggedModal item={assigning} onClose={() => setAssigning(null)} dimensions={options.dimensions} targets={targets} tagKeys={tagKeys} baseCurrency={baseCurrency} money={money} />
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import ContractsNav from '@/Components/Finance/Contracts/ContractsNav';
import ContractStatusChip from '@/Components/Finance/Contracts/ContractStatusChip';
import DaysChip from '@/Components/Finance/Contracts/DaysChip';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, FIELD, LABEL, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ filters, contracts, summary, vendorSpend, options, vendors, owners, costCentres, baseCurrency, can }) {
    const [f, setF] = useState({ ...filters, q: filters.q ?? '' });
    const money = can.viewAmounts !== false;
    const apply = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '' && v !== false));
        router.get(route('finance.contracts.index'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };
    const set = (k) => (e) => apply({ ...f, [k]: e.target.value });
    const toggle = (k, v) => apply({ ...f, [k]: f[k] === v ? null : v });
    const fmt = (v) => moneyOrMask(v, baseCurrency, money, { decimals: 0 });
    const dueChip = (n) => (
        <button key={n} type="button" aria-pressed={Number(f.due) === n} onClick={() => toggle('due', n)}
            className={`rounded-lg border px-2.5 py-1 text-xs ${Number(f.due) === n ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#2D3748]'}`}>
            Due in {n} days: <b>{summary[`due_${n}`]}</b>
        </button>
    );

    return (
        <AuthenticatedLayout header="Contracts">
            <Head title="Contracts" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Contracts' }]} title="Contracts"
                subtitle="Vendor contracts and subscriptions with renewal windows, notice deadlines and decisions"
                actions={can.create && <Link href={route('finance.contracts.create')} className={BTN_PRIMARY}>New contract</Link>} />
            <ContractsNav active="contracts" />

            <div className="mb-3 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard label="Active" value={summary.active} sublabel={`${summary.count} contracts in total`} icon="check_circle" tint="bg-[#E7F4EC] text-[#2D7D46]" onClick={() => apply({ ...f, status: 'active' })} />
                <StatCard label="In renewal window" value={summary.in_window} sublabel="Decision needed" icon="event_upcoming" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => apply({ ...f, status: 'in_renewal_window' })} />
                <StatCard label="Passed" value={summary.passed} sublabel="Notice or end date passed, no decision" icon="priority_high" tint="bg-[#F9DEDC] text-[#8C1D18]" onClick={() => toggle('passed', true)} />
                <StatCard label="Auto-renewing" value={summary.auto_renew} sublabel="Will roll without a decision" icon="autorenew" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <StatCard label="Annualised value" value={fmt(summary.annualised_base_total)} sublabel={`Live contracts, ${baseCurrency}`} icon="payments" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
            </div>
            <div className="mb-4 flex flex-wrap items-center gap-2" aria-label="Due soon filters">{[30, 60, 90].map(dueChip)}</div>

            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-6">
                <div className="col-span-2 lg:col-span-1"><label className={LABEL} htmlFor="c-q">Search</label><input id="c-q" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && apply(f)} onBlur={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="Code, title or vendor" className={FIELD} /></div>
                <div><label className={LABEL} htmlFor="c-st">Status</label><select id="c-st" value={f.status ?? ''} onChange={set('status')} className={FIELD}><option value="">All</option>{options.statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="c-ty">Type</label><select id="c-ty" value={f.type ?? ''} onChange={set('type')} className={FIELD}><option value="">All</option>{options.types.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="c-v">Vendor</label><select id="c-v" value={f.vendor_id ?? ''} onChange={set('vendor_id')} className={FIELD}><option value="">All</option>{vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="c-o">Owner</label><select id="c-o" value={f.owner_id ?? ''} onChange={set('owner_id')} className={FIELD}><option value="">All</option>{owners.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="c-cc">Cost centre</label><select id="c-cc" value={f.cost_centre_id ?? ''} onChange={set('cost_centre_id')} className={FIELD}><option value="">All</option>{costCentres.map((v) => <option key={v.id} value={v.id}>{v.code} {v.name}</option>)}</select></div>
                <label className="flex items-center gap-2 text-sm text-[#2D3748]"><input type="checkbox" checked={!!f.mine} onChange={(e) => apply({ ...f, mine: e.target.checked })} /> My contracts</label>
                <label className="flex items-center gap-2 text-sm text-[#2D3748]"><input type="checkbox" checked={!!f.passed} onChange={(e) => apply({ ...f, passed: e.target.checked })} /> Passed only</label>
            </div>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-4">
                <div className="xl:col-span-3">
                    <Card>
                        <Table head={['Contract', 'Title', 'Vendor', 'Type', 'Status', 'Ends on', 'Notice deadline', 'Value (base)', 'Renewal']}
                            empty={contracts.length === 0 && <Empty>No contracts match these filters.{can.create && <> <Link className="underline" href={route('finance.contracts.create')}>Add a contract</Link>.</>}</Empty>}>
                            {contracts.map((c) => {
                                const closed = ['renewed', 'terminated', 'replaced', 'draft'].includes(c.status);
                                return (
                                    <tr key={c.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(route('finance.contracts.show', c.id))}>
                                        <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link href={route('finance.contracts.show', c.id)} onClick={(e) => e.stopPropagation()} className="text-[#0A1F44] underline">{c.contract_code}</Link></td>
                                        <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">
                                            {c.title}
                                            <div className="mt-0.5 flex flex-wrap gap-1">
                                                {c.in_passed && <Chip size="xs" tone="error" icon="priority_high">Passed</Chip>}
                                                {c.licence_position === 'over' && <Chip size="xs" tone="error">Over-licensed</Chip>}
                                            </div>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs">{c.vendor?.name ?? '—'}</td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs">{humanize(c.type)}</td>
                                        <td className="px-4 py-2"><ContractStatusChip status={c.status} /></td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(c.ends_on)} <DaysChip days={c.days_to_end} muted={closed} /></td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(c.notice_deadline_on)} <DaysChip days={c.days_to_notice} muted={closed} /></td>
                                        <td className="whitespace-nowrap px-4 py-2 text-right text-xs">
                                            <span className="tabular-nums font-medium" title={c.currency !== baseCurrency && money ? `${c.currency} ${Number(c.contract_value).toLocaleString()} in contract currency` : undefined}>{fmt(c.base_contract_value)}</span>
                                            {c.amount_band_label && <div><Chip size="xs" tone="tertiary">{c.amount_band_label}</Chip></div>}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2 text-xs">
                                            {c.open_renewal ? <Chip size="xs" tone="info">{c.open_renewal.renewal_code} {humanize(c.open_renewal.status)}</Chip> : c.is_auto ? <Chip size="xs" tone="neutral" icon="autorenew">Auto-renews</Chip> : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                </div>
                <Card title="Vendor spend">
                    {vendorSpend.length === 0 ? <Empty>{money ? 'No live contracts yet.' : 'Amounts are hidden for your role.'}</Empty> : (
                        <ul className="divide-y divide-gray-50">
                            {vendorSpend.map((v) => (
                                <li key={v.vendor.id}>
                                    <button type="button" onClick={() => apply({ ...f, vendor_id: String(v.vendor.id) })} className="flex w-full items-center justify-between gap-3 px-5 py-2 text-left text-sm hover:bg-gray-50">
                                        <span className="min-w-0"><span className="block truncate text-[#2D3748]">{v.vendor.name}</span><span className="text-xs text-[#718096]">{v.contracts} contract{v.contracts === 1 ? '' : 's'}</span></span>
                                        <span className="text-right tabular-nums"><span className="block font-medium">{fmt(v.base_contract_value)}</span><span className="text-xs text-[#718096]">{fmt(v.annualised_base_value)} / yr</span></span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="border-t border-gray-100 px-5 py-2 text-[11px] text-[#718096]">Contracted value only. Actual spend arrives with Expenses.</p>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

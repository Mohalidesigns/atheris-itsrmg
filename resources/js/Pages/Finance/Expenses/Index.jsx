import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Amount from '@/Components/Finance/Expenses/Amount';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import ImportPanel from '@/Components/Finance/Expenses/ImportPanel';
import useFilters from '@/Components/Finance/Expenses/useFilters';
import { SearchFilter, SelectFilter, Toggle } from '@/Components/Finance/Expenses/FilterField';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ filters, expenses, truncated, summary, options, costCentres, vendors, fiscalYears, selectedFiscalYear, baseCurrency, can }) {
    const { f, setF, apply, set, toggle } = useFilters('finance.expenses.index', { ...filters, fiscal_year_id: filters.fiscal_year_id ?? selectedFiscalYear ?? '' });
    const [importing, setImporting] = useState(false);
    const money = can.viewAmounts !== false;
    const fmt = (v) => moneyOrMask(v, baseCurrency, money, { decimals: 0 });

    return (
        <AuthenticatedLayout header="Expenses">
            <Head title="Expenses" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Expenses' }]} title="Expenses"
                subtitle="Expense requests checked against budget availability, approved, then committed as purchase orders"
                actions={<>
                    {can.import && <button type="button" onClick={() => setImporting(!importing)} className={BTN_SECONDARY}>Import CSV</button>}
                    {can.create && <Link href={route('finance.expenses.create')} className={BTN_PRIMARY}>New expense request</Link>}
                </>} />
            <ExpensesNav active="expenses" />
            {importing && can.import && <div className="mb-4"><ImportPanel routeName="finance.expenses.import" type="expenses" hint="Columns: title, amount, currency, cost_centre_code, budget_line, vendor, needed_by" /></div>}

            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard label="Awaiting my decision" value={summary.awaiting_me} sublabel={`${summary.submitted} submitted`} icon="pending_actions" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => toggle('awaiting_me')} />
                <StatCard label="Approved, not committed" value={summary.approved} sublabel={`${summary.draft} drafts`} icon="task_alt" tint="bg-[#EAF0FB] text-[#1D4ED8]" onClick={() => apply({ ...f, status: 'approved' })} />
                <StatCard label="Committed" value={fmt(summary.committed_base)} sublabel={`${summary.committed} requests, ${baseCurrency}`} icon="lock_clock" tint="bg-[#E7F4EC] text-[#2D7D46]" onClick={() => apply({ ...f, status: 'committed' })} />
                <StatCard label="Unbudgeted" value={summary.unbudgeted_count} sublabel={fmt(summary.unbudgeted_base)} icon="warning" tint="bg-[#F9DEDC] text-[#8C1D18]" onClick={() => toggle('unbudgeted')} />
                <StatCard label="Emergency / over budget" value={`${summary.emergency_count} / ${summary.over_budget_count}`} sublabel="Flagged requests" icon="emergency" tint="bg-[#F7F1E6] text-[#8A6A2E]" onClick={() => toggle('emergency')} />
            </div>

            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-6">
                <SearchFilter id="e-q" value={f.q} setValue={(q) => setF({ ...f, q })} apply={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="Code, title" />
                <SelectFilter id="e-st" label="Status" value={f.status} onChange={set('status')}>{options.statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="e-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')}>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="e-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
                <SelectFilter id="e-v" label="Vendor" value={f.vendor_id} onChange={set('vendor_id')}>{vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}</SelectFilter>
                <div className="flex flex-col justify-end gap-1">
                    <Toggle label="Mine" checked={f.mine} onChange={(v) => apply({ ...f, mine: v })} />
                    <Toggle label="Awaiting me" checked={f.awaiting_me} onChange={(v) => apply({ ...f, awaiting_me: v })} />
                </div>
            </div>

            <Card>
                <Table head={['Request', 'Title', 'Cost centre', 'Vendor', 'Status', 'Amount', 'Needed by', 'Stage', 'PO']}
                    empty={expenses.length === 0 && <Empty>No expense requests match these filters.{can.create && <> <Link className="underline" href={route('finance.expenses.create')}>Raise one</Link>.</>}</Empty>}>
                    {expenses.map((x) => (
                        <tr key={x.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(route('finance.expenses.show', x.id))}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link href={route('finance.expenses.show', x.id)} onClick={(e) => e.stopPropagation()} className="text-[#0A1F44] underline">{x.request_code}</Link></td>
                            <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">{x.title}
                                <div className="mt-0.5 flex flex-wrap gap-1">
                                    {x.unbudgeted && <Chip size="xs" tone="error">Unbudgeted</Chip>}
                                    {x.is_emergency && <Chip size="xs" tone="warning" icon="emergency">Emergency</Chip>}
                                    {x.over_budget && <Chip size="xs" tone="error">Over budget</Chip>}
                                    {x.is_capex && <Chip size="xs" tone="info">Capex</Chip>}
                                    {x.is_lease && <Chip size="xs" tone="tertiary">Lease</Chip>}
                                </div></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{x.cost_centre?.code}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{x.vendor?.name ?? '—'}</td>
                            <td className="px-4 py-2"><StatusChip status={x.status} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs font-medium"><Amount value={x.total_amount} currency={x.currency} base={x.base_total_amount} baseCurrency={baseCurrency} money={money} decimals={0} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(x.needed_by)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{x.current_stage ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{x.po ? <Link href={route('finance.purchase-orders.show', x.po.id)} onClick={(e) => e.stopPropagation()} className="text-[#0A1F44] underline">{x.po.po_code}</Link> : '—'}</td>
                        </tr>
                    ))}
                </Table>
                {truncated && <p className="border-t border-gray-100 px-5 py-2 text-xs text-[#718096]">Showing the first 500 results. Narrow the filters to see more.</p>}
            </Card>
        </AuthenticatedLayout>
    );
}

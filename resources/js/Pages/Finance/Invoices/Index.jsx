import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import AgeingBar from '@/Components/Finance/Expenses/AgeingBar';
import Amount from '@/Components/Finance/Expenses/Amount';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import ImportPanel from '@/Components/Finance/Expenses/ImportPanel';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import useFilters from '@/Components/Finance/Expenses/useFilters';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { SearchFilter, SelectFilter, Toggle } from '@/Components/Finance/Expenses/FilterField';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, daysLabel, localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ filters, invoices, summary, ageing, options, vendors, costCentres, can, ...rest }) {
    const baseCurrency = useBaseCurrency(rest.baseCurrency);
    const { f, setF, apply, set, toggle } = useFilters('finance.invoices.index', filters);
    const [importing, setImporting] = useState(false);
    const money = can.viewAmounts !== false;
    return (
        <AuthenticatedLayout header="Invoices">
            <Head title="Invoices" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Invoices' }]} title="Invoices" subtitle="Vendor invoices, three-way match against order and receipt, approval and payment"
                actions={<>
                    {can.import && <button type="button" onClick={() => setImporting(!importing)} className={BTN_SECONDARY}>Import CSV</button>}
                    {can.create && <Link href={route('finance.invoices.create')} className={BTN_PRIMARY}>Capture invoice</Link>}
                </>} />
            <ExpensesNav active="invoices" />
            {importing && can.import && <div className="mb-4"><ImportPanel routeName="finance.invoices.import" type="invoices" hint="Columns: vendor_invoice_no, vendor, invoice_date, due_on, currency, subtotal, po_code" /></div>}
            <div className="mb-4 grid grid-cols-2 gap-4 xl:grid-cols-5">
                <StatCard label="Awaiting my decision" value={summary.awaiting_me} sublabel={`${summary.count} invoices`} icon="pending_actions" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => toggle('awaiting_me')} />
                <StatCard label="Unpaid" value={moneyOrMask(summary.unpaid_base, baseCurrency, money, { decimals: 0 })} sublabel={baseCurrency} icon="payments" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <StatCard label="Overdue" value={summary.overdue_count} sublabel={moneyOrMask(summary.overdue_base, baseCurrency, money, { decimals: 0 })} icon="alarm" tint="bg-[#F9DEDC] text-[#8C1D18]" onClick={() => toggle('overdue')} />
                <StatCard label="Due in 7 days" value={summary.due_7_count} icon="event_upcoming" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => toggle('due', 7)} />
                <StatCard label="Variance / disputed" value={`${summary.variance_count} / ${summary.disputed_count}`} icon="rule" tint="bg-[#F7F1E6] text-[#8A6A2E]" onClick={() => apply({ ...f, match_status: 'variance' })} />
            </div>
            <div className="mb-4"><AgeingBar ageing={ageing} baseCurrency={baseCurrency} money={money} /></div>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-6">
                <SearchFilter id="i-q" value={f.q} setValue={(q) => setF({ ...f, q })} apply={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="Code, vendor ref" />
                <SelectFilter id="i-st" label="Status" value={f.status} onChange={set('status')}>{options.statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="i-ms" label="Match" value={f.match_status} onChange={set('match_status')}>{options.matchStatuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="i-v" label="Vendor" value={f.vendor_id} onChange={set('vendor_id')}>{vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}</SelectFilter>
                <SelectFilter id="i-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
                <div className="flex flex-col justify-end gap-1"><Toggle label="Overdue only" checked={f.overdue} onChange={(v) => apply({ ...f, overdue: v })} /><Toggle label="Mine" checked={f.mine} onChange={(v) => apply({ ...f, mine: v })} /></div>
            </div>
            <Card>
                <Table head={['Invoice', 'Vendor ref', 'Vendor', 'Status', 'Match', 'Invoice date', 'Due', 'Total', 'Payable']} empty={invoices.length === 0 && <Empty>No invoices match these filters.</Empty>}>
                    {invoices.map((i) => (
                        <tr key={i.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(route('finance.invoices.show', i.id))}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link href={route('finance.invoices.show', i.id)} onClick={(e) => e.stopPropagation()} className="text-[#0A1F44] underline">{i.invoice_code}</Link>
                                {i.is_expected && <Chip size="xs" tone="info" className="ml-1">Expected</Chip>}{i.is_lease && <Chip size="xs" tone="tertiary" className="ml-1">Lease</Chip>}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{i.vendor_invoice_no}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{i.vendor?.name ?? '—'}</td>
                            <td className="px-4 py-2"><StatusChip kind="invoice" status={i.status} /></td>
                            <td className="px-4 py-2"><StatusChip kind="match" status={i.match_status} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(i.invoice_date)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(i.due_on)} {i.status !== 'paid' && i.status !== 'cancelled' && <span className={i.is_overdue ? 'text-[#B3261E]' : 'text-[#718096]'}>{daysLabel(i.days_to_due)}</span>}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs"><Amount value={i.total_amount} currency={i.currency} base={i.base_total_amount} baseCurrency={baseCurrency} money={money} decimals={0} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs"><Amount value={i.payable_amount} currency={i.currency} money={money} decimals={0} secondary={false} /></td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AuthenticatedLayout>
    );
}

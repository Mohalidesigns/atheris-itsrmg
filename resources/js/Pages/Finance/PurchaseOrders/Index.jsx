import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Amount from '@/Components/Finance/Expenses/Amount';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import useFilters from '@/Components/Finance/Expenses/useFilters';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { SearchFilter, SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { localDate, moneyOrMask } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ filters, orders, summary, options, vendors, costCentres, can, ...rest }) {
    const baseCurrency = useBaseCurrency(rest.baseCurrency);
    const { f, setF, apply, set } = useFilters('finance.purchase-orders.index', filters);
    const money = can.viewAmounts !== false;
    return (
        <AuthenticatedLayout header="Purchase Orders">
            <Head title="Purchase Orders" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Purchase Orders' }]} title="Purchase Orders" subtitle="Commitments raised from approved expense requests, with receipt and invoicing progress" />
            <ExpensesNav active="purchase-orders" />
            <div className="mb-4 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="Open" value={summary.open} icon="receipt_long" tint="bg-[#EAF0FB] text-[#1D4ED8]" onClick={() => apply({ ...f, status: 'open' })} />
                <StatCard label="Partially received" value={summary.partially_received} icon="inventory" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => apply({ ...f, status: 'partially_received' })} />
                <StatCard label="Received" value={summary.received} icon="task_alt" tint="bg-[#E7F4EC] text-[#2D7D46]" onClick={() => apply({ ...f, status: 'received' })} />
                <StatCard label="Open commitment" value={moneyOrMask(summary.open_commitment_base, baseCurrency, money, { decimals: 0 })} sublabel={`${summary.closed} closed, ${baseCurrency}`} icon="lock_clock" tint="bg-[#F7F1E6] text-[#8A6A2E]" />
            </div>
            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-4">
                <SearchFilter id="p-q" value={f.q} setValue={(q) => setF({ ...f, q })} apply={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="PO, request or vendor" />
                <SelectFilter id="p-st" label="Status" value={f.status} onChange={set('status')}>{options.statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="p-v" label="Vendor" value={f.vendor_id} onChange={set('vendor_id')}>{vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}</SelectFilter>
                <SelectFilter id="p-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
            </div>
            <Card>
                <Table head={['PO', 'Request', 'Vendor', 'Status', 'PO date', 'Expected', 'Total', 'Received', 'Invoiced', 'Remaining']} empty={orders.length === 0 && <Empty>No purchase orders match these filters.</Empty>}>
                    {orders.map((o) => (
                        <tr key={o.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(route('finance.purchase-orders.show', o.id))}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link href={route('finance.purchase-orders.show', o.id)} onClick={(e) => e.stopPropagation()} className="text-[#0A1F44] underline">{o.po_code}</Link>{o.is_lease && <Chip size="xs" tone="tertiary" className="ml-1">Lease</Chip>}</td>
                            <td className="min-w-[10rem] px-4 py-2 text-xs"><span className="font-mono">{o.expense_request?.request_code}</span> {o.expense_request?.title}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{o.vendor?.name ?? '—'}</td>
                            <td className="px-4 py-2"><StatusChip kind="po" status={o.status} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(o.po_date)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(o.expected_delivery_on)}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs font-medium"><Amount value={o.total_amount} currency={o.currency} base={o.base_total_amount} baseCurrency={baseCurrency} money={money} decimals={0} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs">{Number(o.received_pct ?? 0).toFixed(0)}%</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs"><Amount value={o.invoiced_amount} currency={o.currency} money={money} decimals={0} secondary={false} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs">{moneyOrMask(o.remaining_commitment_base, baseCurrency, money, { decimals: 0 })}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AuthenticatedLayout>
    );
}

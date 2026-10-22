import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import ActivityPanel from '@/Components/Finance/Expenses/ActivityPanel';
import Amount from '@/Components/Finance/Expenses/Amount';
import DocumentsPanel from '@/Components/Finance/Expenses/DocumentsPanel';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import { PoLines, Receipts } from '@/Components/Finance/Expenses/PoLinesTable';
import ReasonModal from '@/Components/Finance/Expenses/ReasonModal';
import ReceiveGoodsModal from '@/Components/Finance/Expenses/ReceiveGoodsModal';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, DOC_KINDS, localDate } from '@/Utils/finance';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

const Fact = ({ label, children }) => <div className="min-w-0"><dt className="text-xs text-[#718096]">{label}</dt><dd className="break-words text-sm text-[#2D3748]">{children || '—'}</dd></div>;

export default function Show(props) {
    const { po, expense, invoices, documents, activity, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can.viewAmounts !== false;
    const [modal, setModal] = useState(null);
    const m = (v, base) => <Amount value={v} currency={po.currency} base={base} baseCurrency={baseCurrency} money={money} />;

    return (
        <AuthenticatedLayout header={po.po_code}>
            <Head title={`Purchase order ${po.po_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Purchase Orders', href: route('finance.purchase-orders.index') }, { label: po.po_code }]} title={`${po.po_code} ${po.vendor?.name ?? ''}`} subtitle={expense?.title} />
            <ExpensesNav active="purchase-orders" />

            <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <StatusChip kind="po" status={po.status} size="sm" />
                    <span className="font-medium text-[#0A1F44]">{m(po.total_amount, po.base_total_amount)}</span>
                    <span className="text-[#4A5568]">Received {Number(po.received_pct ?? 0).toFixed(0)}%</span>
                    {po.is_lease && <Chip size="xs" tone="tertiary">Lease</Chip>}
                    {expense && <Link className="text-xs text-[#0A1F44] underline" href={route('finance.expenses.show', expense.id)}>{expense.request_code}</Link>}
                </div>
            </div>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {can.receive && <button type="button" onClick={() => setModal('receive')} className={BTN_PRIMARY}>Receive goods</button>}
                {can.captureInvoice && <Link href={route('finance.invoices.create', { po_id: po.id })} className={BTN_SECONDARY}>Capture invoice</Link>}
                {can.close && <button type="button" onClick={() => setModal('close')} className={BTN_SECONDARY}>Close PO</button>}
                {can.cancel && <button type="button" onClick={() => setModal('cancel')} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Cancel PO</button>}
            </div>
            {errors?.receive && <p role="alert" className="mb-3 text-sm text-[#B3261E]">{errors.receive}</p>}

            <div className="space-y-4">
                <Card title="Order">
                    <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                        <Fact label="Vendor">{po.vendor?.name}</Fact>
                        <Fact label="Cost centre">{po.cost_centre && `${po.cost_centre.code} ${po.cost_centre.name}`}</Fact>
                        <Fact label="Budget line">{po.budget_line && <Link className="text-[#0A1F44] underline" href={route('finance.budgets.show', { budget: po.budget_line.budget_id, tab: 'lines' })}>{po.budget_line.label}</Link>}</Fact>
                        <Fact label="Issued by">{po.issued_by?.name}</Fact>
                        <Fact label="PO date">{localDate(po.po_date)}</Fact>
                        <Fact label="Expected delivery">{localDate(po.expected_delivery_on)}</Fact>
                        <Fact label="External reference">{po.external_reference}</Fact>
                        <Fact label="FX rate">{po.fx_rate && po.currency !== baseCurrency && money ? `${Number(po.fx_rate)} on ${localDate(po.fx_rate_date)}` : null}</Fact>
                        <Fact label="Subtotal">{m(po.subtotal)}</Fact>
                        <Fact label="Tax">{m(po.tax_amount)}</Fact>
                        <Fact label="Invoiced">{m(po.invoiced_amount)}</Fact>
                        <Fact label="Remaining commitment (base)"><Amount value={po.remaining_commitment_base} currency={baseCurrency} money={money} /></Fact>
                    </dl>
                </Card>
                <PoLines po={po} money={money} />
                <Receipts po={po} />
                <Card title="Invoices" right={can.captureInvoice && <Link href={route('finance.invoices.create', { po_id: po.id })} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white">Capture invoice</Link>}>
                    <Table head={['Invoice', 'Vendor ref', 'Status', 'Match', 'Total', 'Due']} empty={invoices.length === 0 && <Empty>No invoices against this order.</Empty>}>
                        {invoices.map((i) => (
                            <tr key={i.id}>
                                <td className="px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] underline" href={route('finance.invoices.show', i.id)}>{i.invoice_code}</Link></td>
                                <td className="px-4 py-2 text-xs">{i.vendor_invoice_no}</td>
                                <td className="px-4 py-2"><StatusChip kind="invoice" status={i.status} /></td>
                                <td className="px-4 py-2"><StatusChip kind="match" status={i.match_status} /></td>
                                <td className="px-4 py-2 text-right text-xs"><Amount value={i.total_amount} currency={i.currency} base={i.base_total_amount} baseCurrency={baseCurrency} money={money} /></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(i.due_on)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
                <DocumentsPanel subject="purchase_order" subjectId={po.id} documents={documents} kinds={DOC_KINDS} canAttach={can.attach} defaultKind="purchase_order" />
                <ActivityPanel activity={activity} />
            </div>

            {modal === 'receive' && <ReceiveGoodsModal show po={po} onClose={() => setModal(null)} />}
            <ReasonModal show={modal === 'close'} onClose={() => setModal(null)} title="Close purchase order" routeName="finance.purchase-orders.close" params={po.id} field="note" label="Note" required={false} submitLabel="Close PO" errors={errors ?? {}} />
            <ReasonModal show={modal === 'cancel'} onClose={() => setModal(null)} title="Cancel purchase order" routeName="finance.purchase-orders.cancel" params={po.id} submitLabel="Cancel PO" errors={errors ?? {}} />
        </AuthenticatedLayout>
    );
}

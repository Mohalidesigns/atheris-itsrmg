import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import ActivityPanel from '@/Components/Finance/Expenses/ActivityPanel';
import Amount from '@/Components/Finance/Expenses/Amount';
import ApprovalPanel from '@/Components/Finance/Expenses/ApprovalPanel';
import DocumentsPanel from '@/Components/Finance/Expenses/DocumentsPanel';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import InvoicePayModals from '@/Components/Finance/Expenses/InvoicePayModals';
import MatchPanel from '@/Components/Finance/Expenses/MatchPanel';
import ReasonModal from '@/Components/Finance/Expenses/ReasonModal';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, DOC_KINDS, daysLabel, humanize, localDate } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const Fact = ({ label, children }) => <div className="min-w-0"><dt className="text-xs text-[#718096]">{label}</dt><dd className="break-words text-sm text-[#2D3748]">{children || '—'}</dd></div>;
const TABS = [['overview', 'Overview'], ['match', 'Match'], ['approval', 'Approval'], ['documents', 'Documents'], ['activity', 'Activity']];

export default function Show(props) {
    const { invoice: v, po, expense, contract, approval, actual, prepayments, documents, activity, sod, periods, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can.viewAmounts !== false;
    const [tab, setTab] = useState('overview');
    const [modal, setModal] = useState(null);
    const [period, setPeriod] = useState(v.period?.id ?? '');
    const post = (name, confirmText, data = {}) => () => (!confirmText || confirm(confirmText)) && router.post(route(name, v.id), data, { preserveScroll: true });
    const remove = () => confirm(`Delete draft ${v.invoice_code}?`) && router.delete(route('finance.invoices.destroy', v.id));
    const m = (val, base) => <Amount value={val} currency={v.currency} base={base} baseCurrency={baseCurrency} money={money} />;
    const err = errors ?? {};

    return (
        <AuthenticatedLayout header={v.invoice_code}>
            <Head title={`Invoice ${v.invoice_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Invoices', href: route('finance.invoices.index') }, { label: v.invoice_code }]} title={`${v.invoice_code} ${v.vendor?.name ?? ''}`} subtitle={`Vendor invoice ${v.vendor_invoice_no}`} />
            <ExpensesNav active="invoices" />

            {v.is_overdue && v.status !== 'paid' && v.status !== 'cancelled' && <div role="alert" className="mb-3 flex items-start gap-2 rounded-lg border border-[#B3261E]/30 bg-[#F9DEDC] px-4 py-3 text-sm text-[#8C1D18]"><Icon name="alarm" size={20} className="mt-0.5" /><span>Overdue since {localDate(v.due_on)} ({daysLabel(v.days_to_due)}).</span></div>}
            {v.match_status === 'variance' && <div role="alert" className="mb-3 flex items-start gap-2 rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-4 py-3 text-sm text-[#5F4300]"><Icon name="rule" size={20} className="mt-0.5" /><span>The match is outside tolerance. {can.override ? 'Approvers can override with a reason.' : 'It needs an override before it can be approved.'}</span></div>}
            {v.status === 'disputed' && <p className="mb-3 rounded-lg bg-[#F9DEDC] px-4 py-2 text-sm text-[#8C1D18]">Disputed{v.disputed_at ? ` on ${localDate(v.disputed_at)}` : ''}: {v.dispute_reason}</p>}
            {v.rejection_reason && <p className="mb-3 rounded-lg bg-[#F9DEDC] px-4 py-2 text-sm text-[#8C1D18]">Rejected or returned: {v.rejection_reason}</p>}
            {v.cancelled_reason && <p className="mb-3 rounded-lg bg-[#F1F2F4] px-4 py-2 text-sm text-[#43474E]">Cancelled: {v.cancelled_reason}</p>}
            {sod?.applies && (
                <div className="mb-3 flex items-start gap-2 rounded-lg border border-[#DFE6FB] bg-[#EEF2FD] px-4 py-3 text-sm text-[#1E3A8A]"><Icon name="gpp_maybe" size={20} className="mt-0.5" />
                    <span>Segregation of duties applies at this amount: anyone who approved the purchase order{sod.po_approvers?.length ? ` (${sod.po_approvers.map((u) => u.name).join(', ')})` : ''} cannot give the final approval.</span></div>
            )}

            <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <StatusChip kind="invoice" status={v.status} size="sm" />
                    <StatusChip kind="match" status={v.match_status} size="sm" />
                    <span className="font-medium text-[#0A1F44]">{m(v.total_amount, v.base_total_amount)}</span>
                    <span className="text-[#4A5568]">Payable {m(v.payable_amount)}</span>
                    {money && v.amount_band_label && <Chip size="xs" tone="tertiary">{v.amount_band_label}</Chip>}
                    {v.is_lease && <Chip size="xs" tone="tertiary">Lease</Chip>}
                    {v.is_expected && <Chip size="xs" tone="info">Expected</Chip>}
                    {v.current_stage && <span className="text-[#4A5568]">Stage: {v.current_stage}</span>}
                </div>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                {can.edit && <Link href={route('finance.invoices.edit', v.id)} className={BTN_SECONDARY}>Edit</Link>}
                {can.match && <button type="button" onClick={post('finance.invoices.match')} className={BTN_SECONDARY}>Re-run match</button>}
                {can.submit && <button type="button" onClick={post('finance.invoices.submit', 'Submit this invoice for approval?', period ? { period_id: period } : {})} className={BTN_PRIMARY}>Submit for approval</button>}
                {can.withdraw && <button type="button" onClick={post('finance.invoices.withdraw', 'Withdraw this invoice back to draft?')} className={BTN_SECONDARY}>Withdraw</button>}
                {can.schedule && <button type="button" onClick={() => setModal('schedule')} className={BTN_PRIMARY}>Schedule payment</button>}
                {can.pay && <button type="button" onClick={() => setModal('pay')} className={BTN_PRIMARY}>Mark paid</button>}
                {can.dispute && <button type="button" onClick={() => setModal('dispute')} className={BTN_SECONDARY}>Dispute</button>}
                {can.resolve && <button type="button" onClick={post('finance.invoices.resolve', 'Resolve this dispute and return the invoice to draft?')} className={BTN_SECONDARY}>Resolve dispute</button>}
                {can.cancel && <button type="button" onClick={() => setModal('cancel')} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Cancel invoice</button>}
                {can.delete && <button type="button" onClick={remove} className="rounded-lg border border-gray-200 px-4 py-2 text-sm text-[#B3261E]">Delete draft</button>}
                {can.edit && v.status === 'draft' && periods?.length > 0 && (
                    <label className="flex items-center gap-1 text-xs text-[#4A5568]">Period <select aria-label="Period" value={period} onChange={(e) => setPeriod(e.target.value)} className="rounded-lg border border-gray-200 px-2 py-1 text-xs">{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
                )}
            </div>
            {['approval', 'workflow', 'submit', 'pay', 'schedule'].map((k) => err[k] && <p key={k} role="alert" className="mb-2 text-sm text-[#B3261E]">{err[k]}</p>)}

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" role="tablist">
                {TABS.map(([k, label]) => <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`border-b-2 px-4 py-2 text-sm ${tab === k ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>{label}</button>)}
            </div>

            {tab === 'overview' && (
                <div className="space-y-4">
                    <Card title="Invoice">
                        <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                            <Fact label="Vendor">{v.vendor?.name}</Fact>
                            <Fact label="Purchase order">{po && <Link className="text-[#0A1F44] underline" href={route('finance.purchase-orders.show', po.id)}>{po.po_code}</Link>}</Fact>
                            <Fact label="Expense request">{expense && <Link className="text-[#0A1F44] underline" href={route('finance.expenses.show', expense.id)}>{expense.request_code}</Link>}</Fact>
                            <Fact label="Contract">{contract && <Link className="text-[#0A1F44] underline" href={route('finance.contracts.show', contract.id)}>{contract.contract_code}</Link>}{contract?.lease_assessment && <span className="block text-xs text-[#718096]">Lease: {humanize(contract.lease_assessment)}</span>}</Fact>
                            <Fact label="Invoice date">{localDate(v.invoice_date)}</Fact>
                            <Fact label="Due">{localDate(v.due_on)}</Fact>
                            <Fact label="Scheduled">{localDate(v.scheduled_on)}</Fact>
                            <Fact label="Paid">{v.paid_on ? `${localDate(v.paid_on)} (${v.payment_reference ?? 'no reference'})` : null}</Fact>
                            <Fact label="Period">{v.period?.name}</Fact>
                            <Fact label="Cost centre">{v.cost_centre && `${v.cost_centre.code} ${v.cost_centre.name}`}</Fact>
                            <Fact label="Budget line">{v.budget_line && <Link className="text-[#0A1F44] underline" href={route('finance.budgets.show', { budget: v.budget_line.budget_id, tab: 'lines' })}>{v.budget_line.label}</Link>}</Fact>
                            <Fact label="GL account">{v.gl_account && `${v.gl_account.code} ${v.gl_account.name}`}</Fact>
                            <Fact label="Subtotal">{m(v.subtotal)}</Fact>
                            <Fact label={v.tax_type ? `VAT (${v.tax_type.code})` : 'VAT'}>{m(v.tax_amount)}</Fact>
                            <Fact label={v.wht_tax_type ? `Withholding (${v.wht_tax_type.code})` : 'Withholding'}>{m(v.wht_amount)}</Fact>
                            <Fact label="FX rate">{v.fx_rate && v.currency !== baseCurrency && money ? `${Number(v.fx_rate)} on ${localDate(v.fx_rate_date)}` : null}</Fact>
                            <Fact label="Submitted by">{v.submitted_by?.name}</Fact>
                            <Fact label="Approved by">{v.approved_by?.name}</Fact>
                            <Fact label="Recurring schedule">{v.recurring?.recurring_code}</Fact>
                            {v.description && <div className="col-span-2 lg:col-span-4"><dt className="text-xs text-[#718096]">Description</dt><dd className="whitespace-pre-line text-sm">{v.description}</dd></div>}
                        </dl>
                    </Card>
                    <Card title="Lines">
                        <Table head={['#', 'Description', 'Quantity', 'Unit cost', 'Amount']} empty={v.lines.length === 0 && <Empty>A flat-subtotal invoice has no lines.</Empty>}>
                            {v.lines.map((l) => <tr key={l.id}><td className="px-4 py-2 text-xs">{l.line_no}</td><td className="px-4 py-2 text-[#2D3748]">{l.description}</td><td className="px-4 py-2 text-right text-xs tabular-nums">{Number(l.quantity)}</td><td className="px-4 py-2 text-right text-xs">{m(l.unit_cost)}</td><td className="px-4 py-2 text-right text-xs">{m(l.amount)}</td></tr>)}
                        </Table>
                    </Card>
                    {actual && <Card title="Posted to actuals"><p className="px-5 py-3 text-sm">{localDate(actual.posted_on)} in {actual.period?.name}: <Amount value={actual.amount} currency={actual.currency} base={actual.base_amount} baseCurrency={baseCurrency} money={money} className="inline-block" /></p></Card>}
                    {prepayments?.length > 0 && (
                        <Card title="Prepayment release schedule">
                            <Table head={['Period', 'Status', 'Amount']}>{prepayments.map((a) => <tr key={a.id}><td className="px-4 py-2 text-xs">{a.period?.name}</td><td className="px-4 py-2"><StatusChip kind="accrual" status={a.status} /></td><td className="px-4 py-2 text-right text-xs"><Amount value={a.amount} currency={a.currency} base={a.base_amount} baseCurrency={baseCurrency} money={money} /></td></tr>)}</Table>
                        </Card>
                    )}
                </div>
            )}
            {tab === 'match' && <MatchPanel invoice={v} baseCurrency={baseCurrency} money={money} actions={can.override && v.match_status === 'variance' && <button type="button" onClick={() => setModal('override')} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white">Override variance</button>} />}
            {tab === 'approval' && <ApprovalPanel prefix="finance.invoices" subject={v} approval={approval} can={can} baseCurrency={baseCurrency} errors={err} />}
            {tab === 'documents' && <DocumentsPanel subject="invoice" subjectId={v.id} documents={documents} kinds={DOC_KINDS} canAttach={can.attach} defaultKind="invoice" />}
            {tab === 'activity' && <ActivityPanel activity={activity} />}

            {(modal === 'schedule' || modal === 'pay') && <InvoicePayModals mode={modal} invoice={v} errors={err} onClose={() => setModal(null)} />}
            <ReasonModal show={modal === 'dispute'} onClose={() => setModal(null)} title="Dispute invoice" routeName="finance.invoices.dispute" params={v.id} submitLabel="Dispute" errors={err} />
            <ReasonModal show={modal === 'cancel'} onClose={() => setModal(null)} title="Cancel invoice" routeName="finance.invoices.cancel" params={v.id} submitLabel="Cancel invoice" errors={err} />
            <ReasonModal show={modal === 'override'} onClose={() => setModal(null)} title="Override match variance" routeName="finance.invoices.override" params={v.id} submitLabel="Override" errors={err} />
        </AuthenticatedLayout>
    );
}

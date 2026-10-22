import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import ActivityPanel from '@/Components/Finance/Expenses/ActivityPanel';
import Amount from '@/Components/Finance/Expenses/Amount';
import ApprovalPanel from '@/Components/Finance/Expenses/ApprovalPanel';
import AvailabilityCard from '@/Components/Finance/Expenses/AvailabilityCard';
import CommitPoModal from '@/Components/Finance/Expenses/CommitPoModal';
import DocumentsPanel from '@/Components/Finance/Expenses/DocumentsPanel';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import ReasonModal from '@/Components/Finance/Expenses/ReasonModal';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, localDate } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS = [['overview', 'Overview'], ['approval', 'Approval'], ['procurement', 'Procurement'], ['documents', 'Documents'], ['activity', 'Activity']];
const Fact = ({ label, children }) => <div className="min-w-0"><dt className="text-xs text-[#718096]">{label}</dt><dd className="break-words text-sm text-[#2D3748]">{children || '—'}</dd></div>;

export default function Show(props) {
    const { expense: x, availability, approval, po, invoices, actuals, documents, activity, options, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can.viewAmounts !== false;
    const [tab, setTab] = useState(TABS.some(([k]) => k === props.tab) ? props.tab : 'overview');
    const [modal, setModal] = useState(null);
    const select = (k) => { setTab(k); try { window.history.replaceState(null, '', route('finance.expenses.show', { expense: x.id, tab: k })); } catch { /* ignore */ } };
    const post = (name, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(name, x.id), {}, { preserveScroll: true });
    const remove = () => confirm(`Delete draft ${x.request_code}?`) && router.delete(route('finance.expenses.destroy', x.id));
    const m = (v, cur = x.currency, base) => <Amount value={v} currency={cur} base={base} baseCurrency={baseCurrency} money={money} />;

    return (
        <AuthenticatedLayout header={x.request_code}>
            <Head title={`Expense ${x.request_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Expenses', href: route('finance.expenses.index') }, { label: x.request_code }]} title={`${x.request_code} ${x.title}`} subtitle={x.cost_centre ? `${x.cost_centre.code} ${x.cost_centre.name}` : undefined} />
            <ExpensesNav active="expenses" />

            {x.unbudgeted && <div role="alert" className="mb-3 flex items-start gap-2 rounded-lg border border-[#B3261E]/30 bg-[#F9DEDC] px-4 py-3 text-sm text-[#8C1D18]"><Icon name="warning" size={20} className="mt-0.5" /><span>Unbudgeted spend: this request has no budget line, so no availability check applied.</span></div>}
            {x.over_budget && <div role="alert" className="mb-3 flex items-start gap-2 rounded-lg border border-[#E5A100]/40 bg-[#FCEBC4] px-4 py-3 text-sm text-[#5F4300]"><Icon name="priority_high" size={20} className="mt-0.5" /><span>Over budget at submission.{x.overbudget_justification ? ` Justification: ${x.overbudget_justification}` : ''}</span></div>}
            {x.rejection_reason && <p className="mb-3 rounded-lg bg-[#F9DEDC] px-4 py-2 text-sm text-[#8C1D18]">Rejected or returned: {x.rejection_reason}</p>}
            {x.cancelled_reason && <p className="mb-3 rounded-lg bg-[#F1F2F4] px-4 py-2 text-sm text-[#43474E]">Cancelled: {x.cancelled_reason}</p>}

            <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <StatusChip status={x.status} size="sm" />
                    {x.current_stage && <span className="text-[#4A5568]">Stage: {x.current_stage}</span>}
                    <span className="font-medium text-[#0A1F44]">{m(x.total_amount, x.currency, x.base_total_amount)}</span>
                    {money && x.amount_band_label && <Chip size="xs" tone="tertiary">{x.amount_band_label}</Chip>}
                    {x.is_emergency && <Chip size="xs" tone="warning" icon="emergency">Emergency</Chip>}
                    {x.is_capex && <Chip size="xs" tone="info">Capex</Chip>}
                    {x.is_lease && <Chip size="xs" tone="tertiary">Lease</Chip>}
                    {x.po && <Link className="text-xs text-[#0A1F44] underline" href={route('finance.purchase-orders.show', x.po.id)}>{x.po.po_code}</Link>}
                </div>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                {can.edit && <Link href={route('finance.expenses.edit', x.id)} className={BTN_SECONDARY}>Edit</Link>}
                {can.submit && <button type="button" onClick={post('finance.expenses.submit', 'Submit this request for approval?')} className={BTN_PRIMARY}>Submit for approval</button>}
                {can.withdraw && <button type="button" onClick={post('finance.expenses.withdraw', 'Withdraw this request back to draft?')} className={BTN_SECONDARY}>Withdraw</button>}
                {can.commit && <button type="button" onClick={() => setModal('commit')} className={BTN_PRIMARY}>Commit as purchase order</button>}
                {can.captureInvoice && <Link href={route('finance.invoices.create', { expense_id: x.id })} className={BTN_SECONDARY}>Capture invoice</Link>}
                {can.close && <button type="button" onClick={post('finance.expenses.close', 'Close this request?')} className={BTN_SECONDARY}>Close</button>}
                {can.cancel && <button type="button" onClick={() => setModal('cancel')} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Cancel request</button>}
                {can.delete && <button type="button" onClick={remove} className="rounded-lg border border-gray-200 px-4 py-2 text-sm text-[#B3261E]">Delete draft</button>}
            </div>
            {errors?.approval && <p role="alert" className="mb-3 text-sm text-[#B3261E]">{errors.approval}</p>}
            {errors?.commit && <p role="alert" className="mb-3 text-sm text-[#B3261E]">{errors.commit}</p>}

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" role="tablist">
                {TABS.map(([k, label]) => (
                    <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => select(k)}
                        className={`border-b-2 px-4 py-2 text-sm ${tab === k ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>{label}</button>
                ))}
            </div>

            {tab === 'overview' && (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <div className="space-y-4 xl:col-span-2">
                        <Card title="Request">
                            <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-3">
                                <Fact label="Requested by">{x.requested_by?.name}</Fact>
                                <Fact label="Submitted">{localDate(x.submitted_at)}</Fact>
                                <Fact label="Approved">{localDate(x.approved_at)}{x.approved_by && ` by ${x.approved_by.name}`}</Fact>
                                <Fact label="Needed by">{localDate(x.needed_by)}</Fact>
                                <Fact label="Vendor">{x.vendor?.name}</Fact>
                                <Fact label="Contract">{x.contract && <Link className="text-[#0A1F44] underline" href={route('finance.contracts.show', x.contract.id)}>{x.contract.contract_code}</Link>}</Fact>
                                <Fact label="Renewal">{x.renewal?.renewal_code}</Fact>
                                <Fact label="Category">{x.category && `${x.category.code} ${x.category.name}`}</Fact>
                                <Fact label="GL account">{x.gl_account && `${x.gl_account.code} ${x.gl_account.name}`}</Fact>
                                <Fact label="Asset">{x.asset?.label}</Fact>
                                <Fact label="Fiscal year">{x.fiscal_year?.year_label}</Fact>
                                <Fact label="Period">{x.period?.name}</Fact>
                                <Fact label="Amount (before tax)">{m(x.amount, x.currency, x.base_amount)}</Fact>
                                <Fact label="Tax">{x.tax_type ? <>{x.tax_type.code} {m(x.tax_amount)}</> : null}</Fact>
                                <Fact label="FX rate">{x.fx_rate && x.currency !== baseCurrency ? `${Number(x.fx_rate)} on ${localDate(x.fx_rate_date)}` : null}</Fact>
                                {x.description && <div className="col-span-2 lg:col-span-3"><dt className="text-xs text-[#718096]">Description</dt><dd className="whitespace-pre-line text-sm text-[#2D3748]">{x.description}</dd></div>}
                                {x.justification && <div className="col-span-2 lg:col-span-3"><dt className="text-xs text-[#718096]">Justification</dt><dd className="whitespace-pre-line text-sm text-[#2D3748]">{x.justification}</dd></div>}
                            </dl>
                        </Card>
                    </div>
                    <AvailabilityCard availability={availability} baseCurrency={baseCurrency} money={money}
                        extra={x.budget_line && <p className="px-5 pb-4 text-xs"><Link className="text-[#0A1F44] underline" href={route('finance.budgets.show', { budget: x.budget_line.budget_id, tab: 'lines' })}>{x.budget_line.budget_code} #{x.budget_line.line_no} {x.budget_line.label}</Link></p>} />
                </div>
            )}
            {tab === 'approval' && <ApprovalPanel prefix="finance.expenses" subject={x} approval={approval} can={can} baseCurrency={baseCurrency} errors={errors ?? {}} />}
            {tab === 'procurement' && (
                <div className="space-y-4">
                    <Card title="Purchase order">
                        {po ? (
                            <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-4">
                                <Fact label="PO"><Link className="text-[#0A1F44] underline" href={route('finance.purchase-orders.show', po.id)}>{po.po_code}</Link></Fact>
                                <Fact label="Status"><StatusChip kind="po" status={po.status} /></Fact>
                                <Fact label="Total">{m(po.total_amount, po.currency, po.base_total_amount)}</Fact>
                                <Fact label="Received">{`${Number(po.received_pct ?? 0).toFixed(0)}%`}</Fact>
                            </dl>
                        ) : <Empty>{x.status === 'approved' ? 'Approved and ready to commit as a purchase order.' : 'No purchase order yet. It is created when an approved request is committed.'}</Empty>}
                    </Card>
                    <Card title="Invoices" right={can.captureInvoice && <Link href={route('finance.invoices.create', { expense_id: x.id })} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white">Capture invoice</Link>}>
                        <Table head={['Invoice', 'Vendor ref', 'Status', 'Match', 'Total', 'Due']} empty={invoices.length === 0 && <Empty>No invoices yet.</Empty>}>
                            {invoices.map((i) => (
                                <tr key={i.id}>
                                    <td className="px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] underline" href={route('finance.invoices.show', i.id)}>{i.invoice_code}</Link></td>
                                    <td className="px-4 py-2 text-xs">{i.vendor_invoice_no}</td>
                                    <td className="px-4 py-2"><StatusChip kind="invoice" status={i.status} /></td>
                                    <td className="px-4 py-2"><StatusChip kind="match" status={i.match_status} /></td>
                                    <td className="px-4 py-2 text-right text-xs">{m(i.total_amount, i.currency, i.base_total_amount)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(i.due_on)}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>
                    <Card title="Posted actuals">
                        <Table head={['Posted', 'Period', 'Description', 'Amount']} empty={actuals.length === 0 && <Empty>Nothing posted to actuals yet.</Empty>}>
                            {actuals.map((a) => <tr key={a.id}><td className="px-4 py-2 text-xs">{localDate(a.posted_on)}</td><td className="px-4 py-2 text-xs">{a.period?.name}</td><td className="px-4 py-2 text-[#2D3748]">{a.description}</td><td className="px-4 py-2 text-right text-xs">{m(a.amount, a.currency, a.base_amount)}</td></tr>)}
                        </Table>
                    </Card>
                </div>
            )}
            {tab === 'documents' && <DocumentsPanel subject="expense" subjectId={x.id} documents={documents} kinds={options.documentKinds} canAttach={can.attach} defaultKind="quote" />}
            {tab === 'activity' && <ActivityPanel activity={activity} />}

            {modal === 'commit' && <CommitPoModal show expense={x} taxTypes={options.taxTypes} onClose={() => setModal(null)} />}
            <ReasonModal show={modal === 'cancel'} onClose={() => setModal(null)} title="Cancel expense request" routeName="finance.expenses.cancel" params={x.id} submitLabel="Cancel request" errors={errors ?? {}} />
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import Chip from '@/Components/Material/Chip';
import { DecisionModal } from '@/Components/Finance/Budgets/BudgetActions';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import Money from '@/Components/Finance/Budgets/Money';
import { RequestOverview, num, sliceBy } from '@/Components/Finance/Budgets/Charts';
import TransferFormModal from '@/Components/Finance/Budgets/TransferFormModal';
import { AddButton, Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, REQUEST_TONES, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';

const STATUSES = ['draft', 'submitted', 'approved', 'applied', 'rejected', 'withdrawn'];
const lineText = (l) => (l ? `${l.budget.budget_code} v${l.budget.version_no} · #${l.line_no} ${l.description} (${l.cost_centre?.code ?? ''})` : null);


export default function Transfers({ filters, transfers, summary, lineOptions, periods, costCentres, costPools, towers, fiscalYears, baseCurrency, can }) {
    const { errors } = usePage().props;
    const [modal, setModal] = useState(null);
    const [open, setOpen] = useState(filters.highlight ?? null);
    const [decision, setDecision] = useState(null);
    const go = (next) => router.get(route('finance.transfers.index'), Object.fromEntries(Object.entries({ ...filters, highlight: null, ...next }).filter(([, v]) => v)), { preserveState: true, replace: true });
    const post = (t, action, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(`finance.transfers.${action}`, t.id), {}, { preserveScroll: true });
    const decide = (payload, opts) => router.post(route(`finance.transfers.${decision.action}`, decision.t.id), payload, { preserveScroll: true, ...opts });

    return (
        <AuthenticatedLayout header="Budget transfers">
            <Head title="Budget transfers" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: 'Transfers' }]} title="Budget transfers"
                subtitle="Virements between baseline lines, approved by delegation-of-authority band"
                actions={can.create && <AddButton onClick={() => setModal('new')}>New transfer</AddButton>} />
            <BudgetsNav active="transfers" />
            <RequestOverview summary={summary} rows={transfers} baseCurrency={baseCurrency} money={can.viewAmounts !== false} onStatus={(status) => go({ status })}
                valueDonuts={[
                    { title: 'Value by scope', data: sliceBy(transfers, (t) => t.crosses_cost_centre === 'yes' ? 'cross' : 'same', (t) => (t.crosses_cost_centre === 'yes' ? 'Crosses cost centre' : 'Same cost centre'), (t) => num(t.base_amount)) },
                    { title: 'Value by DoA band', data: sliceBy(transfers, (t) => t.amount_band || 'none', (t) => (t.amount_band ? humanize(t.amount_band) : 'No band'), (t) => num(t.base_amount)) },
                ]} />
            <div className="mb-4 grid grid-cols-2 gap-3 sm:w-[28rem]">
                <div><label className={LABEL} htmlFor="tr-fy">Fiscal year</label><select id="tr-fy" value={filters.fiscal_year_id ?? ''} onChange={(e) => go({ fiscal_year_id: e.target.value })} className={FIELD}>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="tr-st">Status</label><select id="tr-st" value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value })} className={FIELD}><option value="">All</option>{STATUSES.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</select></div>
            </div>
            {errors.approval && <p className="mb-2 text-sm text-[#B3261E]">{errors.approval}</p>}
            <Card>
                <Table head={['Transfer', 'From', 'To', 'Periods', 'Amount (base)', 'Band', 'Scope', 'Status', 'Requester', '']} empty={transfers.length === 0 && <Empty>No transfers for this selection.</Empty>}>
                    {transfers.map((t) => (
                        <Fragment key={t.id}>
                            <tr className={`align-top ${t.id === filters.highlight ? 'bg-[#FBF7EE]' : ''}`}>
                                <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><button type="button" aria-expanded={open === t.id} onClick={() => setOpen(open === t.id ? null : t.id)} className="text-[#0A1F44] underline">{t.transfer_code}</button></td>
                                <td className="min-w-[12rem] px-4 py-2 text-xs text-[#2D3748]">{lineText(t.from_line)}</td>
                                <td className="min-w-[12rem] px-4 py-2 text-xs text-[#2D3748]">{lineText(t.to_line) ?? `New: ${t.to_description ?? ''} (${t.to_cost_centre?.code ?? ''} ${t.to_cost_pool?.code ?? ''} ${t.to_tower?.code ?? ''})`}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{t.from_period?.name ?? 'Year'} to {t.to_period?.name ?? 'Year'}</td>
                                <td className="px-4 py-2 text-right"><Money value={t.amount} currency={t.currency} base={t.base_amount} baseCurrency={baseCurrency} stacked /></td>
                                <td className="px-4 py-2">{t.amount_band && <Chip size="xs" tone="tertiary">{humanize(t.amount_band)}</Chip>}</td>
                                <td className="px-4 py-2">{t.crosses_cost_centre === 'yes' ? <Chip size="xs" tone="warning">Crosses cost centre</Chip> : <span className="text-xs text-[#718096]">Same cost centre</span>}</td>
                                <td className="px-4 py-2"><Chip size="xs" tone={REQUEST_TONES[t.status] || 'neutral'}>{humanize(t.status)}</Chip></td>
                                <td className="px-4 py-2 text-xs">{t.requested_by?.name ?? '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2"><div className="flex justify-end gap-3 text-xs">
                                    {t.can.edit && <button type="button" onClick={() => setModal(t)} className="text-[#0A1F44] underline">Edit</button>}
                                    {t.can.submit && <button type="button" onClick={post(t, 'submit', 'Submit this transfer for approval?')} className="text-[#0A1F44] underline">Submit</button>}
                                    {t.can.withdraw && <button type="button" onClick={post(t, 'withdraw', 'Withdraw this transfer?')} className="text-[#0A1F44] underline">Withdraw</button>}
                                    {t.can.approve && <button type="button" onClick={() => setDecision({ t, action: 'approve', title: 'Approve transfer', req: false })} className="text-[#1B5530] underline">Approve</button>}
                                    {t.can.reject && <button type="button" onClick={() => setDecision({ t, action: 'reject', title: 'Reject transfer', req: true })} className="text-[#B3261E] underline">Reject</button>}
                                    {t.can.apply && <button type="button" onClick={post(t, 'apply', 'Apply this transfer to the baseline budgets?')} className="text-[#0A1F44] underline">Apply</button>}
                                </div></td>
                            </tr>
                            {open === t.id && (
                                <tr><td colSpan={10} className="space-y-3 bg-gray-50 px-4 py-3">
                                    <p className="text-sm text-[#2D3748]"><span className="text-xs text-[#718096]">Reason: </span>{t.reason}</p>
                                    {t.rejection_reason && <p className="text-sm text-[#8C1D18]">Rejected: {t.rejection_reason}</p>}
                                    {t.applied_at && <p className="text-xs text-[#718096]">Applied {formatDate(t.applied_at)}</p>}
                                    <ApprovalTrail process="finance.budget_transfer" id={t.id} version={t.status} />
                                </td></tr>
                            )}
                        </Fragment>
                    ))}
                </Table>
            </Card>
            <TransferFormModal show={modal !== null} onClose={() => setModal(null)} transfer={modal === 'new' ? null : modal} fiscalYearId={filters.fiscal_year_id} lineOptions={lineOptions}
                periods={periods} costCentres={costCentres} costPools={costPools} towers={towers} baseCurrency={baseCurrency} />
            {decision && <DecisionModal show title={decision.title} noteRequired={decision.req} errors={errors} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.title.split(' ')[0]} />}
        </AuthenticatedLayout>
    );
}

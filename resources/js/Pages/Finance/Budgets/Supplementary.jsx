import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import Chip from '@/Components/Material/Chip';
import { DecisionModal } from '@/Components/Finance/Budgets/BudgetActions';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import Money from '@/Components/Finance/Budgets/Money';
import { RequestOverview, num, sliceBy } from '@/Components/Finance/Budgets/Charts';
import SupplementaryFormModal from '@/Components/Finance/Budgets/SupplementaryFormModal';
import { AddButton, Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, REQUEST_TONES, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';

const STATUSES = ['draft', 'submitted', 'approved', 'applied', 'rejected', 'withdrawn'];


export default function Supplementary({ filters, requests, summary, lineOptions, costCentres, costPools, towers, subCategories, currencies, fiscalYears, baseCurrency, can }) {
    const { errors } = usePage().props;
    const [modal, setModal] = useState(null);
    const [open, setOpen] = useState(filters.highlight ?? null);
    const [decision, setDecision] = useState(null);
    const go = (next) => router.get(route('finance.supplementary.index'), Object.fromEntries(Object.entries({ ...filters, highlight: null, ...next }).filter(([, v]) => v)), { preserveState: true, replace: true });
    const post = (r, action, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(`finance.supplementary.${action}`, r.id), {}, { preserveScroll: true });
    const decide = (payload, opts) => router.post(route(`finance.supplementary.${decision.action}`, decision.r.id), payload, { preserveScroll: true, ...opts });

    return (
        <AuthenticatedLayout header="Supplementary budgets">
            <Head title="Supplementary budgets" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: 'Supplementary' }]} title="Supplementary budgets"
                subtitle="Requests for additional funding on top of the approved baseline"
                actions={can.create && <AddButton onClick={() => setModal('new')}>New request</AddButton>} />
            <BudgetsNav active="supplementary" />
            <RequestOverview summary={summary} rows={requests} baseCurrency={baseCurrency} money={can.viewAmounts !== false} onStatus={(status) => go({ status })}
                valueDonuts={[
                    { title: 'CapEx vs OpEx', data: sliceBy(requests, (r) => (r.is_capex ? 'capex' : 'opex'), (r) => (r.is_capex ? 'CapEx' : 'OpEx'), (r) => num(r.base_amount)) },
                    { title: 'By cost centre', data: sliceBy(requests, (r) => r.cost_centre?.id, (r) => `${r.cost_centre.code} ${r.cost_centre.name}`, (r) => num(r.base_amount)) },
                ]} />
            <div className="mb-4 grid grid-cols-2 gap-3 sm:w-[28rem]">
                <div><label className={LABEL} htmlFor="sp-fy">Fiscal year</label><select id="sp-fy" value={filters.fiscal_year_id ?? ''} onChange={(e) => go({ fiscal_year_id: e.target.value })} className={FIELD}>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="sp-st">Status</label><select id="sp-st" value={filters.status ?? ''} onChange={(e) => go({ status: e.target.value })} className={FIELD}><option value="">All</option>{STATUSES.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</select></div>
            </div>
            {errors.approval && <p className="mb-2 text-sm text-[#B3261E]">{errors.approval}</p>}
            <Card>
                <Table head={['Request', 'Title', 'Cost centre', 'Applies to', 'Type', 'Amount', 'Band', 'Status', 'Requester', '']} empty={requests.length === 0 && <Empty>No supplementary requests for this selection.</Empty>}>
                    {requests.map((r) => (
                        <Fragment key={r.id}>
                            <tr className={`align-top ${r.id === filters.highlight ? 'bg-[#FBF7EE]' : ''}`}>
                                <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><button type="button" aria-expanded={open === r.id} onClick={() => setOpen(open === r.id ? null : r.id)} className="text-[#0A1F44] underline">{r.request_code}</button></td>
                                <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{r.title}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{r.cost_centre ? `${r.cost_centre.code} ${r.cost_centre.name}` : '—'}</td>
                                <td className="min-w-[10rem] px-4 py-2 text-xs">{r.target_line ? `Line #${r.target_line.line_no} ${r.target_line.description}` : `New line: ${[r.cost_pool?.code, r.tower?.code].filter(Boolean).join(' / ')}`}</td>
                                <td className="px-4 py-2 text-xs">{r.is_capex ? 'CapEx' : 'OpEx'}</td>
                                <td className="px-4 py-2 text-right"><Money value={r.amount} currency={r.currency} base={r.base_amount} baseCurrency={baseCurrency} stacked /></td>
                                <td className="px-4 py-2">{r.amount_band && <Chip size="xs" tone="tertiary">{humanize(r.amount_band)}</Chip>}</td>
                                <td className="px-4 py-2"><Chip size="xs" tone={REQUEST_TONES[r.status] || 'neutral'}>{humanize(r.status)}</Chip></td>
                                <td className="px-4 py-2 text-xs">{r.requested_by?.name ?? '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2"><div className="flex justify-end gap-3 text-xs">
                                    {r.can.edit && <button type="button" onClick={() => setModal(r)} className="text-[#0A1F44] underline">Edit</button>}
                                    {r.can.submit && <button type="button" onClick={post(r, 'submit', 'Submit this request for approval?')} className="text-[#0A1F44] underline">Submit</button>}
                                    {r.can.withdraw && <button type="button" onClick={post(r, 'withdraw', 'Withdraw this request?')} className="text-[#0A1F44] underline">Withdraw</button>}
                                    {r.can.approve && <button type="button" onClick={() => setDecision({ r, action: 'approve', title: 'Approve request', req: false })} className="text-[#1B5530] underline">Approve</button>}
                                    {r.can.reject && <button type="button" onClick={() => setDecision({ r, action: 'reject', title: 'Reject request', req: true })} className="text-[#B3261E] underline">Reject</button>}
                                    {r.can.apply && <button type="button" onClick={post(r, 'apply', 'Apply this request to the baseline budget?')} className="text-[#0A1F44] underline">Apply</button>}
                                </div></td>
                            </tr>
                            {open === r.id && (
                                <tr><td colSpan={10} className="space-y-3 bg-gray-50 px-4 py-3">
                                    <p className="text-sm text-[#2D3748]"><span className="text-xs text-[#718096]">Justification: </span>{r.justification}</p>
                                    {r.rejection_reason && <p className="text-sm text-[#8C1D18]">Rejected: {r.rejection_reason}</p>}
                                    {r.applied_at && <p className="text-xs text-[#718096]">Applied {formatDate(r.applied_at)}</p>}
                                    <ApprovalTrail process="finance.supplementary_budget" id={r.id} version={r.status} />
                                </td></tr>
                            )}
                        </Fragment>
                    ))}
                </Table>
            </Card>
            <SupplementaryFormModal show={modal !== null} onClose={() => setModal(null)} request={modal === 'new' ? null : modal} fiscalYearId={filters.fiscal_year_id} lineOptions={lineOptions}
                costCentres={costCentres} costPools={costPools} towers={towers} subCategories={subCategories} currencies={currencies} baseCurrency={baseCurrency} />
            {decision && <DecisionModal show title={decision.title} noteRequired={decision.req} errors={errors} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.title.split(' ')[0]} />}
        </AuthenticatedLayout>
    );
}

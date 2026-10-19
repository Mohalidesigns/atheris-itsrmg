import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import CapStatusChip from '@/Components/Finance/Capitalisations/CapStatusChip';
import CapitalisationFormModal from '@/Components/Finance/Capitalisations/CapitalisationFormModal';
import ChecklistPanel from '@/Components/Finance/Capitalisations/ChecklistPanel';
import EligibilityCard from '@/Components/Finance/Capitalisations/EligibilityCard';
import ScheduleTable from '@/Components/Finance/Capitalisations/ScheduleTable';
import DocumentsPanel from '@/Components/Finance/Expenses/DocumentsPanel';
import ReasonModal from '@/Components/Finance/Expenses/ReasonModal';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, finMoney, fmtMonths, humanize, localDate, sourceLabel } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const DOC_KINDS = [{ value: 'capitalisation_memo', label: 'Capitalisation memo' }, { value: 'approval', label: 'Approval' }, { value: 'other', label: 'Other' }];

function Fact({ label, children }) {
    return <div className="min-w-0"><dt className="text-xs text-[#718096]">{label}</dt><dd className="break-words text-sm text-[#2D3748]">{children || '—'}</dd></div>;
}

export default function Show(props) {
    const { capitalisation: c, schedule = [], scheduleByYear = [], eligibility, documents = [], activity = [], assets = [], users = [], options, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [editing, setEditing] = useState(false);
    const [reason, setReason] = useState(null); // 'approve' | 'reject' | 'cancel'
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    const post = (name, confirmText) => () => (!confirmText || confirm(confirmText)) && router.post(route(name, c.id), {}, { preserveScroll: true });
    const editable = can.edit && ['draft', 'rejected'].includes(c.status);
    const basePayload = {
        title: c.title, standard: c.standard, available_for_use_on: c.available_for_use_on, amount: c.amount, excluded_amount: c.excluded_amount, excluded_reason: c.excluded_reason, residual_value: c.residual_value,
        useful_life_months: c.useful_life_months, method: c.method, declining_rate_pct: c.declining_rate_pct, create_asset: c.create_asset, asset_id: c.asset?.id ?? null, asset_name: c.asset_name, asset_type: c.asset_type,
        asset_owner_id: c.asset_owner?.id ?? null, source_type: c.source_type, source_id: c.source?.id,
    };

    return (
        <AuthenticatedLayout header={c.capitalisation_code}>
            <Head title={`Capitalisation ${c.capitalisation_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Capitalisation', href: route('finance.capitalisations.index') }, { label: c.capitalisation_code }]}
                title={`${c.capitalisation_code} ${c.title}`} subtitle={`${sourceLabel(c.source_type)}: ${c.source?.label ?? ''}`} />

            <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-gray-200 bg-white p-4 text-sm">
                <CapStatusChip status={c.status} size="sm" />
                <Chip size="xs" tone="primary">{c.standard === 'ias_16' ? 'IAS 16 PP&E' : 'IAS 38 intangible'}</Chip>
                {c.amount_band_label && <Chip size="xs" tone="muted">{c.amount_band_label}</Chip>}
                <span className="font-medium tabular-nums text-[#0A1F44]">{fmt(c.amount)}</span>
                {c.status === 'capitalised' && <span className="text-xs text-[#4A5568]">NBV today {fmt(c.nbv_today)}</span>}
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                {can.edit && editable && <button type="button" onClick={() => setEditing(true)} className={BTN_SECONDARY}>Edit</button>}
                {can.submit && <button type="button" onClick={post('finance.capitalisations.submit')} className={BTN_PRIMARY}>Submit for approval</button>}
                {can.withdraw && <button type="button" onClick={post('finance.capitalisations.withdraw', 'Withdraw this capitalisation back to draft?')} className={BTN_SECONDARY}>Withdraw</button>}
                {can.approve && <button type="button" onClick={() => setReason('approve')} className={BTN_PRIMARY}>Approve</button>}
                {can.reject && <button type="button" onClick={() => setReason('reject')} className="rounded-lg border border-[#B3261E] px-4 py-2 text-sm text-[#B3261E]">Reject</button>}
                {can.cancel && <button type="button" onClick={() => setReason('cancel')} className="rounded-lg border border-gray-200 px-4 py-2 text-sm text-[#B3261E]">Cancel</button>}
            </div>
            {['capitalisation', 'approval', 'submit', 'asset'].map((k) => errors?.[k] && <p key={k} role="alert" className="mb-2 text-sm text-[#B3261E]">{errors[k]}</p>)}
            {c.rejection_reason && <p className="mb-4 rounded-lg bg-[#F9DEDC] px-3 py-2 text-sm text-[#8C1D18]">Rejected: {c.rejection_reason}</p>}
            {c.cancel_reason && <p className="mb-4 rounded-lg bg-[#F1F2F4] px-3 py-2 text-sm text-[#43474E]">Cancelled: {c.cancel_reason}</p>}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <div className="space-y-4 xl:col-span-2">
                    <Card title="Details">
                        <dl className="grid grid-cols-2 gap-4 px-5 py-4 lg:grid-cols-3">
                            <Fact label="Source">{c.source?.href ? <Link className="text-[#0A1F44] underline" href={c.source.href}>{c.source.label}</Link> : c.source?.label}</Fact>
                            <Fact label="Cost centre">{c.cost_centre && `${c.cost_centre.code} ${c.cost_centre.name}`}</Fact>
                            <Fact label="Available for use">{localDate(c.available_for_use_on)}</Fact>
                            <Fact label="Useful life">{fmtMonths(c.useful_life_months)}</Fact>
                            <Fact label="Method">{humanize(c.method)}{c.method === 'declining' && c.declining_rate_pct ? ` at ${c.declining_rate_pct}%` : ''}</Fact>
                            <Fact label="Residual value">{fmt(c.residual_value)}</Fact>
                            <Fact label="Eligible at submission">{fmt(c.eligible_amount)}</Fact>
                            <Fact label="Excluded">{fmt(c.excluded_amount)}{c.excluded_reason ? ` (${c.excluded_reason})` : ''}</Fact>
                            <Fact label="Booked on">{c.capitalised_on ? `${localDate(c.capitalised_on)}${c.booking_period ? `, ${c.booking_period.name}` : ''}` : null}</Fact>
                            <Fact label="Requested by">{c.requested_by}{c.requested_at ? ` · ${localDate(c.requested_at)}` : ''}</Fact>
                            <Fact label="Approved by">{c.approved_by}{c.approved_at ? ` · ${localDate(c.approved_at)}` : ''}</Fact>
                        </dl>
                    </Card>
                    <ChecklistPanel items={c.criteria_checklist} documents={documents} editable={editable} basePayload={basePayload} updateUrl={route('finance.capitalisations.update', c.id)} errors={errors} />
                    <EligibilityCard eligibility={eligibility} baseCurrency={baseCurrency} money={money} />
                    <ScheduleTable schedule={schedule} byYear={scheduleByYear} baseCurrency={baseCurrency} money={money} status={c.status} />
                </div>
                <div className="space-y-4">
                    <ApprovalTrail process="finance.capitalisation_approval" id={c.id} version={c.status} />
                    <Card title="Asset">
                        <div className="space-y-1 px-5 py-4 text-sm">
                            {c.asset ? <p><Link className="text-[#0A1F44] underline" href={c.asset.href}>{c.asset.code} {c.asset.name}</Link></p>
                                : c.create_asset ? <p className="text-[#4A5568]">An asset will be created on approval: <b>{c.asset_name}</b>{c.asset_type ? ` (${humanize(c.asset_type)})` : ''}{c.asset_owner ? `, owner ${c.asset_owner.name}` : ''}.</p>
                                    : <p className="text-[#718096]">No asset linked.</p>}
                        </div>
                    </Card>
                    <DocumentsPanel subject="capitalisation" subjectId={c.id} documents={documents.map((d) => ({ ...d, download_url: d.download_url ?? d.url, created_at: d.created_at ?? d.uploaded_at }))} kinds={DOC_KINDS} canAttach={can.edit} defaultKind="capitalisation_memo" />
                    <Card title="Activity">
                        {activity.length === 0 ? <Empty>No activity yet.</Empty> : (
                            <ul className="divide-y divide-gray-50">{activity.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2 text-sm"><span className="text-[#2D3748]">{a.description}</span><span className="text-xs text-[#718096]">{(typeof a.causer === 'string' ? a.causer : a.causer?.name) ?? 'System'} · {localDate(a.at)}</span></li>
                            ))}</ul>
                        )}
                    </Card>
                </div>
            </div>

            <CapitalisationFormModal show={editing} onClose={() => setEditing(false)} capitalisation={c} options={options} defaults={null} assets={assets} users={users} baseCurrency={baseCurrency} money={money} remaining={eligibility?.remaining} />
            <ReasonModal show={reason === 'approve'} onClose={() => setReason(null)} title="Approve capitalisation" routeName="finance.capitalisations.approve" params={c.id} field="note" label="Note" required={false} submitLabel="Approve" errors={errors} />
            <ReasonModal show={reason === 'reject'} onClose={() => setReason(null)} title="Reject capitalisation" routeName="finance.capitalisations.reject" params={c.id} field="note" label="Reason" submitLabel="Reject" errors={errors} />
            <ReasonModal show={reason === 'cancel'} onClose={() => setReason(null)} title="Cancel capitalisation" routeName="finance.capitalisations.cancel" params={c.id} field="reason" label="Reason" submitLabel="Cancel capitalisation" errors={errors} />
        </AuthenticatedLayout>
    );
}

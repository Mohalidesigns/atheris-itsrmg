import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';
import { ScoreDisplay, TreatmentStatusBadge } from '@/Components/Risk/RiskBadge';
import { formatDate, humanize } from '@/Utils/risk';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value ?? '--'}</dd>
        </div>
    );
}

export default function ShowRiskTreatment({ treatment, statuses = [] }) {
    const destroy = () => {
        if (confirm('Delete this treatment plan? This cannot be undone.')) {
            router.delete(route('risk-treatments.destroy', treatment.id));
        }
    };

    const updateStatus = (status) => {
        if (status === 'completed' && !confirm('Mark this plan completed? ' + (treatment.target_score
            ? `The risk's residual score will be set to the target (${treatment.target_score}).`
            : 'No target score is set, so the residual score will not change.'))) {
            return;
        }
        router.patch(route('risk-treatments.update-status', treatment.id), { status }, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={`Treatment — ${treatment.title}`}>
            <Head title={`Treatment — ${treatment.title}`} />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Risk</p>
                    <p className="mt-1 text-sm font-medium">
                        {treatment.risk ? (
                            <Link href={route('risks.show', treatment.risk.id)} className="font-mono-data text-[#1A365D] hover:underline">
                                {treatment.risk.risk_id_code}
                            </Link>
                        ) : '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Strategy</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{humanize(treatment.strategy)}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><TreatmentStatusBadge status={treatment.status} overdue={treatment.is_overdue} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Progress</p>
                    <div className="mt-2 flex items-center gap-2">
                        <div className="w-full bg-gray-200 rounded-full h-1.5">
                            <div className="bg-[#2D7D46] rounded-full h-1.5" style={{ width: `${treatment.completion_percentage || 0}%` }} />
                        </div>
                        <span className="text-xs text-[#718096]">{treatment.completion_percentage || 0}%</span>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 mb-4">
                <Link href={route('risk-treatments.edit', treatment.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    <PencilIcon className="w-4 h-4" /> Edit
                </Link>
                <label htmlFor="quick-status" className="text-xs text-[#718096] ml-2">Status</label>
                <select id="quick-status" value={treatment.status || ''} onChange={e => updateStatus(e.target.value)}
                    className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                    {statuses.map(s => <option key={s} value={s}>{humanize(s)}</option>)}
                </select>
                <button onClick={destroy}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                    <TrashIcon className="w-4 h-4" /> Delete
                </button>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Treatment Plan</h3>
                </div>
                <div className="px-6 py-2">
                    <DetailRow label="Risk" value={treatment.risk ? `${treatment.risk.risk_id_code} — ${treatment.risk.title}` : null} />
                    <DetailRow label="Description" value={treatment.description} />
                    <DetailRow label="Assigned To" value={treatment.assignee?.name} />
                    <DetailRow label="Priority" value={treatment.priority != null ? `P${treatment.priority}` : null} />
                    <DetailRow label="Due Date" value={<span className={treatment.is_overdue ? 'text-[#C53030] font-medium' : ''}>{formatDate(treatment.due_date)}{treatment.is_overdue ? ' — overdue' : ''}</span>} />
                    <DetailRow label="Estimated Cost" value={treatment.estimated_cost != null ? `${Number(treatment.estimated_cost).toLocaleString()} ${treatment.cost_currency || ''}` : null} />
                    <DetailRow label="Residual: current → target" value={<span className="inline-flex items-center gap-2"><ScoreDisplay score={treatment.risk?.residual_score} /> → {treatment.target_score != null ? <><ScoreDisplay score={treatment.target_score} /> <span className="text-xs text-[#718096]">(L{treatment.target_likelihood} × I{treatment.target_impact})</span></> : <span className="text-xs text-[#718096]">no target set</span>}</span>} />
                    <DetailRow label="Approved" value={treatment.approved_at ? `${formatDate(treatment.approved_at, true)} by ${treatment.approver?.name || '—'}` : null} />
                    <DetailRow label="Notes" value={treatment.notes} />
                    <DetailRow label="Completed At" value={treatment.completed_at ? formatDate(treatment.completed_at) : null} />
                    <DetailRow label="Created" value={formatDate(treatment.created_at, true)} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

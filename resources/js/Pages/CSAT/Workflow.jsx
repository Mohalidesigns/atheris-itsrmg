import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircleIcon, XCircleIcon, DocumentArrowDownIcon } from '@heroicons/react/24/outline';
import { STATUS_COLORS, STATUS_LABELS, formatDate } from '@/Utils/csat';

const ACTION_LABELS = { approved: 'Signed', returned_for_revision: 'Returned for revision', rejected: 'Rejected' };

function StageTracker({ stages, records, status, nextStage }) {
    // Latest submission cycle only.
    const lastSubmit = [...records].reverse().find(r => r.stage_number === 1 && r.action === 'approved');
    const cycle = lastSubmit ? records.filter(r => r.id >= lastSubmit.id) : [];
    return (
        <ol className="grid grid-cols-1 md:grid-cols-4 gap-3">
            {stages.map(st => {
                const signed = cycle.find(r => r.stage_number === st.number && r.action === 'approved');
                const returned = cycle.find(r => r.stage_number === st.number && r.action !== 'approved');
                const current = nextStage?.number === st.number;
                return (
                    <li key={st.number} className={`rounded-xl border p-4 ${signed ? 'border-green-200 bg-green-50' : current ? 'border-amber-300 bg-amber-50' : returned ? 'border-red-200 bg-red-50' : 'border-gray-100 bg-white'}`}>
                        <p className="text-[11px] uppercase text-[#718096] font-medium">Stage {st.number}</p>
                        <p className="text-sm font-semibold text-[#2D3748]">{st.name}</p>
                        <p className="text-xs mt-1 text-[#718096]">
                            {signed ? `${signed.approver_name} · ${formatDate(signed.actioned_at, true)}`
                                : current ? 'Awaiting signature'
                                    : returned ? `Returned by ${returned.approver_name}`
                                        : status === 'approved' || status === 'submitted' ? '—' : 'Pending'}
                        </p>
                    </li>
                );
            })}
        </ol>
    );
}

export default function Workflow({ assessment, approvalRecords = [], stages = [], nextStage, checklist = [], can = {}, editable }) {
    const [comments, setComments] = useState('');
    const [rejectComments, setRejectComments] = useState('');
    const [showReject, setShowReject] = useState(false);
    const [busy, setBusy] = useState(false);
    const deadline = useForm({ submission_deadline: assessment.submission_deadline ? String(assessment.submission_deadline).slice(0, 10) : '' });
    const ready = checklist.every(i => i.done);

    const post = (name, data = {}, after) => router.post(route(name, assessment.id), data, {
        preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: after,
    });

    const daysLeft = assessment.submission_deadline
        ? Math.ceil((new Date(assessment.submission_deadline) - new Date()) / 86400000) : null;

    return (
        <AuthenticatedLayout header="Approval Workflow">
            <Head title="Workflow" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                {can.export && (
                    <Link href={route('csat.submission-package', assessment.id)} className="inline-flex items-center gap-1 px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">
                        <DocumentArrowDownIcon className="w-4 h-4" /> CBN Submission Package
                    </Link>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm text-center">
                    <p className="text-sm text-[#718096] mb-2">Assessment status · {assessment.assessment_year}</p>
                    <span className={`inline-block px-6 py-2 rounded-lg text-lg font-bold ${STATUS_COLORS[assessment.status]}`}>{STATUS_LABELS[assessment.status] || assessment.status}</span>
                    {assessment.status === 'submitted' ? (
                        <p className="text-xs text-[#718096] mt-3">Submitted to the CBN on {formatDate(assessment.submitted_at, true)}</p>
                    ) : (
                        <div className="mt-4 text-left">
                            <label className="block text-xs text-[#718096] mb-1">CBN submission deadline</label>
                            <div className="flex gap-2">
                                <input type="date" value={deadline.data.submission_deadline} disabled={!editable}
                                    onChange={e => deadline.setData('submission_deadline', e.target.value)}
                                    className="flex-1 px-2 py-1 border border-gray-200 rounded text-sm disabled:bg-gray-50" />
                                {editable && (
                                    <button onClick={() => deadline.put(route('csat.deadline.update', assessment.id), { preserveScroll: true })}
                                        disabled={deadline.processing || !deadline.isDirty} className="px-3 py-1 text-xs rounded bg-[#1A365D] text-white disabled:opacity-40">Save</button>
                                )}
                            </div>
                            {deadline.errors.submission_deadline && <p className="text-xs text-[#C53030] mt-1">{deadline.errors.submission_deadline}</p>}
                            {daysLeft !== null && (
                                <p className={`text-xs mt-1 ${daysLeft < 0 ? 'text-[#C53030] font-semibold' : daysLeft <= 14 ? 'text-[#DD6B20]' : 'text-[#718096]'}`}>
                                    {daysLeft < 0 ? `Overdue by ${-daysLeft} day(s) — escalate to MD/CEO` : `${daysLeft} day(s) to the deadline`}
                                </p>
                            )}
                        </div>
                    )}
                </div>

                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <h3 className="text-base font-semibold text-[#2D3748]">Items to Submit (Sheet 6)</h3>
                        <span className={`text-xs font-semibold ${ready ? 'text-[#2D7D46]' : 'text-[#DD6B20]'}`}>{checklist.filter(i => i.done).length} / {checklist.length} complete</span>
                    </div>
                    <ul className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1.5">
                        {checklist.map(i => (
                            <li key={i.key} className="flex items-start gap-2 text-xs">
                                {i.done ? <CheckCircleIcon className="w-4 h-4 text-[#2D7D46] shrink-0" /> : <XCircleIcon className="w-4 h-4 text-[#C53030] shrink-0" />}
                                <span>
                                    <Link href={route(i.route, assessment.id)} className="text-[#2D3748] hover:underline">{i.label}</Link>
                                    <span className="block text-[#718096]">{i.detail}</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                <h3 className="text-base font-semibold text-[#2D3748] mb-4">Approval Routing (BR-AW-01)</h3>
                <StageTracker stages={stages} records={approvalRecords} status={assessment.status} nextStage={nextStage} />

                <div className="mt-5 border-t border-gray-100 pt-5">
                    {can.submit && (
                        <div>
                            <p className="text-sm text-[#718096] mb-3">
                                {ready ? 'All checklist items are complete. Submitting signs Stage 1 and freezes answers until the cycle is approved or returned.'
                                    : 'Submission is blocked until every checklist item above is complete.'}
                            </p>
                            <textarea value={comments} onChange={e => setComments(e.target.value)} rows={2} placeholder="Preparer note (optional)"
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm mb-2" />
                            <button onClick={() => post('csat.workflow.submit', { comments }, () => setComments(''))} disabled={!ready || busy}
                                className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] disabled:opacity-40">
                                Submit for Approval
                            </button>
                        </div>
                    )}

                    {assessment.status === 'pending_approval' && nextStage && (
                        <div className="space-y-3">
                            <p className="text-sm text-[#2D3748]">Awaiting <strong>Stage {nextStage.number}: {nextStage.name}</strong>.</p>
                            {can.signedEarlierStage && (
                                <p className="text-sm text-[#DD6B20]">You signed an earlier stage of this submission — segregation of duties requires a different officer to sign this one.</p>
                            )}
                            {can.approve && (
                                <>
                                    <textarea value={comments} onChange={e => setComments(e.target.value)} rows={2}
                                        className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" placeholder="Approval comments (optional)" />
                                    <div className="flex gap-2">
                                        <button onClick={() => post('csat.workflow.approve', { comments }, () => setComments(''))} disabled={busy}
                                            className="px-4 py-2 text-sm font-semibold text-white bg-[#2D7D46] rounded-lg hover:bg-green-700 disabled:opacity-50">
                                            Approve &amp; sign Stage {nextStage.number}
                                        </button>
                                        <button onClick={() => setShowReject(true)}
                                            className="px-4 py-2 text-sm font-semibold text-white bg-[#C53030] rounded-lg hover:bg-red-700">
                                            Return for Revision
                                        </button>
                                    </div>
                                </>
                            )}
                            {!can.approve && !can.signedEarlierStage && (
                                <p className="text-sm text-[#718096]">You do not hold the permission required to sign this stage.</p>
                            )}
                            {showReject && (
                                <div className="mt-2 p-4 bg-red-50 rounded-lg">
                                    <label className="block text-sm font-medium text-[#C53030] mb-1">Reason for returning (required)</label>
                                    <textarea value={rejectComments} onChange={e => setRejectComments(e.target.value)} rows={3}
                                        className="w-full px-3 py-2 border border-red-200 rounded-lg text-sm" placeholder="Explain what needs to be revised..." />
                                    <div className="flex gap-2 mt-2">
                                        <button onClick={() => post('csat.workflow.reject', { comments: rejectComments }, () => { setRejectComments(''); setShowReject(false); })}
                                            disabled={!rejectComments.trim() || busy}
                                            className="px-4 py-2 text-sm font-semibold text-white bg-[#C53030] rounded-lg disabled:opacity-50">Confirm Return</button>
                                        <button onClick={() => setShowReject(false)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {assessment.status === 'approved' && (
                        <div>
                            <p className="text-sm text-[#2D7D46] font-medium mb-3">All stages signed. Record the submission once the package has been lodged with the CBN.</p>
                            {can.submitToCbn && (
                                <button onClick={() => confirm('Record this assessment as submitted to the CBN? This is final.') && post('csat.workflow.submit-cbn')} disabled={busy}
                                    className="px-4 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg disabled:opacity-50">
                                    Mark as Submitted to CBN
                                </button>
                            )}
                        </div>
                    )}

                    {assessment.status === 'submitted' && <p className="text-sm text-[#718096]">This cycle is closed.</p>}
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <h3 className="text-base font-semibold text-[#2D3748] mb-4">Approval Trail</h3>
                {approvalRecords.length === 0 ? (
                    <p className="text-sm text-[#718096]">No approval actions recorded yet.</p>
                ) : (
                    <ol className="space-y-3">
                        {approvalRecords.map(r => {
                            const stage = stages.find(s => s.number === r.stage_number);
                            return (
                                <li key={r.id} className="flex items-start gap-3">
                                    <div className={`w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0 ${r.action === 'approved' ? 'bg-[#2D7D46]' : 'bg-[#C53030]'}`}>{r.stage_number}</div>
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-[#2D3748]">
                                            {stage?.name || `Stage ${r.stage_number}`} — {r.approver_name} ({r.approver_role}) ·{' '}
                                            <span className={r.action === 'approved' ? 'text-[#2D7D46]' : 'text-[#C53030]'}>{ACTION_LABELS[r.action] || r.action}</span>
                                        </p>
                                        {r.comments && <p className="text-xs text-[#2D3748] mt-0.5">“{r.comments}”</p>}
                                        <p className="text-xs text-[#718096]">{formatDate(r.actioned_at, true)}</p>
                                        {r.digital_signature_token && <p className="text-[10px] font-mono text-[#A0AEC0] truncate" title={r.digital_signature_token}>Signature {r.digital_signature_token.slice(0, 24)}…</p>}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

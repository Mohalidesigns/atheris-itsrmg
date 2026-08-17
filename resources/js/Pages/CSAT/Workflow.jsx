import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const statusConfig = {
    draft: { color: '#718096', label: 'Draft' },
    in_progress: { color: '#D4AF37', label: 'In Progress' },
    pending_approval: { color: '#DD6B20', label: 'Pending Approval' },
    approved: { color: '#2D7D46', label: 'Approved' },
    submitted_to_cbn: { color: '#1A365D', label: 'Submitted to CBN' },
};

export default function Workflow({ assessment, approvalRecords }) {
    const [comments, setComments] = useState('');
    const [rejectComments, setRejectComments] = useState('');
    const [showReject, setShowReject] = useState(false);

    const status = statusConfig[assessment.status] || statusConfig.draft;

    const submitForApproval = () => {
        router.post(route('csat.workflow.submit', assessment.id), {}, { preserveScroll: true });
    };

    const approve = () => {
        router.post(route('csat.workflow.approve', assessment.id), { comments }, { preserveScroll: true, onSuccess: () => setComments('') });
    };

    const reject = () => {
        router.post(route('csat.workflow.reject', assessment.id), { comments: rejectComments }, {
            preserveScroll: true, onSuccess: () => { setRejectComments(''); setShowReject(false); },
        });
    };

    return (
        <AuthenticatedLayout header="Approval Workflow">
            <Head title="Workflow" />

            <div className="mb-4">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
            </div>

            {/* Current Status */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6 text-center">
                <p className="text-sm text-[#718096] mb-2">Assessment Status</p>
                <span className="inline-block px-6 py-2 rounded-lg text-lg font-bold text-white"
                    style={{ backgroundColor: status.color }}>
                    {status.label}
                </span>
                <p className="text-xs text-[#718096] mt-2">Assessment Year: {assessment.assessment_year}</p>
            </div>

            {/* Actions */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                <h3 className="text-base font-semibold text-[#2D3748] mb-4">Actions</h3>

                {assessment.status === 'in_progress' && (
                    <div>
                        <p className="text-sm text-[#718096] mb-3">Submit this assessment for approval by the CISO and Board.</p>
                        <button onClick={submitForApproval}
                            className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                            Submit for Approval
                        </button>
                    </div>
                )}

                {assessment.status === 'pending_approval' && (
                    <div className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Approval Comments (optional)</label>
                            <textarea value={comments} onChange={e => setComments(e.target.value)} rows={2}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" placeholder="Add comments..." />
                        </div>
                        <div className="flex gap-2">
                            <button onClick={approve}
                                className="px-4 py-2 text-sm font-semibold text-white bg-[#2D7D46] rounded-lg hover:bg-green-700">
                                Approve
                            </button>
                            <button onClick={() => setShowReject(true)}
                                className="px-4 py-2 text-sm font-semibold text-white bg-[#C53030] rounded-lg hover:bg-red-700">
                                Return for Revision
                            </button>
                        </div>

                        {showReject && (
                            <div className="mt-4 p-4 bg-red-50 rounded-lg">
                                <label className="block text-sm font-medium text-[#C53030] mb-1">Reason for returning (required)</label>
                                <textarea value={rejectComments} onChange={e => setRejectComments(e.target.value)} rows={3}
                                    className="w-full px-3 py-2 border border-red-200 rounded-lg text-sm" placeholder="Explain what needs to be revised..." />
                                <div className="flex gap-2 mt-2">
                                    <button onClick={reject} disabled={!rejectComments.trim()}
                                        className="px-4 py-2 text-sm font-semibold text-white bg-[#C53030] rounded-lg hover:bg-red-700 disabled:opacity-50">
                                        Confirm Return
                                    </button>
                                    <button onClick={() => setShowReject(false)}
                                        className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {assessment.status === 'approved' && (
                    <p className="text-sm text-[#2D7D46] font-medium">This assessment has been approved and is ready for CBN submission.</p>
                )}

                {assessment.status === 'draft' && (
                    <p className="text-sm text-[#718096]">Complete the inherent risk and maturity assessments before submitting for approval.</p>
                )}
            </div>

            {/* Approval Trail */}
            <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <h3 className="text-base font-semibold text-[#2D3748] mb-4">Approval Trail</h3>
                {(approvalRecords || []).length === 0 ? (
                    <p className="text-sm text-[#718096]">No approval actions recorded yet.</p>
                ) : (
                    <div className="space-y-3">
                        {(approvalRecords || []).map((r, i) => (
                            <div key={r.id} className="flex items-start gap-3">
                                <div className={`w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0 ${
                                    r.action === 'approved' ? 'bg-[#2D7D46]' : r.action === 'rejected' ? 'bg-[#C53030]' : 'bg-[#718096]'
                                }`}>
                                    {i + 1}
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-[#2D3748]">
                                        {r.approver_name} ({r.approver_role}) — <span className={r.action === 'approved' ? 'text-[#2D7D46]' : 'text-[#C53030]'}>
                                            {r.action === 'approved' ? 'Approved' : 'Returned for Revision'}
                                        </span>
                                    </p>
                                    {r.comments && <p className="text-xs text-[#718096] mt-1">{r.comments}</p>}
                                    <p className="text-xs text-[#718096]">{new Date(r.actioned_at).toLocaleString()}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

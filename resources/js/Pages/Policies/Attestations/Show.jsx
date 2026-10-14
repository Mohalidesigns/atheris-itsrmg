import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PolicyStatus, fmtDate } from '@/Components/Policies/PolicyBadges';
import { Head, Link, useForm } from '@inertiajs/react';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

export default function ShowPolicyAttestation({ attestation, overdue = false, canWaive = false }) {
    const waive = useForm({ reason: '' });
    return (
        <AuthenticatedLayout header="Attestation Record">
            <Head title="Attestation Record" />

            <div className="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><PolicyStatus status={overdue ? 'overdue' : attestation.status} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Due Date</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {fmtDate(attestation.due_date) || '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Acknowledged</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">
                        {attestation.acknowledged_at ? new Date(attestation.acknowledged_at).toLocaleString('en-GB') : 'Not yet'}
                    </p>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="px-6 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Record Detail</h3>
                </div>
                <div className="px-6 py-2">
                    <DetailRow label="Policy" value={attestation.policy ? (
                        <Link href={route('policies.show', attestation.policy.id)} className="text-[#1A365D] hover:underline">
                            {attestation.policy.policy_code ? `${attestation.policy.policy_code} — ` : ''}{attestation.policy.title}
                        </Link>
                    ) : null} />
                    <DetailRow label="Version attested" value={attestation.version_number ? `v${attestation.version_number}${attestation.policy?.version_number !== attestation.version_number ? ` (policy is now v${attestation.policy?.version_number})` : ''}` : null} />
                    <DetailRow label="User" value={attestation.user?.name} />
                    <DetailRow label="Email" value={attestation.user?.email} />
                    <DetailRow label="Department" value={attestation.user?.department} />
                    <DetailRow label="Job Title" value={attestation.user?.job_title} />
                    <DetailRow label="Notes" value={attestation.notes} />
                    <DetailRow label="Reminder Sent" value={attestation.reminder_sent_at ? new Date(attestation.reminder_sent_at).toLocaleString('en-GB') : null} />
                    <DetailRow label="Created" value={new Date(attestation.created_at).toLocaleString('en-GB')} />
                </div>
            </div>

            {canWaive && attestation.status === 'pending' && (
                <form onSubmit={(e) => { e.preventDefault(); waive.post(route('policy-attestations.waive', attestation.id), { preserveScroll: true }); }}
                    className="mt-6 flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <span className="text-sm text-[#2D3748]">Waive this attestation</span>
                    <input value={waive.data.reason} onChange={(e) => waive.setData('reason', e.target.value)} placeholder="Reason, e.g. on long-term leave" className="min-w-0 flex-1 rounded-lg border-gray-300 text-sm" />
                    <button type="submit" disabled={waive.processing} className="rounded-lg border border-gray-300 px-3 py-2 text-sm">Waive</button>
                    {waive.errors.reason && <p className="w-full text-xs text-[#C53030]">{waive.errors.reason}</p>}
                </form>
            )}
        </AuthenticatedLayout>
    );
}

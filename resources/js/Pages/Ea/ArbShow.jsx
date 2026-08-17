import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm } from '@inertiajs/react';

export default function ArbShow({ submission, principleImpact }) {
    const form = useForm({ decision: 'approved', decision_rationale: '', votes: {}, minutes: '' });
    const decide = (e) => { e.preventDefault(); form.post(route('ea.arb.decide', submission.id)); };

    return (
        <AuthenticatedLayout header={`ARB — ${submission.code}`}>
            <Head title={`ARB — ${submission.code}`} />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Governance' }, { label: 'ARB' }, { label: submission.code }]}
                title={submission.title}
                subtitle={`Status: ${submission.status} · Risk: ${submission.risk_band || 'medium'} · Decided: ${submission.decided_at || 'not yet'}`}
            />

            <div className="grid grid-cols-1 lg:grid-cols-[1fr,360px] gap-4">
                <div className="space-y-4">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <h3 className="font-bold text-[#0A1F44] mb-2">Summary</h3>
                        <p className="text-sm whitespace-pre-wrap">{submission.summary}</p>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <h3 className="font-bold text-[#0A1F44] mb-2">Impacted principles (auto)</h3>
                        <ul className="text-sm space-y-1">
                            {(principleImpact?.impacted_principles || []).map((row, i) => (
                                <li key={i} className="flex items-center gap-2 border-b border-gray-50 pb-1">
                                    <span className="font-mono text-xs">{row.principle.code}</span>
                                    <span>{row.principle.name}</span>
                                    <span className="ml-auto text-xs text-gray-500">score {row.score}</span>
                                </li>
                            ))}
                            {(!principleImpact?.impacted_principles || principleImpact.impacted_principles.length === 0) && <li className="text-sm text-gray-400">No principle keywords matched.</li>}
                        </ul>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <h3 className="font-bold text-[#0A1F44] mb-2">Impacted standards (auto)</h3>
                        <ul className="text-sm space-y-1">
                            {(principleImpact?.impacted_standards || []).map((row, i) => (
                                <li key={i} className="flex items-center gap-2 border-b border-gray-50 pb-1">
                                    <span className="font-mono text-xs">{row.standard.code}</span>
                                    <span>{row.standard.name}</span>
                                    <span className="ml-auto text-xs text-gray-500">score {row.score}</span>
                                </li>
                            ))}
                            {(!principleImpact?.impacted_standards || principleImpact.impacted_standards.length === 0) && <li className="text-sm text-gray-400">No standard keywords matched.</li>}
                        </ul>
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="font-bold text-[#0A1F44] mb-3">Status</h3>
                    <StatusBadge status={submission.status === 'approved' ? 'compliant' : submission.status === 'rejected' ? 'critical' : 'in_progress'} label={submission.status} />
                    <p className="text-xs text-gray-500 mt-2">Risk band: {submission.risk_band || 'medium'}</p>
                    <p className="text-xs text-gray-500 mt-1">Signature: {submission.digital_signature ? submission.digital_signature.slice(0, 16) + '…' : '—'}</p>
                    {submission.minutes && <div className="mt-3"><div className="text-xs font-bold">Minutes</div><div className="text-xs">{submission.minutes}</div></div>}
                    {submission.decision_rationale && <div className="mt-3"><div className="text-xs font-bold">Rationale</div><div className="text-xs">{submission.decision_rationale}</div></div>}

                    {submission.status !== 'approved' && submission.status !== 'rejected' && (
                        <form onSubmit={decide} className="mt-4 space-y-2">
                            <h4 className="font-bold text-sm">Record decision</h4>
                            <select value={form.data.decision} onChange={(e) => form.setData('decision', e.target.value)} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                <option value="approved">approved</option><option value="rejected">rejected</option><option value="deferred">deferred</option>
                            </select>
                            <textarea className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Rationale" value={form.data.decision_rationale} onChange={(e) => form.setData('decision_rationale', e.target.value)} />
                            <textarea className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Minutes" value={form.data.minutes} onChange={(e) => form.setData('minutes', e.target.value)} />
                            <button disabled={form.processing} className="w-full px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Record decision</button>
                        </form>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

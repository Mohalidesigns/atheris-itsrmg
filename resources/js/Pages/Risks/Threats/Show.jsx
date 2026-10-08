import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { formatDate, threatLabel } from '@/Utils/risk';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';

function DetailRow({ label, value }) {
    return (
        <div className="py-3 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

export default function ShowThreat({ threat, risks = [] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        risk_id: '',
        likelihood: '',
        impact: '',
        analysis: '',
        recommendations: '',
    });

    const submitAssessment = (e) => {
        e.preventDefault();
        post(route('threats.assessments.store', threat.id), { onSuccess: () => reset() });
    };

    const destroy = () => {
        if (confirm(`Delete threat ${threat.threat_id_code}? This cannot be undone.`)) {
            router.delete(route('threats.destroy', threat.id));
        }
    };

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">{threat.threat_id_code}</span>
                <span>{threat.name}</span>
            </div>
        }>
            <Head title={`${threat.threat_id_code} - ${threat.name}`} />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Severity</p>
                    <div className="mt-1">{threat.severity ? <StatusBadge status={threat.severity} label={threatLabel(threat.severity)} /> : <span className="text-sm text-gray-400">--</span>}</div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Likelihood</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">{threat.likelihood ?? '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><StatusBadge status={threat.is_active ? 'active' : 'disabled'} label={threat.is_active ? 'Active' : 'Inactive'} /></div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Assessments</p>
                    <p className="mt-1 text-lg font-bold font-mono-data text-[#2D3748]">{(threat.assessments || []).length}</p>
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                <Link href={route('threats.edit', threat.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    <PencilIcon className="w-4 h-4" /> Edit
                </Link>
                <button onClick={destroy}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                    <TrashIcon className="w-4 h-4" /> Delete
                </button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div className="px-6 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Threat Profile</h3>
                    </div>
                    <div className="px-6 py-2">
                        <DetailRow label="Description" value={threat.description} />
                        <DetailRow label="Category" value={threat.category && threatLabel(threat.category)} />
                        <DetailRow label="Source" value={threat.source && threatLabel(threat.source)} />
                        <DetailRow label="Type" value={threat.type && threatLabel(threat.type)} />
                        <DetailRow label="Capability" value={threat.capability} />
                        <DetailRow label="Intent" value={threat.intent} />
                        <DetailRow label="Countermeasures" value={threat.countermeasures} />
                        <DetailRow label="Last Seen" value={threat.last_seen ? formatDate(threat.last_seen) : null} />
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                        <div className="px-6 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Threat Assessments &amp; Linked Risks</h3>
                        </div>
                        {(threat.assessments || []).length === 0 ? (
                            <p className="px-6 py-8 text-sm text-[#718096] text-center">No assessments recorded for this threat yet.</p>
                        ) : (
                            <ul className="divide-y divide-gray-50">
                                {threat.assessments.map(a => (
                                    <li key={a.id} className="px-6 py-3">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                {a.risk ? (
                                                    <Link href={route('risks.show', a.risk.id)} className="font-mono-data text-xs text-[#1A365D] font-medium hover:underline">
                                                        {a.risk.risk_id_code} — {a.risk.title}
                                                    </Link>
                                                ) : (
                                                    <span className="text-xs text-[#718096]">Standalone assessment</span>
                                                )}
                                                {a.analysis && <p className="text-sm text-[#2D3748] mt-0.5">{a.analysis}</p>}
                                            </div>
                                            <div className="text-right shrink-0 ml-3">
                                                {a.score != null && (
                                                    <span className="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-[#0A1F44] text-white font-bold font-mono-data text-sm">{a.score}</span>
                                                )}
                                            </div>
                                        </div>
                                        <p className="text-xs text-[#718096] mt-1">
                                            {formatDate(a.assessment_date)} · by {a.assessor?.name || '--'}
                                            {a.likelihood != null && ` · L${a.likelihood}`}{a.impact != null && ` × I${a.impact}`}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                        <h3 className="text-sm font-semibold text-[#2D3748] mb-4">Assess Threat Against a Risk</h3>
                        <form onSubmit={submitAssessment} className="space-y-4">
                            <div>
                                <InputLabel value="Related Risk" />
                                <select value={data.risk_id} onChange={e => setData('risk_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">None (standalone)</option>
                                    {risks.map(r => <option key={r.id} value={r.id}>{r.risk_id_code} — {r.title}</option>)}
                                </select>
                                <InputError message={errors.risk_id} className="mt-1" />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel value="Likelihood (1-5)" />
                                    <select value={data.likelihood} onChange={e => setData('likelihood', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">--</option>
                                        {[1, 2, 3, 4, 5].map(v => <option key={v} value={v}>{v}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Impact (1-5)" />
                                    <select value={data.impact} onChange={e => setData('impact', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">--</option>
                                        {[1, 2, 3, 4, 5].map(v => <option key={v} value={v}>{v}</option>)}
                                    </select>
                                </div>
                            </div>
                            <div>
                                <InputLabel value="Analysis" />
                                <textarea value={data.analysis} onChange={e => setData('analysis', e.target.value)} rows={2}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            </div>
                            <div>
                                <InputLabel value="Recommendations" />
                                <textarea value={data.recommendations} onChange={e => setData('recommendations', e.target.value)} rows={2}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            </div>
                            {(errors.likelihood || errors.impact) && <p className="text-xs text-red-600">{errors.likelihood || errors.impact}</p>}
                            <div className="flex justify-end">
                                <PrimaryButton disabled={processing}>Record Assessment</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

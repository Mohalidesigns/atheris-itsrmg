import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { PencilIcon, ArrowUpTrayIcon, ArrowDownTrayIcon, XMarkIcon, TrashIcon } from '@heroicons/react/24/outline';
import { useEffect, useState } from 'react';
import axios from 'axios';
import Chip from '@/Components/Compliance/Chip';
import InputError from '@/Components/InputError';
import EvidenceUploadModal from '@/Components/Compliance/EvidenceUploadModal';
import EvidenceReviewModal from '@/Components/Compliance/EvidenceReviewModal';
import { RatingBadge, StatusBadge } from '@/Components/Risk/RiskBadge';
import { COVERAGE, CONTROL_STATUS, EVIDENCE_TYPES, RESULT_STYLES, ASSESSMENT_STATUS, effectivenessOf, evidenceState, formatDate, humanize, isOverdue } from '@/Utils/compliance';

function MappingForm({ control, frameworks, coverageOptions }) {
    const [framework, setFramework] = useState('');
    const [requirements, setRequirements] = useState([]);
    const { data, setData, post, processing, errors, reset } = useForm({ requirement_id: '', coverage: 'full', notes: '' });
    const mapped = new Set((control.framework_requirements || []).map((r) => r.id));

    useEffect(() => {
        setData('requirement_id', '');
        if (!framework) return setRequirements([]);
        axios.get(route('frameworks.requirements', framework)).then((r) => setRequirements(r.data));
    }, [framework]);

    const submit = (e) => {
        e.preventDefault();
        post(route('controls.mappings.store', control.id), { preserveScroll: true, onSuccess: () => reset('requirement_id', 'notes') });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-2 p-3 mb-4 rounded-lg bg-gray-50 border border-gray-100">
            <div>
                <label className="text-[11px] text-[#718096] uppercase">Framework</label>
                <select value={framework} onChange={(e) => setFramework(e.target.value)} className="block mt-0.5 text-sm border-gray-200 rounded-lg">
                    <option value="">Select…</option>
                    {frameworks.map((f) => <option key={f.id} value={f.id}>{f.short_name}</option>)}
                </select>
            </div>
            <div className="flex-1 min-w-[260px]">
                <label className="text-[11px] text-[#718096] uppercase">Requirement</label>
                <select value={data.requirement_id} onChange={(e) => setData('requirement_id', e.target.value)} disabled={!framework} className="block w-full mt-0.5 text-sm border-gray-200 rounded-lg">
                    <option value="">{framework ? 'Select requirement…' : '—'}</option>
                    {requirements.map((r) => <option key={r.id} value={r.id}>{mapped.has(r.id) ? '✓ ' : ''}{r.requirement_code} {r.title}</option>)}
                </select>
                <InputError message={errors.requirement_id} className="mt-1" />
            </div>
            <div>
                <label className="text-[11px] text-[#718096] uppercase">Coverage</label>
                <select value={data.coverage} onChange={(e) => setData('coverage', e.target.value)} className="block mt-0.5 text-sm border-gray-200 rounded-lg">
                    {coverageOptions.map((c) => <option key={c} value={c}>{COVERAGE[c]?.label || c}</option>)}
                </select>
            </div>
            <button disabled={processing || !data.requirement_id} className="px-3 py-2 text-sm rounded-lg bg-[#1A365D] text-white disabled:opacity-40">{mapped.has(Number(data.requirement_id)) ? 'Update' : 'Map'}</button>
        </form>
    );
}

export default function ShowControl({ control, testResults, frameworks, coverageOptions, can }) {
    const [tab, setTab] = useState('details');
    const [uploading, setUploading] = useState(false);
    const [reviewing, setReviewing] = useState(null);
    const eff = effectivenessOf(control.effectiveness);
    const reviewDue = isOverdue(control.next_review_date);

    const byFramework = (control.framework_requirements || []).reduce((acc, r) => {
        const k = r.framework?.short_name || 'Other';
        (acc[k] = acc[k] || []).push(r);
        return acc;
    }, {});

    const unmap = (r) => {
        if (confirm(`Remove the mapping to ${r.requirement_code}?`)) {
            router.delete(route('controls.mappings.destroy', [control.id, r.id]), { preserveScroll: true });
        }
    };
    const archive = () => {
        if (confirm(`Archive ${control.control_code}? It is removed from the library, mappings and statistics (soft delete).`)) {
            router.delete(route('controls.destroy', control.id));
        }
    };
    const removeEvidence = (e) => {
        if (confirm(`Remove evidence "${e.title}"?`)) router.delete(route('evidence.destroy', e.id), { preserveScroll: true });
    };

    const tabs = [
        ['details', 'Details'],
        ['mappings', `Framework mappings (${control.framework_requirements?.length || 0})`],
        ['testing', `Testing (${testResults.length})`],
        ['risks', `Risks (${control.risks?.length || 0})`],
        ['evidence', `Evidence (${control.evidence?.length || 0})`],
    ];

    return (
        <AuthenticatedLayout header={<div className="flex items-center gap-3"><span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">{control.control_code}</span>{control.title}</div>}>
            <Head title={control.control_code} />

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Status</p>
                    <Chip className={`mt-2 ${CONTROL_STATUS[control.status] || ''}`}>{humanize(control.status)}</Chip>
                    {control.is_key_control && <Chip className="mt-2 ml-1 bg-[#D4AF37]/10 text-[#B7791F]">Key control</Chip>}
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Effectiveness</p>
                    <p className="text-sm font-semibold mt-2" style={{ color: eff.color }}>{eff.label}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Type / Nature</p>
                    <p className="text-sm text-[#2D3748] mt-2">{humanize(control.type)} / {humanize(control.nature)}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Last tested · next review</p>
                    <p className="text-sm text-[#2D3748] mt-2">{formatDate(control.last_tested)} · <span className={reviewDue ? 'text-red-600 font-semibold' : ''}>{formatDate(control.next_review_date)}</span></p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Framework mappings</p>
                    <p className="text-2xl font-bold font-mono-data text-[#1A365D] mt-1">{control.framework_requirements?.length || 0}</p>
                </div>
            </div>

            <div className="flex flex-wrap gap-2 mb-4">
                {can.edit && <Link href={route('controls.edit', control.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#4A5568]"><PencilIcon className="w-4 h-4" /> Edit</Link>}
                {can.evidence && <button onClick={() => setUploading(true)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#4A5568]"><ArrowUpTrayIcon className="w-4 h-4" /> Upload evidence</button>}
                {can.delete && <button onClick={archive} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-red-600 hover:underline"><TrashIcon className="w-4 h-4" /> Archive</button>}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-4 flex gap-1 overflow-x-auto">
                    {tabs.map(([k, label]) => (
                        <button key={k} onClick={() => setTab(k)} className={`px-4 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors ${tab === k ? 'border-[#D4AF37] text-[#1A365D]' : 'border-transparent text-[#718096] hover:text-[#2D3748]'}`}>{label}</button>
                    ))}
                </div>
                <div className="p-5">
                    {tab === 'details' && (
                        <div className="space-y-5">
                            <div><h4 className="text-xs text-[#718096] uppercase mb-1">Description</h4><p className="text-sm text-[#2D3748] whitespace-pre-line">{control.description || 'No description.'}</p></div>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Domain</h4><p className="text-sm text-[#2D3748]">{control.domain || '--'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Owner</h4><p className="text-sm text-[#2D3748]">{control.owner?.name || <span className="text-[#B7791F]">Unassigned</span>}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Frequency</h4><p className="text-sm text-[#2D3748]">{humanize(control.frequency)}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Parent control</h4><p className="text-sm text-[#2D3748]">{control.parent ? <Link href={route('controls.show', control.parent.id)} className="text-[#1A365D] hover:underline">{control.parent.control_code}</Link> : '--'}</p></div>
                            </div>
                            {control.implementation_notes && <div><h4 className="text-xs text-[#718096] uppercase mb-1">Implementation notes</h4><p className="text-sm text-[#2D3748] whitespace-pre-line">{control.implementation_notes}</p></div>}
                            {control.children?.length > 0 && (
                                <div>
                                    <h4 className="text-xs text-[#718096] uppercase mb-1">Sub-controls</h4>
                                    <div className="flex flex-wrap gap-2">{control.children.map((c) => <Link key={c.id} href={route('controls.show', c.id)} className="text-xs bg-gray-50 border border-gray-100 rounded px-2 py-1 hover:border-[#1A365D]">{c.control_code} {c.title}</Link>)}</div>
                                </div>
                            )}
                        </div>
                    )}

                    {tab === 'mappings' && (
                        <div>
                            {can.edit && <MappingForm control={control} frameworks={frameworks} coverageOptions={coverageOptions} />}
                            {Object.keys(byFramework).length === 0 ? <p className="text-sm text-[#718096] py-8 text-center">Not mapped to any framework requirement — this control doesn't yet evidence compliance with anything.</p> : (
                                <div className="space-y-4">
                                    {Object.entries(byFramework).map(([fw, reqs]) => (
                                        <div key={fw}>
                                            <h4 className="text-xs font-semibold uppercase text-[#718096] mb-1">{fw}</h4>
                                            {reqs.map((r) => (
                                                <div key={r.id} className="flex items-center justify-between gap-3 py-2 border-b border-gray-50">
                                                    <div className="min-w-0"><span className="font-mono-data text-xs text-[#1A365D] font-semibold mr-2">{r.requirement_code}</span><span className="text-sm text-[#2D3748]">{r.title}</span></div>
                                                    <div className="flex items-center gap-2 shrink-0">
                                                        <Chip className={COVERAGE[r.pivot?.coverage]?.chip}>{COVERAGE[r.pivot?.coverage]?.label || r.pivot?.coverage}</Chip>
                                                        {can.edit && <button onClick={() => unmap(r)} title="Remove mapping" className="p-1 text-[#A0AEC0] hover:text-red-600"><XMarkIcon className="w-4 h-4" /></button>}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {tab === 'testing' && (
                        testResults.length === 0 ? <p className="text-sm text-[#718096] py-8 text-center">This control hasn't been tested in a compliance assessment yet.</p> : (
                            <table className="w-full text-sm">
                                <thead><tr className="text-left text-xs text-[#718096] border-b border-gray-100"><th className="py-2">Assessment</th><th className="py-2">Requirement</th><th className="py-2">Result</th><th className="py-2">Assessed</th></tr></thead>
                                <tbody className="divide-y divide-gray-50">
                                    {testResults.map((t) => (
                                        <tr key={t.id}>
                                            <td className="py-2"><Link href={route('compliance-assessments.show', t.assessment.id)} className="text-[#1A365D] hover:underline">{t.assessment.title}</Link> <Chip className={`ml-1 ${ASSESSMENT_STATUS[t.assessment.status]?.chip}`}>{ASSESSMENT_STATUS[t.assessment.status]?.label}</Chip></td>
                                            <td className="py-2 text-xs"><span className="font-mono-data">{t.requirement?.requirement_code}</span> {t.requirement?.title}</td>
                                            <td className="py-2"><Chip className={RESULT_STYLES[t.status]?.chip}>{RESULT_STYLES[t.status]?.label}</Chip></td>
                                            <td className="py-2 text-xs text-[#718096]">{formatDate(t.assessed_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )
                    )}

                    {tab === 'risks' && (
                        control.risks?.length > 0 ? (
                            <div className="divide-y divide-gray-50">{control.risks.map((r) => (
                                <div key={r.id} className="flex items-center justify-between py-2 gap-3">
                                    <div className="min-w-0"><Link href={route('risks.show', r.id)} className="font-mono-data text-xs text-[#1A365D] font-semibold hover:underline">{r.risk_id_code}</Link><p className="text-sm text-[#2D3748]">{r.title}</p></div>
                                    <div className="flex items-center gap-2 shrink-0">{r.pivot?.effectiveness && <span className="text-xs text-[#718096]">mitigation: {humanize(r.pivot.effectiveness)}</span>}<RatingBadge rating={r.residual_rating || r.inherent_rating} /><StatusBadge status={r.status} /></div>
                                </div>
                            ))}</div>
                        ) : <p className="text-sm text-[#718096] py-8 text-center">No linked risks. Link controls to risks from the risk's Controls tab.</p>
                    )}

                    {tab === 'evidence' && (
                        control.evidence?.length > 0 ? (
                            <div className="divide-y divide-gray-50">{control.evidence.map((e) => {
                                const st = evidenceState(e);
                                return (
                                    <div key={e.id} className="flex items-center justify-between py-2 gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-[#2D3748]">{e.title}</p>
                                            <p className="text-xs text-[#718096]">{EVIDENCE_TYPES[e.type] || e.type} · {e.uploader?.name} · {formatDate(e.created_at)}{e.valid_until ? ` · valid until ${formatDate(e.valid_until)}` : ''}</p>
                                            {e.status === 'rejected' && e.review_notes && <p className="text-[11px] text-red-600">{e.review_notes}</p>}
                                        </div>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <Chip className={st.chip}>{st.label}</Chip>
                                            {(e.file_path || e.url) && <a href={route('evidence.download', e.id)} target="_blank" rel="noreferrer" className="p-1 text-[#718096] hover:text-[#1A365D]" title="Download"><ArrowDownTrayIcon className="w-4 h-4" /></a>}
                                            {can.reviewEvidence && e.status === 'pending' && <button onClick={() => setReviewing(e)} className="text-xs px-2 py-1 rounded-lg bg-[#1A365D] text-white">Review</button>}
                                            {can.delete && <button onClick={() => removeEvidence(e)} className="p-1 text-[#A0AEC0] hover:text-red-600" title="Remove"><TrashIcon className="w-4 h-4" /></button>}
                                        </div>
                                    </div>
                                );
                            })}</div>
                        ) : <p className="text-sm text-[#718096] py-8 text-center">No evidence attached yet.</p>
                    )}
                </div>
            </div>

            <EvidenceUploadModal show={uploading} onClose={() => setUploading(false)} subject={{ type: 'control', id: control.id, label: `${control.control_code} ${control.title}` }} />
            <EvidenceReviewModal evidence={reviewing} onClose={() => setReviewing(null)} />
        </AuthenticatedLayout>
    );
}

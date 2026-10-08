import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Chip from '@/Components/Compliance/Chip';
import EvidenceUploadModal from '@/Components/Compliance/EvidenceUploadModal';
import { LockClosedIcon, MagnifyingGlassIcon, PaperClipIcon, PencilSquareIcon } from '@heroicons/react/24/outline';
import { RESULT_STYLES, ASSESSMENT_STATUS, GAP_STATUS, GAP_RESOLVED, effectivenessOf, scoreColor, formatDate, toDateInput, isOverdue } from '@/Utils/compliance';

const field = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';
const NEEDS_FINDING = ['non_compliant', 'partially_compliant'];

function ResultRow({ result, gap, controls, editable, canAssess, canEvidence, onEvidence }) {
    const [open, setOpen] = useState(false);
    const { data, setData, patch, processing, errors, clearErrors } = useForm({
        status: result.status,
        findings: result.findings || '',
        recommendations: result.recommendations || '',
        control_id: result.control_id || '',
    });
    const st = RESULT_STYLES[result.status] || RESULT_STYLES.not_assessed;

    const start = () => {
        setData({ status: result.status === 'not_assessed' ? 'compliant' : result.status, findings: result.findings || '', recommendations: result.recommendations || '', control_id: result.control_id || '' });
        clearErrors();
        setOpen(true);
    };
    const save = (e) => {
        e.preventDefault();
        patch(route('compliance-results.update', result.id), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <div className={`border rounded-lg p-3 ${st.row}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono-data text-xs font-semibold text-[#1A365D]">{result.requirement?.requirement_code}</span>
                        <span className="text-sm text-[#2D3748]">{result.requirement?.title}</span>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap mt-1 text-[11px] text-[#718096]">
                        {result.control && (
                            <Link href={route('controls.show', result.control.id)} className="inline-flex items-center gap-1 hover:text-[#1A365D]" title={`Control effectiveness: ${effectivenessOf(result.control.effectiveness).label}`}>
                                <span className="w-1.5 h-1.5 rounded-full" style={{ background: effectivenessOf(result.control.effectiveness).color }} />
                                {result.control.control_code}
                            </Link>
                        )}
                        {gap && (
                            <Link href={route('gap-analysis.index', { search: gap.gap_code, state: 'all' })} className={`px-1.5 rounded ${GAP_RESOLVED.includes(gap.status) ? 'bg-gray-100' : 'bg-red-50 text-red-700'}`}>
                                {gap.gap_code} · {GAP_STATUS[gap.status]?.label || gap.status}
                            </Link>
                        )}
                        {result.evidence_count > 0 && <span className="inline-flex items-center gap-0.5"><PaperClipIcon className="w-3 h-3" />{result.evidence_count} evidence</span>}
                        {result.assessor && <span>Assessed by {result.assessor.name} · {formatDate(result.assessed_at)}</span>}
                    </div>
                    {!open && result.findings && <p className="text-xs text-[#4A5568] mt-1.5"><span className="font-medium">Finding:</span> {result.findings}</p>}
                    {!open && result.recommendations && <p className="text-xs text-[#4A5568] mt-0.5"><span className="font-medium">Recommendation:</span> {result.recommendations}</p>}
                </div>
                {!open && (
                    <div className="flex items-center gap-2 shrink-0">
                        <Chip className={st.chip}>{st.label}</Chip>
                        {editable && canAssess && <button onClick={start} className="text-xs text-[#1A365D] font-medium hover:underline">{result.status === 'not_assessed' ? 'Assess' : 'Edit'}</button>}
                        {canEvidence && <button onClick={() => onEvidence(result)} title="Attach evidence" className="p-1 text-[#718096] hover:text-[#1A365D]"><PaperClipIcon className="w-4 h-4" /></button>}
                    </div>
                )}
            </div>

            {open && (
                <form onSubmit={save} className="mt-3 pt-3 border-t border-gray-200/70 space-y-3">
                    <div className="flex flex-wrap gap-1.5">
                        {Object.entries(RESULT_STYLES).map(([k, v]) => (
                            <button type="button" key={k} onClick={() => setData('status', k)}
                                className={`text-xs px-2.5 py-1 rounded-full border ${data.status === k ? 'ring-2 ring-offset-1 ring-[#1A365D]/40 ' + v.chip + ' border-transparent' : 'border-gray-200 text-[#718096] bg-white'}`}>
                                {v.label}
                            </button>
                        ))}
                    </div>
                    <InputError message={errors.status} />
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <InputLabel value={NEEDS_FINDING.includes(data.status) ? 'Finding *' : 'Finding / test notes'} />
                            <textarea value={data.findings} onChange={(e) => setData('findings', e.target.value)} rows={2} className={field} placeholder="What was tested and what was found" />
                            <InputError message={errors.findings} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Recommendation" />
                            <textarea value={data.recommendations} onChange={(e) => setData('recommendations', e.target.value)} rows={2} className={field} placeholder="Becomes the gap's initial remediation plan" />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="flex-1 min-w-[240px]">
                            <InputLabel value="Control tested" />
                            <select value={data.control_id} onChange={(e) => setData('control_id', e.target.value)} className={field}>
                                <option value="">—</option>
                                {controls.map((c) => <option key={c.id} value={c.id}>{c.control_code} — {c.title}</option>)}
                            </select>
                            <InputError message={errors.control_id} className="mt-1" />
                        </div>
                        <div className="flex gap-2">
                            <SecondaryButton type="button" onClick={() => setOpen(false)}>Cancel</SecondaryButton>
                            <PrimaryButton disabled={processing}>Save result</PrimaryButton>
                        </div>
                    </div>
                    {NEEDS_FINDING.includes(data.status) && <p className="text-[11px] text-[#B7791F]">Saving this result opens (or updates) a remediation gap for this requirement.</p>}
                    {['compliant', 'not_applicable'].includes(data.status) && gap && !GAP_RESOLVED.includes(gap.status) && <p className="text-[11px] text-[#2D7D46]">Saving resolves open gap {gap.gap_code} as remediated.</p>}
                </form>
            )}
        </div>
    );
}

function DetailsModal({ assessment, users, show, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        title: assessment.title, description: assessment.description || '', lead_assessor_id: assessment.lead_assessor_id || '',
        start_date: toDateInput(assessment.start_date), due_date: toDateInput(assessment.due_date), summary: assessment.summary || '',
    });
    const submit = (e) => { e.preventDefault(); put(route('compliance-assessments.update', assessment.id), { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal show={show} onClose={onClose} maxWidth="xl">
            <form onSubmit={submit} className="p-6 space-y-4">
                <h3 className="text-lg font-semibold text-[#2D3748]">Assessment details</h3>
                <div><InputLabel value="Title *" /><TextInput value={data.title} onChange={(e) => setData('title', e.target.value)} className="mt-1 block w-full" /><InputError message={errors.title} className="mt-1" /></div>
                <div><InputLabel value="Scope / description" /><textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} className={field} /></div>
                <div className="grid grid-cols-3 gap-3">
                    <div><InputLabel value="Lead assessor" /><select value={data.lead_assessor_id} onChange={(e) => setData('lead_assessor_id', e.target.value)} className={field}><option value="">—</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></div>
                    <div><InputLabel value="Start" /><input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} className={field} /></div>
                    <div><InputLabel value="Due" /><input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} className={field} /><InputError message={errors.due_date} className="mt-1" /></div>
                </div>
                <div><InputLabel value="Summary / conclusion" /><textarea value={data.summary} onChange={(e) => setData('summary', e.target.value)} rows={3} className={field} /></div>
                <div className="flex justify-end gap-2 pt-2 border-t border-gray-100"><SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton><PrimaryButton disabled={processing}>Save</PrimaryButton></div>
            </form>
        </Modal>
    );
}

function CompleteModal({ assessment, show, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ action: 'complete', summary: assessment.summary || '' });
    const submit = (e) => { e.preventDefault(); post(route('compliance-assessments.transition', assessment.id), { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6 space-y-4">
                <div>
                    <h3 className="text-lg font-semibold text-[#2D3748]">Complete assessment</h3>
                    <p className="text-xs text-[#718096] mt-1">Completing locks the results and makes this the framework's current compliance score on the dashboard.</p>
                </div>
                <div><InputLabel value="Conclusion / summary" /><textarea value={data.summary} onChange={(e) => setData('summary', e.target.value)} rows={4} className={field} placeholder="Overall opinion, key themes, management response" /></div>
                <InputError message={errors.status} />
                <div className="flex justify-end gap-2 pt-2 border-t border-gray-100"><SecondaryButton type="button" onClick={onClose}>Cancel</SecondaryButton><PrimaryButton disabled={processing}>Complete</PrimaryButton></div>
            </form>
        </Modal>
    );
}

export default function AssessmentShow({ assessment, resultStatuses, gapsByRequirement, controls, users, can }) {
    const { errors } = usePage().props;
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [editing, setEditing] = useState(false);
    const [completing, setCompleting] = useState(false);
    const [evidenceFor, setEvidenceFor] = useState(null);

    const results = assessment.results || [];
    const editable = ['planned', 'in_progress'].includes(assessment.status);
    const total = results.length;
    const notAssessed = results.filter((r) => r.status === 'not_assessed').length;
    const assessed = total - notAssessed;
    const counts = useMemo(() => results.reduce((acc, r) => ({ ...acc, [r.status]: (acc[r.status] || 0) + 1 }), {}), [results]);
    const statusMeta = ASSESSMENT_STATUS[assessment.status] || ASSESSMENT_STATUS.planned;
    const overdue = editable && isOverdue(assessment.due_date);

    // Group by parent domain (e.g. ISO "A.5 Organizational controls") for long frameworks.
    const groups = useMemo(() => {
        const q = search.trim().toLowerCase();
        const visible = results.filter((r) => (filter === 'all' || r.status === filter)
            && (!q || `${r.requirement?.requirement_code} ${r.requirement?.title} ${r.findings || ''}`.toLowerCase().includes(q)));
        const map = new Map();
        visible.forEach((r) => {
            const p = r.requirement?.parent;
            const key = p ? `${p.requirement_code} ${p.title}` : 'Requirements';
            if (!map.has(key)) map.set(key, []);
            map.get(key).push(r);
        });
        return [...map.entries()];
    }, [results, filter, search]);

    const transition = (action, confirmText) => {
        if (!confirmText || confirm(confirmText)) {
            router.post(route('compliance-assessments.transition', assessment.id), { action }, { preserveScroll: true });
        }
    };
    const destroy = () => {
        if (confirm(`Delete "${assessment.title}" and all of its results? Gaps it raised are kept but unlinked.`)) {
            router.delete(route('compliance-assessments.destroy', assessment.id));
        }
    };

    const kpi = (label, value, color) => (
        <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
            <p className="text-xs text-[#718096] uppercase">{label}</p>
            <p className="text-2xl font-bold font-mono-data mt-1" style={{ color }}>{value}</p>
        </div>
    );

    return (
        <AuthenticatedLayout header={assessment.title}>
            <Head title={assessment.title} />

            <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3 mb-4">
                <div className="text-sm text-[#718096] space-y-1">
                    <div className="flex items-center gap-2 flex-wrap">
                        <Chip className={statusMeta.chip}>{statusMeta.label}</Chip>
                        <Link href={route('frameworks.show', assessment.framework_id)} className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded hover:underline">{assessment.framework?.short_name}</Link>
                        <span>{assessment.framework?.name}</span>
                    </div>
                    <p className="text-xs">
                        Lead: <span className="text-[#2D3748]">{assessment.lead_assessor?.name || '—'}</span>
                        {' · '}Start {formatDate(assessment.start_date)}
                        {' · '}<span className={overdue ? 'text-red-600 font-semibold' : ''}>Due {formatDate(assessment.due_date)}{overdue ? ' (overdue)' : ''}</span>
                        {assessment.end_date && <> · {assessment.status === 'completed' ? 'Completed' : 'Closed'} {formatDate(assessment.end_date)}</>}
                    </p>
                    {assessment.description && <p className="text-xs max-w-3xl">{assessment.description}</p>}
                </div>
                {can.assess && (
                    <div className="flex flex-wrap gap-2 shrink-0">
                        <button onClick={() => setEditing(true)} className="inline-flex items-center gap-1 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#4A5568]"><PencilSquareIcon className="w-4 h-4" /> Details</button>
                        {assessment.status === 'planned' && <button onClick={() => transition('start')} className="px-3 py-1.5 text-sm rounded-lg bg-[#1A365D] text-white hover:bg-[#2D4A7A]">Start assessment</button>}
                        {assessment.status === 'in_progress' && (
                            <button onClick={() => setCompleting(true)} disabled={notAssessed > 0} title={notAssessed > 0 ? `${notAssessed} requirement(s) still to assess` : ''}
                                className="px-3 py-1.5 text-sm rounded-lg bg-[#2D7D46] text-white hover:bg-[#256B3B] disabled:opacity-40 disabled:cursor-not-allowed">Complete</button>
                        )}
                        {editable && <button onClick={() => transition('cancel', 'Cancel this assessment? Results are kept but it will no longer count toward compliance posture.')} className="px-3 py-1.5 text-sm border border-gray-200 rounded-lg text-[#718096] hover:bg-gray-50">Cancel</button>}
                        {!editable && <button onClick={() => transition('reopen', 'Reopen this assessment for further testing? It will stop counting as the completed result until completed again.')} className="px-3 py-1.5 text-sm border border-gray-200 rounded-lg text-[#4A5568] hover:bg-gray-50">Reopen</button>}
                        {can.delete && assessment.status !== 'completed' && <button onClick={destroy} className="px-3 py-1.5 text-sm text-red-600 hover:underline">Delete</button>}
                    </div>
                )}
            </div>

            {errors.status && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{errors.status}</div>}
            {!editable && (
                <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-sm text-[#4A5568] flex items-center gap-2">
                    <LockClosedIcon className="w-4 h-4" /> This assessment is {statusMeta.label.toLowerCase()} — results are read-only. Reopen it to change them.
                </div>
            )}

            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
                {kpi('Score', assessment.overall_score !== null ? `${Math.round(assessment.overall_score)}%` : '--', scoreColor(assessment.overall_score))}
                {kpi('Compliant', assessment.compliant_count, '#2D7D46')}
                {kpi('Partial', assessment.partial_count, '#B7791F')}
                {kpi('Non-compliant', assessment.non_compliant_count, '#C53030')}
                {kpi('Not applicable', assessment.not_applicable_count, '#718096')}
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Progress</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">{assessed}/{total}</p>
                    <div className="mt-1 bg-gray-200 rounded-full h-1.5"><div className="bg-[#1A365D] rounded-full h-1.5" style={{ width: `${total ? (assessed / total) * 100 : 0}%` }} /></div>
                </div>
            </div>
            {assessment.summary && <div className="mb-4 bg-white rounded-xl border border-gray-100 p-4 text-sm text-[#4A5568]"><span className="text-xs uppercase text-[#718096] block mb-1">Summary</span>{assessment.summary}</div>}

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 border-b border-gray-100 flex flex-col md:flex-row md:items-center gap-3 md:justify-between">
                    <div className="flex flex-wrap gap-1.5">
                        <button onClick={() => setFilter('all')} className={`text-xs px-2.5 py-1 rounded-full ${filter === 'all' ? 'bg-[#1A365D] text-white' : 'bg-gray-100 text-[#4A5568]'}`}>All · {total}</button>
                        {resultStatuses.map((s) => (
                            <button key={s} onClick={() => setFilter(s)} className={`text-xs px-2.5 py-1 rounded-full ${filter === s ? 'bg-[#1A365D] text-white' : RESULT_STYLES[s].chip}`}>
                                {RESULT_STYLES[s].label} · {counts[s] || 0}
                            </button>
                        ))}
                    </div>
                    <div className="relative md:w-72">
                        <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search requirements…" className="w-full pl-9 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg" />
                    </div>
                </div>
                <div className="p-4 space-y-5">
                    {groups.length === 0 && <p className="text-sm text-[#718096] text-center py-8">No requirements match.</p>}
                    {groups.map(([heading, rows]) => (
                        <div key={heading}>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-[#718096] mb-2">{heading} <span className="font-normal">({rows.length})</span></h4>
                            <div className="space-y-2">
                                {rows.map((r) => (
                                    <ResultRow key={r.id} result={r} gap={gapsByRequirement[r.requirement_id]} controls={controls}
                                        editable={editable} canAssess={can.assess} canEvidence={can.evidence} onEvidence={setEvidenceFor} />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <DetailsModal assessment={assessment} users={users} show={editing} onClose={() => setEditing(false)} />
            <CompleteModal assessment={assessment} show={completing} onClose={() => setCompleting(false)} />
            <EvidenceUploadModal show={!!evidenceFor} onClose={() => setEvidenceFor(null)}
                subject={evidenceFor ? { type: 'compliance_result', id: evidenceFor.id, label: `${evidenceFor.requirement?.requirement_code} ${evidenceFor.requirement?.title}` } : null} />
        </AuthenticatedLayout>
    );
}

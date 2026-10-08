import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import axios from 'axios';
import Pagination from '@/Components/Pagination';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Chip from '@/Components/Compliance/Chip';
import { PlusIcon, MagnifyingGlassIcon, PaperClipIcon } from '@heroicons/react/24/outline';
import { GAP_SEVERITY, GAP_STATUS, GAP_RESOLVED, formatDate, toDateInput } from '@/Utils/compliance';

const field = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';

function GapModal({ gap, show, onClose, users, frameworks, controls, statuses, severities, readOnly = false }) {
    const isNew = !gap;
    const initial = () => ({
        title: gap?.title || '',
        description: gap?.description || '',
        severity: gap?.severity || 'medium',
        status: gap?.status || 'identified',
        priority: gap?.priority || '',
        requirement_id: gap?.requirement_id || '',
        control_id: gap?.control_id || '',
        assigned_to: gap?.assigned_to || '',
        due_date: toDateInput(gap?.due_date),
        remediation_plan: gap?.remediation_plan || '',
        notes: gap?.notes || '',
    });
    const { data, setData, post, put, processing, errors, clearErrors } = useForm(initial());
    const [framework, setFramework] = useState(gap?.requirement?.framework_id || '');
    const [requirements, setRequirements] = useState([]);

    useEffect(() => {
        if (show) {
            setData(initial());
            clearErrors();
            setFramework(gap?.requirement?.framework_id || '');
        }
    }, [show, gap?.id]);

    useEffect(() => {
        if (!framework) return setRequirements([]);
        axios.get(route('frameworks.requirements', framework)).then((r) => setRequirements(r.data));
    }, [framework]);

    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        isNew ? post(route('gap-analysis.store'), opts) : put(route('gap-analysis.update', gap.id), opts);
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6 space-y-4 max-h-[85vh] overflow-y-auto">
                <fieldset disabled={readOnly} className="contents">
                <div>
                    <h3 className="text-lg font-semibold text-[#2D3748]">{isNew ? 'Log a gap' : <>Gap <span className="font-mono-data">{gap.gap_code}</span></>}</h3>
                    {gap?.assessment && <p className="text-xs text-[#718096]">Raised by assessment <Link href={route('compliance-assessments.show', gap.assessment.id)} className="text-[#1A365D] underline">{gap.assessment.title}</Link></p>}
                </div>
                <div>
                    <InputLabel value="Title *" />
                    <TextInput value={data.title} onChange={(e) => setData('title', e.target.value)} className="mt-1 block w-full" />
                    <InputError message={errors.title} className="mt-1" />
                </div>
                <div>
                    <InputLabel value="Description / finding" />
                    <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} className={field} />
                </div>
                <div className="grid grid-cols-3 gap-3">
                    <div>
                        <InputLabel value="Severity *" />
                        <select value={data.severity} onChange={(e) => setData('severity', e.target.value)} className={field}>
                            {severities.map((s) => <option key={s} value={s}>{GAP_SEVERITY[s]?.label || s}</option>)}
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Status" />
                        <select value={data.status} onChange={(e) => setData('status', e.target.value)} className={field}>
                            {statuses.map((s) => <option key={s} value={s}>{GAP_STATUS[s]?.label || s}</option>)}
                        </select>
                        <InputError message={errors.status} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Priority (1 = urgent)" />
                        <select value={data.priority} onChange={(e) => setData('priority', e.target.value)} className={field}>
                            <option value="">From severity</option>
                            {[1, 2, 3, 4, 5].map((p) => <option key={p} value={p}>P{p}</option>)}
                        </select>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <InputLabel value="Owner" />
                        <select value={data.assigned_to} onChange={(e) => setData('assigned_to', e.target.value)} className={field}>
                            <option value="">Unassigned</option>
                            {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                        <InputError message={errors.assigned_to} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Target date" />
                        <input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} className={field} />
                    </div>
                </div>
                <div className="grid grid-cols-3 gap-3">
                    <div>
                        <InputLabel value="Framework" />
                        <select value={framework} onChange={(e) => { setFramework(e.target.value); setData('requirement_id', ''); }} className={field}>
                            <option value="">—</option>
                            {frameworks.map((f) => <option key={f.id} value={f.id}>{f.short_name}</option>)}
                        </select>
                    </div>
                    <div className="col-span-2">
                        <InputLabel value="Requirement" />
                        <select value={data.requirement_id} onChange={(e) => setData('requirement_id', e.target.value)} className={field} disabled={!framework}>
                            <option value="">{gap?.requirement && !requirements.length ? `${gap.requirement.requirement_code} ${gap.requirement.title}` : '—'}</option>
                            {requirements.map((r) => <option key={r.id} value={r.id}>{r.requirement_code} {r.title}</option>)}
                        </select>
                    </div>
                </div>
                <div>
                    <InputLabel value="Related control" />
                    <select value={data.control_id} onChange={(e) => setData('control_id', e.target.value)} className={field}>
                        <option value="">—</option>
                        {controls.map((c) => <option key={c.id} value={c.id}>{c.control_code} — {c.title}</option>)}
                    </select>
                </div>
                <div>
                    <InputLabel value={['remediation_planned', 'in_progress'].includes(data.status) ? 'Remediation plan *' : 'Remediation plan'} />
                    <textarea value={data.remediation_plan} onChange={(e) => setData('remediation_plan', e.target.value)} rows={2} className={field} />
                    <InputError message={errors.remediation_plan} className="mt-1" />
                </div>
                <div>
                    <InputLabel value={data.status === 'accepted' ? 'Notes — risk-acceptance rationale and approver *' : 'Notes'} />
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} className={field} />
                    <InputError message={errors.notes} className="mt-1" />
                </div>
                </fieldset>
                <div className="flex justify-end gap-2 pt-2 border-t border-gray-100">
                    <SecondaryButton type="button" onClick={onClose}>{readOnly ? 'Close' : 'Cancel'}</SecondaryButton>
                    {!readOnly && <PrimaryButton disabled={processing}>{isNew ? 'Log gap' : 'Save changes'}</PrimaryButton>}
                </div>
            </form>
        </Modal>
    );
}

export default function GapAnalysisIndex({ gaps, stats, bySeverity, filters, statuses, severities, users, frameworks, controls, can }) {
    const [editing, setEditing] = useState(null); // gap object, 'new', or null
    const [search, setSearch] = useState(filters.search || '');

    const apply = (patch) => router.get(route('gap-analysis.index'), { ...filters, ...patch, page: undefined }, { preserveState: true, preserveScroll: true, replace: true });

    const remove = (g) => {
        if (confirm(`Delete gap ${g.gap_code}? Prefer closing or accepting it so the audit trail is kept.`)) {
            router.delete(route('gap-analysis.destroy', g.id), { preserveScroll: true });
        }
    };

    const kpis = [
        ['Open gaps', stats.open, { state: 'open', severity: undefined, overdue: undefined, assignee: undefined }, 'text-[#2D3748]'],
        ['Critical', stats.critical, { state: 'open', severity: 'critical' }, 'text-[#C53030]'],
        ['High', stats.high, { state: 'open', severity: 'high' }, 'text-[#DD6B20]'],
        ['Overdue', stats.overdue, { state: 'open', overdue: 1 }, 'text-[#C53030]'],
        ['Unassigned', stats.unassigned, { state: 'open', assignee: 'none' }, 'text-[#B7791F]'],
        ['Resolved', stats.resolved, { state: 'resolved', severity: undefined }, 'text-[#2D7D46]'],
    ];
    const totalOpen = Object.values(bySeverity).reduce((a, b) => a + Number(b), 0) || 1;

    return (
        <AuthenticatedLayout header="Gap Analysis">
            <Head title="Gap Analysis" />

            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <p className="text-sm text-[#718096]">Gaps are raised automatically when an assessment marks a requirement non- or partially compliant, and resolved when it is re-assessed as compliant. Track each one to closure here.</p>
                {can.create && (
                    <button onClick={() => setEditing('new')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] shrink-0">
                        <PlusIcon className="w-4 h-4" /> Log Gap
                    </button>
                )}
            </div>

            <div className="grid grid-cols-3 sm:grid-cols-6 gap-3 mb-3">
                {kpis.map(([label, value, patch, tone]) => (
                    <button key={label} onClick={() => apply(patch)} className="text-left bg-white rounded-xl border border-gray-100 p-3 shadow-sm hover:shadow">
                        <p className="text-xs text-[#718096] uppercase">{label}</p>
                        <p className={`text-2xl font-bold font-mono-data mt-1 ${tone}`}>{value}</p>
                    </button>
                ))}
            </div>

            <div className="flex h-2 rounded-full overflow-hidden mb-4 bg-gray-100" title="Open gaps by severity">
                {severities.map((s) => bySeverity[s] ? <div key={s} style={{ width: `${(bySeverity[s] / totalOpen) * 100}%`, background: GAP_SEVERITY[s].color }} title={`${GAP_SEVERITY[s].label}: ${bySeverity[s]}`} /> : null)}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 flex flex-wrap gap-2">
                    <form onSubmit={(e) => { e.preventDefault(); apply({ search: search || undefined }); }} className="relative flex-1 min-w-[200px]">
                        <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, title, finding…" className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg" />
                    </form>
                    <select value={filters.state || 'open'} onChange={(e) => apply({ state: e.target.value })} className="text-sm border-gray-200 rounded-lg">
                        <option value="open">Open</option><option value="resolved">Resolved</option><option value="all">All</option>
                    </select>
                    <select value={filters.severity || ''} onChange={(e) => apply({ severity: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All severities</option>
                        {severities.map((s) => <option key={s} value={s}>{GAP_SEVERITY[s].label}</option>)}
                    </select>
                    <select value={filters.status || ''} onChange={(e) => apply({ status: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All statuses</option>
                        {statuses.map((s) => <option key={s} value={s}>{GAP_STATUS[s].label}</option>)}
                    </select>
                    <select value={filters.framework || ''} onChange={(e) => apply({ framework: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All frameworks</option>
                        {frameworks.map((f) => <option key={f.id} value={f.id}>{f.short_name}</option>)}
                    </select>
                    <select value={filters.assignee || ''} onChange={(e) => apply({ assignee: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">Any owner</option><option value="none">Unassigned</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                    {filters.overdue ? <button onClick={() => apply({ overdue: undefined })} className="text-xs px-2 py-1 rounded-full bg-red-50 text-red-700">Overdue only ✕</button> : null}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="bg-gray-50/50 border-y border-gray-100 text-left text-[#718096]">
                            <th className="px-4 py-3 font-medium">Code</th>
                            <th className="px-4 py-3 font-medium">Gap</th>
                            <th className="px-4 py-3 font-medium">Requirement</th>
                            <th className="px-4 py-3 font-medium">Severity</th>
                            <th className="px-4 py-3 font-medium">Status</th>
                            <th className="px-4 py-3 font-medium">Owner</th>
                            <th className="px-4 py-3 font-medium">Target</th>
                            <th className="px-4 py-3" />
                        </tr></thead>
                        <tbody className="divide-y divide-gray-50">
                            {gaps.data.length === 0 ? (
                                <tr><td colSpan={8} className="px-4 py-12 text-center text-[#718096]">No gaps match these filters.</td></tr>
                            ) : gaps.data.map((g) => (
                                <tr key={g.id} className="hover:bg-gray-50/50 align-top">
                                    <td className="px-4 py-3 font-mono-data text-xs text-[#1A365D] font-semibold whitespace-nowrap">{g.gap_code}</td>
                                    <td className="px-4 py-3 max-w-md">
                                        <button onClick={() => setEditing(g)} className="text-left text-[#2D3748] font-medium hover:text-[#1A365D]">{g.title}</button>
                                        <p className="text-xs text-[#718096]">
                                            {g.assessment ? <Link href={route('compliance-assessments.show', g.assessment.id)} className="hover:underline">{g.assessment.title}</Link> : 'Logged manually'}
                                            {g.control && <> · <Link href={route('controls.show', g.control.id)} className="hover:underline">{g.control.control_code}</Link></>}
                                            {g.evidence_count > 0 && <span className="inline-flex items-center gap-0.5 ml-1"><PaperClipIcon className="w-3 h-3" />{g.evidence_count}</span>}
                                        </p>
                                    </td>
                                    <td className="px-4 py-3 text-xs text-[#718096] whitespace-nowrap">
                                        {g.requirement ? <><span className="text-[10px] text-[#A0AEC0]">{g.requirement.framework?.short_name}</span><br /><span className="font-mono-data">{g.requirement.requirement_code}</span></> : '—'}
                                    </td>
                                    <td className="px-4 py-3"><Chip className={GAP_SEVERITY[g.severity]?.chip}>{GAP_SEVERITY[g.severity]?.label || g.severity}</Chip></td>
                                    <td className="px-4 py-3"><Chip className={GAP_STATUS[g.status]?.chip}>{GAP_STATUS[g.status]?.label || g.status}</Chip></td>
                                    <td className="px-4 py-3 text-xs">{g.assignee?.name || <span className="text-[#B7791F]">Unassigned</span>}</td>
                                    <td className={`px-4 py-3 text-xs whitespace-nowrap ${g.is_overdue ? 'text-red-600 font-semibold' : 'text-[#718096]'}`}>
                                        {formatDate(g.due_date)}{g.is_overdue && <span className="block text-[10px] uppercase">Overdue</span>}
                                        {GAP_RESOLVED.includes(g.status) && g.completed_at && <span className="block text-[10px] text-[#2D7D46]">Closed {formatDate(g.completed_at)}</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                        {can.edit && <button onClick={() => setEditing(g)} className="text-xs text-[#1A365D] hover:underline">Update</button>}
                                        {can.delete && <button onClick={() => remove(g)} className="text-xs text-[#A0AEC0] hover:text-red-600 ml-3">Delete</button>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={gaps.links} /></div>
            </div>

            <GapModal show={editing !== null} gap={editing === 'new' ? null : editing} onClose={() => setEditing(null)}
                users={users} frameworks={frameworks} controls={controls} statuses={statuses} severities={severities}
                readOnly={editing !== 'new' && !can.edit} />
        </AuthenticatedLayout>
    );
}

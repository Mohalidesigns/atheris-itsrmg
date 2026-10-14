import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ArrowDownTrayIcon, ExclamationTriangleIcon, PencilSquareIcon } from '@/Components/Material/Symbols';

const implTone = { implemented: 'bg-[#D1FAE5] text-[#2D7D46]', partial: 'bg-[#FEF3C7] text-[#8A6400]', planned: 'bg-[#DBEAFE] text-[#1A365D]', not_started: 'bg-[#FEE2E2] text-[#C53030]', not_applicable: 'bg-gray-100 text-[#718096]' };
const resultTone = { compliant: 'text-[#2D7D46]', partially_compliant: 'text-[#8A6400]', non_compliant: 'font-semibold text-[#B3261E]' };
const effTone = { effective: 'text-[#2D7D46]', partially_effective: 'text-[#8A6400]', ineffective: 'font-semibold text-[#B3261E]', not_assessed: 'text-[#718096]' };
const label = (s) => (s || '').replace(/_/g, ' ');
const fmt = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

function EditModal({ row, onClose, controls, implementation }) {
    const form = useForm({
        is_applicable: row.is_applicable ?? true,
        justification: row.justification ?? '',
        implementation_status: row.implementation_status && row.implementation_status !== 'not_applicable' ? row.implementation_status : 'planned',
        control_id: row.control_id ?? '',
    });
    const save = (e) => { e.preventDefault(); form.put(route('isms.soa.update', row.requirement_id), { preserveScroll: true, onSuccess: onClose }); };

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <form onSubmit={save} className="space-y-3 p-6">
                <h3 className="text-base font-semibold text-[#2D3748]"><span className="font-mono text-sm">{row.requirement_code}</span> {row.title}</h3>
                {row.description && <p className="text-xs text-[#718096]">{row.description}</p>}
                <div className="flex gap-4 text-sm">
                    <label className="flex items-center gap-2"><input type="radio" checked={form.data.is_applicable === true} onChange={() => form.setData('is_applicable', true)} /> Applicable</label>
                    <label className="flex items-center gap-2"><input type="radio" checked={form.data.is_applicable === false} onChange={() => form.setData('is_applicable', false)} /> Excluded</label>
                </div>
                <label className="block text-xs text-[#718096]">{form.data.is_applicable ? 'Why it is included *' : 'Why it is excluded *'}
                    <textarea rows={3} value={form.data.justification} onChange={(e) => form.setData('justification', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-sm" />
                    {form.errors.justification && <span className="text-[11px] text-[#C53030]">{form.errors.justification}</span>}
                </label>
                {form.data.is_applicable && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label className="text-xs text-[#718096]">Implementation *
                            <select value={form.data.implementation_status} onChange={(e) => form.setData('implementation_status', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                {implementation.filter((i) => i !== 'not_applicable').map((i) => <option key={i} value={i}>{label(i)}</option>)}
                            </select>
                            {form.errors.implementation_status && <span className="text-[11px] text-[#C53030]">{form.errors.implementation_status}</span>}
                        </label>
                        <label className="text-xs text-[#718096]">Primary implementing control
                            <select value={form.data.control_id ?? ''} onChange={(e) => form.setData('control_id', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option value="">None</option>
                                {controls.map((c) => <option key={c.id} value={c.id}>{c.control_code} — {c.title}</option>)}
                            </select>
                        </label>
                    </div>
                )}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-[#718096]">Cancel</button>
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save</button>
                </div>
            </form>
        </Modal>
    );
}

function Versions({ versions, can, me, changes }) {
    const submit = useForm({ notes: '' });
    const decide = useForm({ decision: '', notes: '' });
    const pending = versions.find((v) => v.status === 'submitted');
    const send = (id, decision) => { decide.transform((d) => ({ ...d, decision })); decide.post(route('isms.soa.decide', id), { preserveScroll: true }); };
    const changeCount = Object.keys(changes).length;

    return (
        <div className="mb-4 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 className="text-sm font-semibold text-[#2D3748]">Approved versions</h3>
                    <p className="text-xs text-[#718096]">ISO/IEC 27001 cl. 6.1.3(d): the SoA is documented information approved by management. {changeCount > 0 ? `${changeCount} entries changed since the last approved version.` : 'No changes since the last approved version.'}</p>
                </div>
                {can.edit && !pending && (
                    <div className="flex gap-2">
                        <input value={submit.data.notes} onChange={(e) => submit.setData('notes', e.target.value)} placeholder="Notes for the approver" className="w-56 rounded-lg border-gray-300 text-xs" />
                        <button type="button" disabled={submit.processing} onClick={() => submit.post(route('isms.soa.submit'), { preserveScroll: true })} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white">Submit new version</button>
                    </div>
                )}
            </div>
            {Object.values({ ...submit.errors, ...decide.errors }).length > 0 && <p className="mt-2 text-xs text-[#C53030]">{Object.values({ ...submit.errors, ...decide.errors }).join(' ')}</p>}
            <ul className="mt-3 divide-y divide-gray-50 text-xs">
                {versions.length === 0 && <li className="py-2 text-[#718096]">No version has been issued yet.</li>}
                {versions.map((v) => (
                    <li key={v.id} className="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            <strong className="text-[#0A1F44]">v{v.version_no}</strong>{' '}
                            <span className={v.status === 'approved' ? 'text-[#2D7D46]' : v.status === 'returned' ? 'text-[#B3261E]' : 'text-[#8A6400]'}>{v.status}</span>
                            {' · '}prepared by {v.preparer?.name || '—'} {fmt(v.prepared_at)}
                            {v.decider && ` · ${v.status === 'approved' ? 'approved' : 'returned'} by ${v.decider.name} ${fmt(v.decided_at)}`}
                            {v.summary && ` · ${v.summary.applicable} applicable, ${v.summary.implemented} implemented, ${v.summary.flagged} flagged`}
                            {v.decision_notes && <span className="block text-[#718096]">“{v.decision_notes}”</span>}
                        </span>
                        {v.status === 'submitted' && can.approve && (v.prepared_by === me ? <span className="text-[#718096]">Awaiting another approver</span> : (
                            <span className="flex gap-1">
                                <input value={decide.data.notes} onChange={(e) => decide.setData('notes', e.target.value)} placeholder="Notes (needed to return)" className="w-44 rounded border-gray-300 text-xs" />
                                <button type="button" onClick={() => send(v.id, 'approve')} className="rounded bg-[#2D7D46] px-2 text-xs font-semibold text-white">Approve</button>
                                <button type="button" onClick={() => send(v.id, 'return')} className="rounded border border-[#C53030]/40 px-2 text-xs text-[#C53030]">Return</button>
                            </span>
                        ))}
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function IsmsSoa({ rows = [], summary = {}, changes = {}, versions = [], controls = [], implementation = [], can = {}, me }) {
    const [filter, setFilter] = useState('all');
    const [editing, setEditing] = useState(null);
    const shown = useMemo(() => rows.filter((r) => ({
        all: true,
        applicable: r.is_applicable === true,
        excluded: r.is_applicable === false,
        implemented: r.implementation_status === 'implemented',
        flagged: r.flags.length > 0,
        changed: Boolean(changes[r.requirement_code]),
    })[filter]), [rows, filter, changes]);

    return (
        <AuthenticatedLayout header="Statement of Applicability">
            <Head title="SoA — ISO 27001:2022" />
            <PageHeader
                breadcrumbs={[{ label: 'ISMS', href: route('isms.index') }, { label: 'Statement of Applicability' }]}
                title="Statement of Applicability (SoA)"
                subtitle="Applicability, justification and implementation for every Annex A control, set beside the evidence: the controls that implement it and the latest ISO 27001 assessment."
                actions={can.export && (
                    <a href={route('isms.soa.export')} className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#C9A86A] text-[#0A1F44] text-sm font-semibold"><ArrowDownTrayIcon className="w-4 h-4" /> Export SoA (CSV)</a>
                )}
            />
            <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
                <KpiCard label="Annex A controls" value={summary.total || 0} tone="navy" />
                <KpiCard label="Applicable" value={summary.applicable || 0} tone="gold" />
                <KpiCard label="Excluded" value={summary.excluded || 0} tone="white" />
                <KpiCard label="Implemented" value={summary.implemented || 0} sublabel={`${summary.partial || 0} partial`} tone="green" />
                <KpiCard label="Undecided" value={summary.pending || 0} tone={summary.pending ? 'amber' : 'white'} />
                <KpiCard label="Claim ≠ evidence" value={summary.flagged || 0} tone={summary.flagged ? 'red' : 'white'} />
            </div>

            <Versions versions={versions} can={can} me={me} changes={changes} />

            <div className="flex flex-wrap gap-2 mb-4">
                {['all', 'applicable', 'excluded', 'implemented', 'flagged', 'changed'].map((f) => (
                    <button key={f} onClick={() => setFilter(f)} className={`px-3 py-1.5 rounded-lg text-xs capitalize ${filter === f ? 'bg-[#0A1F44] text-white' : 'bg-white border border-gray-200 text-[#2D3748]'}`}>
                        {f === 'changed' ? 'changed since approval' : f}
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Control</th>
                            <th className="px-3 py-2">Decision</th>
                            <th className="px-3 py-2">Justification</th>
                            <th className="px-3 py-2">Implemented by</th>
                            <th className="px-3 py-2">Latest assessment</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {shown.map((r) => (
                            <tr key={r.requirement_id} className="align-top">
                                <td className="px-3 py-2 max-w-xs">
                                    <span className="font-mono text-xs text-[#0A1F44]">{r.requirement_code}</span>
                                    {changes[r.requirement_code] && <span className="ml-1 rounded bg-[#DBEAFE] px-1 text-[10px] text-[#1A365D]">changed: {changes[r.requirement_code]}</span>}
                                    <p className="text-[#2D3748]">{r.title}</p>
                                    {r.flags.map((f) => <p key={f} className="mt-1 flex items-start gap-1 text-[11px] text-[#B3261E]"><ExclamationTriangleIcon className="mt-0.5 h-3 w-3 flex-shrink-0" />{f}</p>)}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {r.is_applicable === null ? <span className="text-xs text-[#8A6400]">Undecided</span> : (
                                        <span className={`rounded-lg px-2 py-0.5 text-xs ${implTone[r.is_applicable ? r.implementation_status : 'not_applicable'] || implTone.planned} whitespace-nowrap`}>
                                            {r.is_applicable ? label(r.implementation_status) : 'excluded'}
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#4A5568] max-w-sm">{r.justification || '—'}
                                    {r.updated_by_name && <span className="block text-[10px] text-[#718096]">{r.updated_by_name} · {fmt(r.updated_at)}</span>}
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    {r.controls.length === 0 ? <span className="text-[#718096]">—</span> : r.controls.slice(0, 4).map((c) => (
                                        <p key={c.id}><span className="font-mono">{c.control_code}</span> <span className={effTone[c.effectiveness] || effTone.not_assessed}>{label(c.effectiveness)}</span></p>
                                    ))}
                                    {r.controls.length > 4 && <p className="text-[#718096]">+{r.controls.length - 4} more</p>}
                                </td>
                                <td className={`px-3 py-2 text-xs ${resultTone[r.assessment_result] || 'text-[#718096]'}`}>{r.assessment_result ? label(r.assessment_result) : 'not assessed'}</td>
                                <td className="px-3 py-2">
                                    {can.edit && <button type="button" onClick={() => setEditing(r)} title="Edit decision" className="text-[#0A1F44]"><PencilSquareIcon className="h-4 w-4" /></button>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {editing && <EditModal row={editing} onClose={() => setEditing(null)} controls={controls} implementation={implementation} />}
        </AuthenticatedLayout>
    );
}

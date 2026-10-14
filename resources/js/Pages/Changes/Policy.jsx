import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import { humanize } from '@/Utils/risk';
import { CHANGE_TYPES, RISK_LEVELS, hoursLabel } from '@/Utils/changes';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const TYPES = CHANGE_TYPES.map((t) => t.key);

function approverLabel(s, boards, users = []) {
    if (s.approver_type === 'board') return boards.find((b) => String(b.id) === String(s.approver_ref))?.name || 'Board';
    if (s.approver_type === 'owner') return 'Change owner';
    if (s.approver_type === 'user') return users.find((u) => String(u.id) === String(s.approver_ref))?.name || `User #${s.approver_ref}`;
    return `${humanize(s.approver_type)}: ${s.approver_ref}`;
}

function StageForm({ form, boards, roles, users = [], keys, approverTypes, onSubmit, submitLabel, onCancel }) {
    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
    const label = 'block text-xs font-medium text-[#4A5568]';
    const toggleType = (t) => form.setData('applies_to_types', form.data.applies_to_types.includes(t) ? form.data.applies_to_types.filter((x) => x !== t) : [...form.data.applies_to_types, t]);
    return (
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(); }} className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="sm:col-span-2"><label className={label}>Stage name</label><input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={field} /><InputError message={form.errors.name} /></div>
                <div><label className={label}>Key</label><select value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} className={field}>{keys.map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</select><InputError message={form.errors.key} /></div>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><label className={label}>Sequence</label><input type="number" min="1" max="255" value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} className={field} /><InputError message={form.errors.sequence} /></div>
                <div><label className={label}>Min risk level</label><select value={form.data.min_risk_level} onChange={(e) => form.setData('min_risk_level', e.target.value)} className={field}>{RISK_LEVELS.map((r) => <option key={r} value={r}>{humanize(r)}</option>)}</select></div>
                <div><label className={label}>SLA (hours)</label><input type="number" min="1" max="720" value={form.data.sla_hours} onChange={(e) => form.setData('sla_hours', e.target.value)} className={field} /><InputError message={form.errors.sla_hours} /></div>
                <label className="flex items-center gap-2 self-end pb-2 text-sm text-[#2D3748]"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="rounded border-gray-300" /> Active</label>
            </div>
            <div>
                <label className={label}>Applies to</label>
                <div className="mt-1 flex gap-2">
                    {TYPES.map((t) => <button key={t} type="button" onClick={() => toggleType(t)} className={`rounded-lg border px-3 py-1 text-xs ${form.data.applies_to_types.includes(t) ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#4A5568]'} whitespace-nowrap`}>{humanize(t)}</button>)}
                </div>
                <InputError message={form.errors.applies_to_types} />
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div><label className={label}>Approver type</label><select value={form.data.approver_type} onChange={(e) => form.setData((d) => ({ ...d, approver_type: e.target.value, approver_ref: '' }))} className={field}>{approverTypes.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select></div>
                <div className="sm:col-span-2">
                    <label className={label}>{form.data.approver_type === 'board' ? 'Board' : form.data.approver_type === 'role' ? 'Role' : form.data.approver_type === 'job_title' ? 'Job title contains' : form.data.approver_type === 'user' ? 'User' : 'Approver'}</label>
                    {form.data.approver_type === 'board' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={field}><option value="">—</option>{boards.map((b) => <option key={b.id} value={b.id}>{b.name} ({b.type.toUpperCase()}, quorum {b.quorum})</option>)}</select>}
                    {form.data.approver_type === 'role' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={field}><option value="">—</option>{roles.map((r) => <option key={r} value={r}>{r}</option>)}</select>}
                    {form.data.approver_type === 'user' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={field}><option value="">—</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` (${u.job_title})` : ''}</option>)}</select>}
                    {form.data.approver_type === 'job_title' && <input value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={field} placeholder="e.g. Head of IT" />}
                    {form.data.approver_type === 'owner' && <p className="mt-2 text-xs text-[#718096]">The change owner named on each change decides this stage.</p>}
                    <InputError message={form.errors.approver_ref} />
                </div>
            </div>
            {form.data.approver_type === 'board' && <div className="w-40"><label className={label}>Quorum override</label><input type="number" min="1" max="20" value={form.data.quorum} onChange={(e) => form.setData('quorum', e.target.value)} className={field} placeholder="board default" /></div>}
            <div className="flex justify-end gap-2 pt-2">
                {onCancel && <button type="button" onClick={onCancel} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button>}
                <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">{submitLabel}</button>
            </div>
        </form>
    );
}

function StageRow({ stage: s, boards, roles, users, keys, approverTypes, canManage }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: s.name, key: s.key, sequence: s.sequence, applies_to_types: s.applies_to_types || [], min_risk_level: s.min_risk_level, approver_type: s.approver_type, approver_ref: s.approver_ref || '', quorum: s.quorum || '', sla_hours: s.sla_hours, is_active: !!s.is_active });
    return (
        <>
            <tr className={`align-top ${!s.is_active ? 'opacity-50' : ''}`}>
                <td className="px-4 py-3 font-mono text-xs text-[#718096]">{s.sequence}</td>
                <td className="px-4 py-3"><p className="font-medium text-[#2D3748]">{s.name}</p><p className="text-[10px] text-[#718096]">{s.key}{s.key === 'change_manager' ? ' · final authority' : ''}</p></td>
                <td className="px-4 py-3 text-xs"><span className="flex flex-wrap gap-1">{(s.applies_to_types || []).map((t) => <StatusBadge key={t} status={t} label={humanize(t)} />)}</span></td>
                <td className="px-4 py-3"><StatusBadge status={s.min_risk_level} label={`${humanize(s.min_risk_level)} and above`} /></td>
                <td className="px-4 py-3 text-xs text-[#2D3748]">{approverLabel(s, boards, users)}<span className="block text-[10px] text-[#718096]">{humanize(s.approver_type)}{s.approver_type === 'board' && s.quorum ? ` · quorum ${s.quorum}` : ''}</span></td>
                <td className="px-4 py-3 text-xs text-[#2D3748]">{hoursLabel(s.sla_hours)}</td>
                <td className="px-4 py-3 text-right text-xs">
                    {canManage && (
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setEditing(!editing)} className="text-[#0A1F44] underline">{editing ? 'Close' : 'Edit'}</button>
                            {s.key !== 'change_manager' && <button type="button" onClick={() => { if (confirm(`Remove stage "${s.name}"?`)) router.delete(route('changes.policy.destroy', s.id), { preserveScroll: true }); }} className="text-[#B3261E] underline">Remove</button>}
                        </div>
                    )}
                </td>
            </tr>
            {editing && (
                <tr><td colSpan={7} className="bg-[#F7FAFC] px-4 py-3">
                    <StageForm form={form} boards={boards} roles={roles} users={users} keys={keys} approverTypes={approverTypes} submitLabel="Save stage" onCancel={() => setEditing(false)}
                        onSubmit={() => form.put(route('changes.policy.update', s.id), { preserveScroll: true, onSuccess: () => setEditing(false) })} />
                </td></tr>
            )}
        </>
    );
}

export default function ChangePolicy({ stages = [], boards = [], roles = [], users = [], matrix = {}, keys = [], approverTypes = [], canManage = false }) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ name: '', key: 'custom', sequence: (Math.max(0, ...stages.map((s) => s.sequence)) || 0) + 10, applies_to_types: ['normal'], min_risk_level: 'low', approver_type: 'role', approver_ref: '', quorum: '', sla_hours: 24, is_active: true });

    return (
        <AuthenticatedLayout header="Approval Policy">
            <Head title="Approval Policy" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: 'Approval Policy' }]}
                title="Change Approval Policy"
                subtitle="The ordered stages a change passes through. A stage applies when the change type is listed and its risk level is at or above the stage's minimum; the Change Manager authorisation stage is always last and cannot be removed."
                actions={canManage && <button type="button" onClick={() => setAdding(true)} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90">Add stage</button>}
            />

            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]"><th className="px-4 py-2">#</th><th className="px-4 py-2">Stage</th><th className="px-4 py-2">Applies to</th><th className="px-4 py-2">Min risk</th><th className="px-4 py-2">Approver</th><th className="px-4 py-2">SLA</th><th className="px-4 py-2" /></tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {stages.map((s) => <StageRow key={s.id} stage={s} boards={boards} roles={roles} users={users} keys={keys} approverTypes={approverTypes} canManage={canManage} />)}
                        {stages.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-xs text-[#718096]">No stages defined.</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <h3 className="mb-1 text-sm font-semibold text-[#2D3748]">Routing matrix</h3>
                <p className="mb-3 text-xs text-[#718096]">What a change of each type and risk level is routed through today. Standard changes are pre-approved unless they fall in a freeze window, which adds the Change Manager stage.</p>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-xs">
                        <thead><tr className="text-left uppercase text-[#718096]"><th className="px-3 py-2">Type \ Risk</th>{RISK_LEVELS.map((r) => <th key={r} className="px-3 py-2"><StatusBadge status={r} label={humanize(r)} /></th>)}</tr></thead>
                        <tbody className="divide-y divide-gray-100">
                            {TYPES.map((t) => (
                                <tr key={t}>
                                    <td className="px-3 py-2"><StatusBadge status={t} label={humanize(t)} /></td>
                                    {RISK_LEVELS.map((r) => {
                                        const list = matrix?.[t]?.[r] || [];
                                        return <td key={r} className="px-3 py-2 align-top text-[#2D3748]">{list.length === 0 ? <span className="text-[#2D7D46]">Approved on submission</span> : list.map((n, i) => <span key={n}>{i > 0 && <span className="mx-1 text-gray-300">→</span>}{n}</span>)}</td>;
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="mt-4 rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#4A5568] shadow-sm">
                <h3 className="mb-1 text-sm font-semibold text-[#2D3748]">How approval works</h3>
                <ul className="list-disc space-y-1 pl-5">
                    <li>Stages are decided in sequence; only the current stage is actionable and later ones are skipped if any stage rejects.</li>
                    <li>Requester, implementer and change owner cannot approve their own change, except the business approval stage which is the owner by design.</li>
                    <li>Board stages are carried by votes from voting members reaching quorum with no rejection; the chair or any approve-changes holder can decide on the board's behalf and the override is logged.</li>
                    <li>The final Change Manager stage always requires the approve changes permission. Policy edits apply to changes submitted afterwards; already-routed approvals keep their snapshot.</li>
                </ul>
            </div>

            <Modal show={adding} onClose={() => setAdding(false)} maxWidth="2xl">
                <div className="p-6">
                    <h2 className="mb-3 text-lg font-semibold text-[#0A1F44]">Add a custom stage</h2>
                    <StageForm form={form} boards={boards} roles={roles} users={users} keys={keys} approverTypes={approverTypes} submitLabel="Add stage" onCancel={() => setAdding(false)}
                        onSubmit={() => form.post(route('changes.policy.store'), { preserveScroll: true, onSuccess: () => { setAdding(false); form.reset(); } })} />
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}

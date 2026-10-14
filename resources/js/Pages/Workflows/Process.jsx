import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { fmtDateTime } from '@/Components/Monitoring/format';

const input = 'mt-1 w-full rounded-lg border-gray-300 text-sm';
const requestTone = { pending: ['warning', 'hourglass_top'], approved: ['success', 'check_circle'], rejected: ['error', 'cancel'], withdrawn: ['muted', 'undo'] };

/** Initiator → stages → done, as a left-to-right flow. */
function Flow({ initiators, steps, end = 'Approved' }) {
    const Box = ({ title, sub, tone = 'neutral', icon }) => (
        <div className="min-w-[150px] max-w-[220px] rounded-lg border border-gray-200 bg-white px-3 py-2">
            <div className="flex items-center gap-1 text-xs font-semibold text-[#0A1F44]">{icon && <Icon name={icon} size={16} />}{title}</div>
            {sub && <p className="mt-0.5 text-[11px] text-[#718096]">{sub}</p>}
        </div>
    );
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Box title="Initiator" sub={initiators?.length ? initiators.join(', ') : 'anyone with the module permission'} icon="send" />
            {steps.map((s, i) => (
                <div key={i} className="flex items-center gap-2"><Icon name="arrow_forward" size={18} className="text-[#A0AEC0]" /><Box title={`${i + 1}. ${s.name}`} sub={s.sub} icon="how_to_reg" /></div>
            ))}
            <Icon name="arrow_forward" size={18} className="text-[#A0AEC0]" />
            <Box title={end} sub={steps.length ? null : 'one approval, module default'} icon="check_circle" />
        </div>
    );
}

function Settings({ process, workflow, roles, canEdit }) {
    const form = useForm({ initiator_roles: workflow?.initiator_roles || [], is_active: workflow ? workflow.is_active : true, notes: workflow?.notes || '' });
    const toggle = (r) => form.setData('initiator_roles', form.data.initiator_roles.includes(r) ? form.data.initiator_roles.filter((x) => x !== r) : [...form.data.initiator_roles, r]);
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put(route('workflows.process.update', process.key), { preserveScroll: true }); }} className="space-y-3 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <h3 className="text-sm font-semibold text-[#2D3748]">Who may start it</h3>
            <p className="text-xs text-[#718096]">Starts at: {process.initiate}. Leave every role unticked to let anyone holding the module's permission start it.</p>
            <div className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                {roles.map((r) => (
                    <label key={r} className="flex items-center gap-2 text-xs text-[#2D3748]">
                        <input type="checkbox" disabled={!canEdit} checked={form.data.initiator_roles.includes(r)} onChange={() => toggle(r)} className="rounded border-gray-300" />{r}
                    </label>
                ))}
            </div>
            {process.engine === 'generic' && (
                <label className="flex items-center gap-2 text-xs text-[#2D3748]"><input type="checkbox" disabled={!canEdit} checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="rounded border-gray-300" />Use the stages below (untick to fall back to the module default)</label>
            )}
            <textarea rows={2} disabled={!canEdit} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Notes, e.g. the board resolution that set this flow" className="w-full rounded-lg border-gray-300 text-xs" />
            {canEdit && <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white">Save</button>}
            {workflow?.editor && <p className="text-[11px] text-[#A0AEC0]">Last changed by {workflow.editor.name}, {fmtDateTime(workflow.updated_at)}</p>}
        </form>
    );
}

function StageForm({ process, stage, roles, users, permissions, approverTypes, onDone }) {
    const form = useForm({
        name: stage?.name || '', approver_type: stage?.approver_type || 'role', approver_ref: stage?.approver_ref || '',
        min_approvals: stage?.min_approvals || 1, sla_hours: stage?.sla_hours || 72,
        condition_field: stage?.condition_field || '', condition_values: stage?.condition_values || [], is_active: stage ? stage.is_active : true,
    });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    const cond = process.conditions.find((c) => c.field === form.data.condition_field);
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };
        stage ? form.put(route('workflows.stages.update', stage.id), opts) : form.post(route('workflows.stages.store', process.key), opts);
    };
    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-2 rounded-lg bg-[#F7FAFC] p-3 md:grid-cols-6">
            <label className="text-[11px] text-[#718096] md:col-span-2">Stage name *<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. CISO approval" className={input} />{err('name')}</label>
            <label className="text-[11px] text-[#718096]">Approved by<select value={form.data.approver_type} onChange={(e) => form.setData({ ...form.data, approver_type: e.target.value, approver_ref: '' })} className={input}>{Object.entries(approverTypes).filter(([k]) => k !== 'owner' || process.owner).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
            <label className="text-[11px] text-[#718096] md:col-span-2">Who
                {form.data.approver_type === 'role' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={input}><option value="">Choose a role…</option>{roles.map((r) => <option key={r}>{r}</option>)}</select>}
                {form.data.approver_type === 'user' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={input}><option value="">Choose a person…</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` — ${u.job_title}` : ''}</option>)}</select>}
                {form.data.approver_type === 'permission' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={input}><option value="">Choose a permission…</option>{permissions.map((p) => <option key={p}>{p}</option>)}</select>}
                {form.data.approver_type === 'job_title' && <input value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} placeholder="e.g. Chief Risk Officer" className={input} />}
                {form.data.approver_type === 'owner' && <p className="mt-2 text-xs text-[#4A5568]">The owner recorded on each record</p>}
                {err('approver_ref')}
            </label>
            <label className="text-[11px] text-[#718096]">Approvals needed<input type="number" min="1" max="10" value={form.data.min_approvals} onChange={(e) => form.setData('min_approvals', e.target.value)} className={input} /></label>
            <label className="text-[11px] text-[#718096]">SLA (hours)<input type="number" min="1" value={form.data.sla_hours} onChange={(e) => form.setData('sla_hours', e.target.value)} className={input} /></label>
            {process.conditions.length > 0 && (
                <label className="text-[11px] text-[#718096] md:col-span-2">Only when<select value={form.data.condition_field} onChange={(e) => form.setData({ ...form.data, condition_field: e.target.value, condition_values: [] })} className={input}><option value="">Always</option>{process.conditions.map((c) => <option key={c.field} value={c.field}>{c.label} is…</option>)}</select></label>
            )}
            {cond && (
                <div className="flex flex-wrap items-end gap-2 text-xs md:col-span-3">
                    {Object.entries(cond.values).map(([k, v]) => (
                        <label key={k} className="flex items-center gap-1"><input type="checkbox" checked={form.data.condition_values.includes(k)} onChange={() => form.setData('condition_values', form.data.condition_values.includes(k) ? form.data.condition_values.filter((x) => x !== k) : [...form.data.condition_values, k])} className="rounded border-gray-300" />{v}</label>
                    ))}
                    {err('condition_values')}
                </div>
            )}
            <div className="flex items-end gap-2 md:col-span-6">
                <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white">{stage ? 'Save stage' : 'Add stage'}</button>
                {onDone && <button type="button" onClick={onDone} className="text-xs text-[#718096]">Cancel</button>}
            </div>
        </form>
    );
}

function GenericStages({ process, stages, rolesWithPermission, canEdit, ...pickers }) {
    const [editing, setEditing] = useState(null);
    const [adding, setAdding] = useState(false);
    return (
        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between"><h3 className="text-sm font-semibold text-[#2D3748]">Approval stages</h3>{canEdit && !adding && <button onClick={() => setAdding(true)} className="inline-flex items-center gap-1 rounded-lg border border-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-[#0A1F44]"><Icon name="add" size={16} />Add stage</button>}</div>
            {stages.length === 0 && !adding && <p className="text-xs text-[#718096]">No stages: {process.default}</p>}
            <ol className="space-y-2">
                {stages.map((s, i) => (
                    <li key={s.id} className="rounded-lg border border-gray-100 p-3">
                        {editing === s.id ? <StageForm process={process} stage={s} {...pickers} onDone={() => setEditing(null)} /> : (
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="text-sm font-semibold text-[#0A1F44]">{i + 1}. {s.name}{!s.is_active && <span className="ml-2 text-xs font-normal text-[#A0AEC0]">(inactive)</span>}</p>
                                    <p className="text-xs text-[#4A5568]">Approved by {s.who}{s.min_approvals > 1 ? ` — ${s.min_approvals} of them` : ''} · SLA {s.sla_hours}h</p>
                                    <div className="mt-1 flex flex-wrap gap-1">
                                        {s.condition_field && <Chip tone="info" icon="filter_alt" size="xs">Only when {s.condition_field.replace(/_/g, ' ')}: {(s.condition_values || []).join(', ')}</Chip>}
                                        {s.approver_type === 'role' && !rolesWithPermission.includes(s.approver_ref) && <Chip tone="warning" icon="warning" size="xs">{s.approver_ref} lacks "{process.permission}"</Chip>}
                                    </div>
                                </div>
                                {canEdit && (
                                    <div className="flex items-center gap-1">
                                        <button title="Move up" disabled={i === 0} onClick={() => router.post(route('workflows.stages.move', s.id), { direction: 'up' }, { preserveScroll: true })} className="rounded p-1 text-[#4A5568] disabled:opacity-30"><Icon name="arrow_upward" size={18} /></button>
                                        <button title="Move down" disabled={i === stages.length - 1} onClick={() => router.post(route('workflows.stages.move', s.id), { direction: 'down' }, { preserveScroll: true })} className="rounded p-1 text-[#4A5568] disabled:opacity-30"><Icon name="arrow_downward" size={18} /></button>
                                        <button title="Edit" onClick={() => setEditing(s.id)} className="rounded p-1 text-[#0A1F44]"><Icon name="edit" size={18} /></button>
                                        <button title="Delete" onClick={() => confirm(`Delete the stage "${s.name}"?`) && router.delete(route('workflows.stages.destroy', s.id), { preserveScroll: true })} className="rounded p-1 text-[#C53030]"><Icon name="delete" size={18} /></button>
                                    </div>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ol>
            {adding && <div className="mt-2"><StageForm process={process} {...pickers} onDone={() => setAdding(false)} /></div>}
            <p className="mt-3 text-[11px] text-[#718096]">Stages run in order. Whoever started the approval never approves it, and nobody decides two stages of the same approval. A role must also hold "{process.permission}" to act.</p>
        </div>
    );
}

function ChangeStages({ changeStages, boards, changeTypes, riskLevels, changeKeys, changeApproverTypes, roles, users, matrix, canChange }) {
    const blank = { name: '', key: 'custom', sequence: (Math.max(0, ...changeStages.map((s) => s.sequence)) + 10), applies_to_types: ['normal'], min_risk_level: 'low', approver_type: 'role', approver_ref: '', quorum: '', sla_hours: 48, is_active: true };
    const [editing, setEditing] = useState(null);
    const form = useForm(blank);
    const start = (s) => { setEditing(s ? s.id : 'new'); form.setData(s ? { ...blank, ...s, approver_ref: s.approver_ref || '', quorum: s.quorum || '' } : blank); };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        editing === 'new' ? form.post(route('changes.policy.store'), opts) : form.put(route('changes.policy.update', editing), opts);
    };
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    const who = (s) => s.approver_type === 'board' ? (boards.find((b) => String(b.id) === String(s.approver_ref))?.name || 'a board') : s.approver_type === 'user' ? (users.find((u) => String(u.id) === String(s.approver_ref))?.name || 'a person') : s.approver_type === 'owner' ? 'the change owner' : `${s.approver_type.replace('_', ' ')}: ${s.approver_ref}`;
    return (
        <div className="space-y-4">
            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <div className="mb-3 flex items-center justify-between"><h3 className="text-sm font-semibold text-[#2D3748]">Change approval stages</h3>{canChange && editing === null && <button onClick={() => start(null)} className="inline-flex items-center gap-1 rounded-lg border border-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-[#0A1F44]"><Icon name="add" size={16} />Add stage</button>}</div>
                <ol className="space-y-2">
                    {changeStages.map((s) => (
                        <li key={s.id} className="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-gray-100 p-3">
                            <div>
                                <p className="text-sm font-semibold text-[#0A1F44]">{s.sequence}. {s.name}{!s.is_active && <span className="ml-2 text-xs font-normal text-[#A0AEC0]">(inactive)</span>}</p>
                                <p className="text-xs text-[#4A5568]">Approved by {who(s)} · SLA {s.sla_hours}h</p>
                                <div className="mt-1 flex flex-wrap gap-1"><Chip tone="info" size="xs" icon="category">{(s.applies_to_types || []).join(', ')} changes</Chip><Chip tone="neutral" size="xs" icon="trending_up">risk {s.min_risk_level}+</Chip></div>
                            </div>
                            {canChange && <div className="flex gap-1"><button title="Edit" onClick={() => start(s)} className="rounded p-1 text-[#0A1F44]"><Icon name="edit" size={18} /></button>{s.key !== 'change_manager' && <button title="Delete" onClick={() => confirm(`Delete "${s.name}"?`) && router.delete(route('changes.policy.destroy', s.id), { preserveScroll: true })} className="rounded p-1 text-[#C53030]"><Icon name="delete" size={18} /></button>}</div>}
                        </li>
                    ))}
                </ol>
                {editing !== null && (
                    <form onSubmit={submit} className="mt-3 grid grid-cols-1 gap-2 rounded-lg bg-[#F7FAFC] p-3 md:grid-cols-6">
                        <label className="text-[11px] text-[#718096] md:col-span-2">Name *<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={input} />{err('name')}</label>
                        <label className="text-[11px] text-[#718096]">Kind<select value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} className={input}>{changeKeys.map((k) => <option key={k}>{k}</option>)}</select></label>
                        <label className="text-[11px] text-[#718096]">Order<input type="number" value={form.data.sequence} onChange={(e) => form.setData('sequence', e.target.value)} className={input} /></label>
                        <label className="text-[11px] text-[#718096]">From risk<select value={form.data.min_risk_level} onChange={(e) => form.setData('min_risk_level', e.target.value)} className={input}>{riskLevels.map((r) => <option key={r}>{r}</option>)}</select></label>
                        <label className="text-[11px] text-[#718096]">SLA (h)<input type="number" value={form.data.sla_hours} onChange={(e) => form.setData('sla_hours', e.target.value)} className={input} /></label>
                        <div className="flex flex-wrap items-center gap-2 text-xs md:col-span-2">{changeTypes.map((t) => <label key={t} className="flex items-center gap-1"><input type="checkbox" checked={form.data.applies_to_types.includes(t)} onChange={() => form.setData('applies_to_types', form.data.applies_to_types.includes(t) ? form.data.applies_to_types.filter((x) => x !== t) : [...form.data.applies_to_types, t])} className="rounded border-gray-300" />{t}</label>)}{err('applies_to_types')}</div>
                        <label className="text-[11px] text-[#718096]">Approved by<select value={form.data.approver_type} onChange={(e) => form.setData({ ...form.data, approver_type: e.target.value, approver_ref: '' })} className={input}>{changeApproverTypes.map((t) => <option key={t}>{t}</option>)}</select></label>
                        <label className="text-[11px] text-[#718096] md:col-span-2">Who
                            {form.data.approver_type === 'role' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={input}><option value="">Choose…</option>{roles.map((r) => <option key={r}>{r}</option>)}</select>}
                            {form.data.approver_type === 'user' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={input}><option value="">Choose…</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>}
                            {form.data.approver_type === 'board' && <select value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={input}><option value="">Choose…</option>{boards.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select>}
                            {form.data.approver_type === 'job_title' && <input value={form.data.approver_ref} onChange={(e) => form.setData('approver_ref', e.target.value)} className={input} />}
                            {form.data.approver_type === 'owner' && <p className="mt-2 text-xs text-[#4A5568]">The change owner</p>}
                            {err('approver_ref')}
                        </label>
                        <label className="flex items-center gap-2 text-xs md:col-span-2"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="rounded border-gray-300" />Active</label>
                        <div className="flex gap-2 md:col-span-6"><button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white">Save stage</button><button type="button" onClick={() => setEditing(null)} className="text-xs text-[#718096]">Cancel</button></div>
                    </form>
                )}
            </div>
            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Route by change type and risk</h3>
                <table className="min-w-full text-xs">
                    <thead><tr className="text-left text-[11px] uppercase text-[#718096]"><th className="py-1 pr-3">Type</th>{riskLevels.map((r) => <th key={r} className="py-1 pr-3">{r}</th>)}</tr></thead>
                    <tbody className="divide-y divide-gray-100">{changeTypes.map((t) => <tr key={t}><td className="py-2 pr-3 font-medium text-[#0A1F44]">{t}</td>{riskLevels.map((r) => <td key={r} className="py-2 pr-3 text-[#4A5568]">{(matrix?.[t]?.[r] || []).map((s) => s.name || s).join(' → ') || 'pre-approved'}</td>)}</tr>)}</tbody>
                </table>
            </div>
        </div>
    );
}

function CsatStages({ csatStages, permissions, canEdit }) {
    const form = useForm({ stages: csatStages });
    const set = (i, k, v) => form.setData('stages', form.data.stages.map((s, j) => (j === i ? { ...s, [k]: v } : s)));
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.put(route('workflows.csat.update'), { preserveScroll: true }); }} className="space-y-2 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <h3 className="text-sm font-semibold text-[#2D3748]">CSAT sign-off stages</h3>
            <p className="text-xs text-[#718096]">Stage 1 is the preparer's submission. Each later stage is signed by a different person holding its permission; signatures are HMAC-sealed.</p>
            {form.data.stages.map((s, i) => (
                <div key={i} className="flex flex-wrap items-end gap-2">
                    <span className="w-6 text-sm font-semibold text-[#0A1F44]">{i + 1}.</span>
                    <input disabled={!canEdit} value={s.name} onChange={(e) => set(i, 'name', e.target.value)} className="min-w-[220px] flex-1 rounded-lg border-gray-300 text-sm" />
                    <select disabled={!canEdit} value={s.permission} onChange={(e) => set(i, 'permission', e.target.value)} className="rounded-lg border-gray-300 text-sm">{[...new Set([s.permission, 'edit csat', ...permissions])].map((p) => <option key={p}>{p}</option>)}</select>
                    {canEdit && form.data.stages.length > 2 && <button type="button" onClick={() => form.setData('stages', form.data.stages.filter((_, j) => j !== i))} className="rounded p-1 text-[#C53030]"><Icon name="delete" size={18} /></button>}
                </div>
            ))}
            {Object.values(form.errors).map((e, i) => <p key={i} className="text-[11px] text-[#C53030]">{e}</p>)}
            {canEdit && <div className="flex gap-2"><button type="button" onClick={() => form.setData('stages', [...form.data.stages, { name: 'Additional sign-off', permission: 'approve csat' }])} className="inline-flex items-center gap-1 rounded-lg border border-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-[#0A1F44]"><Icon name="add" size={16} />Add stage</button><button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white">Save stages</button></div>}
        </form>
    );
}

export default function WorkflowProcess(props) {
    const { process, workflow, roles, rolesWithPermission = [], permissions, users, approverTypes, stages = [], changeStages, csatStages, recent = [], can = {} } = props;
    const flash = usePage().props.flash || {};
    const steps = process.engine === 'change'
        ? (changeStages || []).filter((s) => s.is_active).map((s) => ({ name: s.name, sub: `${(s.applies_to_types || []).join('/')} · risk ${s.min_risk_level}+` }))
        : process.engine === 'csat' ? (csatStages || []).slice(1).map((s) => ({ name: s.name, sub: s.permission }))
            : stages.filter((s) => s.is_active).map((s) => ({ name: s.name, sub: s.who + (s.condition_field ? ' (conditional)' : '') }));
    return (
        <AuthenticatedLayout header="Workflow Studio">
            <Head title={process.label} />
            <PageHeader
                breadcrumbs={[{ label: 'Workflow Studio' }, { label: 'Approval Workflows', href: route('workflows.index') }, { label: process.label }]}
                title={process.label}
                subtitle={`${process.module} · ${process.description}`}
                actions={<Link href={route('workflows.index')} className="text-sm text-[#0A1F44] underline">← All workflows</Link>}
            />
            {flash.error && <p className="mb-3 rounded-lg bg-[#F9DEDC] px-3 py-2 text-xs text-[#8C1D18]">{flash.error}</p>}
            <div className="mb-4 overflow-x-auto rounded-xl border border-gray-100 bg-[#F7FAFC] p-4"><Flow initiators={workflow?.initiator_roles} steps={steps} end={process.engine === 'csat' ? 'Signed off' : 'Approved'} /></div>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {process.engine === 'generic' && <GenericStages process={process} stages={stages} rolesWithPermission={rolesWithPermission} canEdit={can.edit} roles={roles} users={users} permissions={permissions} approverTypes={approverTypes} />}
                    {process.engine === 'change' && <ChangeStages {...props} />}
                    {process.engine === 'csat' && <CsatStages csatStages={csatStages} permissions={permissions} canEdit={can.edit} />}
                    {recent.length > 0 && (
                        <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                            <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Recent approvals</h3>
                            <table className="min-w-full divide-y divide-gray-100 text-sm">
                                <tbody className="divide-y divide-gray-100">
                                    {recent.map((r) => { const [tone, icon] = requestTone[r.status] || ['neutral', null]; return (
                                        <tr key={r.id}><td className="px-4 py-2 text-[#2D3748]">{r.subject_label}</td><td className="px-4 py-2"><Chip tone={tone} icon={icon}>{r.status}</Chip></td><td className="px-4 py-2 text-xs text-[#718096]">started by {r.initiator?.name || '—'}, {fmtDateTime(r.created_at)}</td></tr>
                                    ); })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
                <aside className="space-y-4">
                    <Settings process={process} workflow={workflow} roles={roles} canEdit={can.edit} />
                    <div className="rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#4A5568] shadow-sm space-y-1">
                        <p><b>Permission to act:</b> {process.permission}</p>
                        <p><b>Without stages:</b> {process.default}</p>
                        {process.engine !== 'generic' && <p className="text-[#718096]">This process keeps its own engine; the stages here are its live configuration.</p>}
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

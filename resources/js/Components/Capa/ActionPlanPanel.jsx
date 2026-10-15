import Icon from '@/Components/Material/Icon';
import InputError from '@/Components/InputError';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate } from '@/Utils/risk';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Note, inputCls, labelCls, primaryBtn, secondaryBtn } from './shared';

const STATUS_LABEL = { planned: 'Planned', in_progress: 'In progress', done: 'Done', cancelled: 'Cancelled' };

export default function ActionPlanPanel({ issue, capa, actions = [], owners = [], config, canWork, changeRequests }) {
    const { auth } = usePage().props;
    const [editingId, setEditingId] = useState(null); // 'new' | action id | null
    const form = useForm({ title: '', description: '', owner_id: '', due_date: '', status: 'planned', change_request_id: '' });
    const stage = capa.stage;
    const planOpen = ['identification', 'containment', 'rca', 'action_planning', 'implementation'].includes(stage);
    const working = stage === 'implementation';
    const canDelete = ['identification', 'containment', 'rca', 'action_planning'].includes(stage);
    const mayEdit = (a) => planOpen && (canWork || a.owner_id === auth?.user?.id);
    const statuses = (config.actionStatuses || Object.keys(STATUS_LABEL)).filter((s) => working || ['planned', 'cancelled'].includes(s));

    const begin = (a) => {
        if (changeRequests === undefined) router.reload({ only: ['changeRequests'] });
        form.clearErrors();
        form.setData(a ? {
            title: a.title, description: a.description || '', owner_id: a.owner_id || '', due_date: a.due_date || '',
            status: a.status, change_request_id: a.change_request?.id || '',
        } : { title: '', description: '', owner_id: '', due_date: '', status: 'planned', change_request_id: '' });
        setEditingId(a ? a.id : 'new');
    };
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => setEditingId(null) };
        form.transform((d) => ({ ...d, owner_id: d.owner_id || null, due_date: d.due_date || null, change_request_id: d.change_request_id || null }));
        if (editingId === 'new') form.post(route('issues.capa.actions.store', issue.id), opts);
        else form.put(route('issues.capa.actions.update', [issue.id, editingId]), opts);
    };
    const setStatus = (a, status) => router.put(route('issues.capa.actions.update', [issue.id, a.id]), { status }, { preserveScroll: true });
    const remove = (a) => router.delete(route('issues.capa.actions.destroy', [issue.id, a.id]), { preserveScroll: true });

    const editor = (
        <form onSubmit={submit} className="space-y-3 rounded-lg border border-[#C9A86A]/50 bg-[#C9A86A]/5 p-3">
            <div>
                <label className={labelCls}>Corrective action</label>
                <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={inputCls} />
                <InputError message={form.errors.title} className="mt-1" />
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label className={labelCls}>Owner</label>
                    <select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className={inputCls}>
                        <option value="">Unassigned</option>
                        {owners.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                    <InputError message={form.errors.owner_id} className="mt-1" />
                </div>
                <div>
                    <label className={labelCls}>Due date</label>
                    <input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} className={inputCls} />
                    <InputError message={form.errors.due_date} className="mt-1" />
                </div>
                <div>
                    <label className={labelCls}>Status</label>
                    <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={inputCls}>
                        {statuses.map((s) => <option key={s} value={s}>{STATUS_LABEL[s] || s}</option>)}
                    </select>
                    {!working && <p className="mt-1 text-[10px] text-[#718096]">Work starts once the plan is approved.</p>}
                    <InputError message={form.errors.status} className="mt-1" />
                </div>
                <div>
                    <label className={labelCls}>Change request</label>
                    <select value={form.data.change_request_id} onChange={(e) => form.setData('change_request_id', e.target.value)} className={inputCls}>
                        <option value="">None</option>
                        {(changeRequests || []).map((c) => <option key={c.id} value={c.id}>{c.change_code} · {c.title}</option>)}
                        {/* keep the current link selectable even if it was filtered out of the open list */}
                        {form.data.change_request_id && !(changeRequests || []).some((c) => String(c.id) === String(form.data.change_request_id)) && (
                            <option value={form.data.change_request_id}>Currently linked change</option>
                        )}
                    </select>
                    <InputError message={form.errors.change_request_id} className="mt-1" />
                </div>
            </div>
            <div>
                <label className={labelCls}>Description (optional)</label>
                <textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={2} className={inputCls} />
            </div>
            <InputError message={form.errors.capa} />
            <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setEditingId(null)} className={secondaryBtn}>Cancel</button>
                <button disabled={!form.data.title.trim() || form.processing} className={primaryBtn}>{editingId === 'new' ? 'Add action' : 'Save action'}</button>
            </div>
        </form>
    );

    const overdueCount = actions.filter((a) => a.overdue).length;

    return (
        <Card title="Action plan" icon="checklist"
            aside={canWork && planOpen && editingId === null && <button type="button" onClick={() => begin(null)} className={secondaryBtn}><Icon name="add" size={16} />Add action</button>}>
            {overdueCount > 0 && <div className="mb-3"><Note tone="error" icon="schedule">{overdueCount} action{overdueCount > 1 ? 's are' : ' is'} overdue.</Note></div>}
            {stage === 'action_planning' && <p className="mb-3 text-[11px] text-[#718096]">Any change to the plan withdraws its sign-off.</p>}
            {actions.length === 0 && editingId !== 'new' && <p className="text-xs italic text-[#A0AEC0]">No corrective actions yet. At least one is needed before the plan can be approved.</p>}

            {actions.length > 0 && (
                <div className="-mx-4 overflow-x-auto sm:mx-0">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-[11px] uppercase text-[#718096]">
                                {['Code', 'Action', 'Owner', 'Due', 'Status', 'Change', ''].map((h) => <th key={h} className="px-3 py-2 font-medium">{h}</th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {actions.map((a) => (
                                editingId === a.id ? (
                                    <tr key={a.id}><td colSpan={7} className="px-3 py-2">{editor}</td></tr>
                                ) : (
                                    <tr key={a.id} className={a.overdue ? 'bg-[#B3261E]/5' : ''}>
                                        <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-[#4A5568]">{a.action_code}</td>
                                        <td className="min-w-[180px] px-3 py-2 text-[#2D3748]">
                                            {a.title}
                                            {a.description && <span className="block text-[11px] text-[#718096]">{a.description}</span>}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-xs text-[#4A5568]">{a.owner?.name || <span className="text-[#B3261E]">Unassigned</span>}</td>
                                        <td className={`whitespace-nowrap px-3 py-2 text-xs ${a.overdue ? 'font-semibold text-[#B3261E]' : 'text-[#4A5568]'}`}>
                                            {a.due_date ? formatDate(a.due_date) : '—'}{a.overdue && <span className="block text-[10px]">Overdue</span>}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2"><StatusBadge status={a.status} label={STATUS_LABEL[a.status]} /></td>
                                        <td className="whitespace-nowrap px-3 py-2 text-xs">
                                            {a.change_request
                                                ? <Link href={a.change_request.url} className="font-mono text-[#0A1F44] underline" title={a.change_request.title}>{a.change_request.code}</Link>
                                                : <span className="text-[#A0AEC0]">—</span>}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right">
                                            {mayEdit(a) && (
                                                <span className="inline-flex items-center gap-0.5">
                                                    {working && ['planned', 'in_progress'].includes(a.status) && (
                                                        <button type="button" onClick={() => setStatus(a, a.status === 'planned' ? 'in_progress' : 'done')} className="rounded-lg px-2 py-1 text-xs text-[#0A1F44] hover:bg-[#0A1F44]/5">
                                                            {a.status === 'planned' ? 'Start' : 'Mark done'}
                                                        </button>
                                                    )}
                                                    <button type="button" onClick={() => begin(a)} aria-label={`Edit ${a.action_code}`} className="rounded-lg p-1 text-[#0A1F44] hover:bg-[#0A1F44]/5"><Icon name="edit" size={18} /></button>
                                                    {canWork && (canDelete
                                                        ? <button type="button" onClick={() => remove(a)} aria-label={`Delete ${a.action_code}`} className="rounded-lg p-1 text-[#B3261E] hover:bg-[#B3261E]/10"><Icon name="delete" size={18} /></button>
                                                        : a.status !== 'cancelled' && a.status !== 'done' && <button type="button" onClick={() => setStatus(a, 'cancelled')} aria-label={`Cancel ${a.action_code}`} className="rounded-lg p-1 text-[#B3261E] hover:bg-[#B3261E]/10"><Icon name="block" size={18} /></button>)}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                )
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {editingId === 'new' && <div className="mt-3">{editor}</div>}
        </Card>
    );
}

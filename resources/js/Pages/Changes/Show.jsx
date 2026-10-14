import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import RiskMatrix from '@/Components/Changes/RiskMatrix';
import { formatDate, humanize } from '@/Utils/risk';
import {
    APPROVAL_COLOUR, EVENT_DOT, IMPACT_LABELS, LIKELIHOOD_LABELS, PIR_OUTCOMES, PRIORITIES, SIDE_STATUSES, STATUS_STEPS,
    TASK_TYPES, VOTE_COLOUR, relativeDue, toDateTimeInput, windowLabel,
} from '@/Utils/changes';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const ACTION_LABEL = {
    submit: ['Submit for approval', 'primary'], revise: ['Revise (back to draft)', 'primary'], start: ['Start implementation', 'primary'],
    complete: ['Mark implemented', 'primary'], back_out: ['Backed out', 'danger'], fail: ['Failed', 'danger'], cancel: ['Cancel change', 'secondary'],
};
const NOTE_REQUIRED = ['revise', 'back_out', 'fail', 'cancel'];

export default function ChangeShow({
    change: c, source = {}, affectedAssets = [], conflicts = [], freeze = null, currentApproval = null,
    canWork = false, canDecide = false, canVote = false, myVote = null, canApprove = false, canStart = false, canReview = false,
    owners = [], assets = [], boardsMembers = [], voteTally = null, transitions = {}, categories = [], sources = {},
}) {
    const { errors = {}, auth } = usePage().props;
    const [editing, setEditing] = useState(false);
    const [note, setNote] = useState('');
    const [emergencyReason, setEmergencyReason] = useState('');
    const [addingTask, setAddingTask] = useState(false);
    const [assetQuery, setAssetQuery] = useState('');

    const locked = ['closed', 'cancelled'].includes(c.status);
    const stepIndex = STATUS_STEPS.indexOf(c.status === 'submitted' ? 'in_approval' : c.status);
    const sideStatus = SIDE_STATUSES.includes(c.status) ? c.status : null;
    const approvals = c.approvals || [];
    const current = approvals.find((a) => a.id === currentApproval) || null;
    const isBoardStage = current?.approver_type === 'board';

    const comment = useForm({ note: '' });
    const decide = useForm({ decision: 'approve', note: '' });
    const vote = useForm({ vote: myVote || 'approve', comment: '' });
    const schedule = useForm({ scheduled_start_at: toDateTimeInput(c.scheduled_start_at), scheduled_end_at: toDateTimeInput(c.scheduled_end_at), freeze_override_reason: '' });
    const pir = useForm({ action: 'review', pir_outcome: c.status === 'backed_out' ? 'backed_out' : 'successful', pir_summary: '' });
    const task = useForm({ title: '', type: 'implementation', assignee_id: '', due_at: '' });
    const edit = useForm({
        title: c.title || '', description: c.description || '', justification: c.justification || '', category: c.category, priority: c.priority,
        impact: c.impact || 3, likelihood: c.likelihood || 3, impact_assessment: c.impact_assessment || '', implementation_plan: c.implementation_plan || '',
        backout_plan: c.backout_plan || '', test_plan: c.test_plan || '', communication_plan: c.communication_plan || '',
        outage_required: !!c.outage_required, expected_downtime_minutes: c.expected_downtime_minutes ?? '',
        affected_asset_ids: c.affected_asset_ids || [], affected_services: c.affected_services || [], source_reference: c.source_reference || '',
        change_owner_id: c.change_owner_id || '', implementer_id: c.implementer_id || '', change_manager_id: c.change_manager_id || '',
    });

    const act = (action) => {
        const payload = { action, note };
        if (action === 'start' && c.status === 'in_approval') payload.emergency_start_reason = emergencyReason;
        router.post(route('changes.transition', c.id), payload, { preserveScroll: true, onSuccess: () => { setNote(''); setEmergencyReason(''); } });
    };
    const submitDecision = (decision) => {
        decide.transform((data) => ({ ...data, decision }));
        decide.post(route('changes.decide', c.id), { preserveScroll: true, onSuccess: () => decide.reset() });
    };
    const toggleTask = (t) => router.put(route('changes.tasks.update', [c.id, t.id]), { status: t.status === 'done' ? 'pending' : 'done' }, { preserveScroll: true });

    // Next-step buttons from the workflow's transition table.
    const actions = Object.entries(transitions)
        .filter(([action, from]) => from.includes(c.status) && !['decide', 'vote', 'schedule', 'review', 'reopen'].includes(action))
        .filter(([action]) => (action === 'start' ? canStart : canWork || (action === 'cancel' && canApprove)))
        .filter(([action]) => !(action === 'start' && c.status === 'approved' && c.change_type !== 'emergency'))
        .map(([action]) => action);
    const emergencyStart = actions.includes('start') && c.status === 'in_approval';
    const canSchedule = ['approved', 'scheduled'].includes(c.status) && (canWork || canApprove);

    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
    const label = 'block text-xs font-medium text-[#4A5568]';
    const doneTasks = (c.tasks || []).filter((t) => ['done', 'skipped'].includes(t.status)).length;
    const filteredAssets = assets.filter((a) => !assetQuery || `${a.name} ${a.asset_id_code || ''}`.toLowerCase().includes(assetQuery.toLowerCase())).slice(0, 40);

    return (
        <AuthenticatedLayout header="Change Register">
            <Head title={c.change_code || 'Change'} />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: c.change_code }]}
                title={c.title}
                subtitle={`${c.change_code} · ${humanize(c.change_type)} change · ${humanize(c.category)} · raised ${formatDate(c.created_at)}${c.requester ? ` by ${c.requester.name}` : ''}`}
                actions={canWork && !locked && (
                    <button type="button" onClick={() => setEditing(!editing)} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44]">{editing ? 'Cancel edit' : 'Edit'}</button>
                )}
            />

            {/* Status stepper */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {STATUS_STEPS.map((s, idx) => (
                    <div key={s} className="flex items-center gap-2">
                        <span className={`rounded-lg px-3 py-1 text-xs ${sideStatus ? 'bg-gray-100 text-[#A0AEC0]' : idx < stepIndex ? 'bg-[#2D7D46]/10 text-[#2D7D46]' : idx === stepIndex ? 'bg-[#0A1F44] text-white' : 'bg-gray-100 text-[#718096]'} whitespace-nowrap`}>
                            {s === 'in_approval' ? 'In approval' : humanize(s)}
                        </span>
                        {idx < STATUS_STEPS.length - 1 && <span className="text-gray-300">→</span>}
                    </div>
                ))}
                {sideStatus && <span className="rounded-lg bg-[#B3261E]/10 px-3 py-1 text-xs font-semibold text-[#B3261E] whitespace-nowrap">{humanize(sideStatus)}{['backed_out', 'failed'].includes(sideStatus) ? ' · awaiting PIR' : ''}</span>}
                {c.started_before_approval && <span className="rounded-lg bg-[#E5A100]/10 px-3 py-1 text-xs font-semibold text-[#8A6400] whitespace-nowrap">Started before approval (retrospective authorisation)</span>}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {/* Approval tracker */}
                    {approvals.length > 0 && (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="mb-3 text-sm font-semibold text-[#2D3748]">Approval stages</h3>
                            <ol className="space-y-2">
                                {approvals.map((a, idx) => {
                                    const isCurrent = a.id === currentApproval;
                                    const approver = a.approver_type === 'board' ? (a.board?.name || 'Board') : a.assignee?.name || (a.approver_type === 'owner' ? 'Change owner' : a.approver_ref ? `${humanize(a.approver_type)}: ${a.approver_ref}` : humanize(a.approver_type));
                                    return (
                                        <li key={a.id} className={`rounded-lg border p-3 ${isCurrent ? 'border-[#C9A86A] bg-[#C9A86A]/5' : 'border-gray-100'}`}>
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div className="flex items-start gap-3">
                                                    <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white" style={{ backgroundColor: APPROVAL_COLOUR[a.status] || '#718096' }}>{idx + 1}</span>
                                                    <div>
                                                        <p className="text-sm font-medium text-[#2D3748]">{a.stage_name}{isCurrent && <span className="ml-2 rounded bg-[#C9A86A] px-1.5 py-0.5 text-[10px] font-semibold uppercase text-[#0A1F44]">Current</span>}</p>
                                                        <p className="text-[11px] text-[#718096]">{approver}{a.due_at && a.status === 'pending' ? ` · ${relativeDue(a.due_at)} (${formatDate(a.due_at, true)})` : ''}</p>
                                                        {a.decided_at && <p className="text-[11px] text-[#718096]">{humanize(a.status)} {formatDate(a.decided_at, true)}{a.decided_by ? ` by ${a.decided_by.name}` : ''}</p>}
                                                        {a.decision_note && <p className="mt-1 text-xs italic text-[#4A5568]">“{a.decision_note}”</p>}
                                                    </div>
                                                </div>
                                                <StatusBadge status={a.status} label={a.status === 'pending' && a.due_at && new Date(a.due_at) < Date.now() ? 'Overdue' : humanize(a.status)} />
                                            </div>

                                            {isCurrent && a.approver_type === 'board' && (
                                                <BoardPanel members={boardsMembers} tally={voteTally} canVote={canVote} myVote={myVote} vote={vote} changeId={c.id} />
                                            )}

                                            {isCurrent && canDecide && (
                                                <form onSubmit={(e) => e.preventDefault()} className="mt-3 space-y-2 border-t border-[#C9A86A]/30 pt-3">
                                                    <textarea value={decide.data.note} onChange={(e) => decide.setData('note', e.target.value)} rows={2} placeholder="Decision note (required to reject)" className="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs" />
                                                    <InputError message={decide.errors.note || decide.errors.decision || errors.decision} />
                                                    <div className="flex gap-2">
                                                        <button type="button" disabled={decide.processing} onClick={() => submitDecision('approve')} className="rounded-lg bg-[#2D7D46] px-3 py-1.5 text-xs text-white disabled:opacity-50">Approve stage</button>
                                                        <button type="button" disabled={decide.processing} onClick={() => submitDecision('reject')} className="rounded-lg border border-[#B3261E]/40 px-3 py-1.5 text-xs text-[#B3261E] disabled:opacity-50">Reject change</button>
                                                        {a.approver_type === 'board' && <span className="self-center text-[10px] text-[#718096]">Chair / authoriser override — logged.</span>}
                                                    </div>
                                                </form>
                                            )}
                                        </li>
                                    );
                                })}
                            </ol>
                        </div>
                    )}

                    {/* Details */}
                    {editing ? (
                        <form onSubmit={(e) => { e.preventDefault(); edit.put(route('changes.update', c.id), { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Edit change</h3>
                            <div>
                                <label className={label}>Title</label>
                                <input value={edit.data.title} onChange={(e) => edit.setData('title', e.target.value)} className={field} />
                                <InputError message={edit.errors.title} className="mt-1" />
                            </div>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div>
                                    <label className={label}>Category</label>
                                    <select value={edit.data.category} onChange={(e) => edit.setData('category', e.target.value)} className={field}>{categories.map((x) => <option key={x} value={x}>{humanize(x)}</option>)}</select>
                                </div>
                                <div>
                                    <label className={label}>Priority</label>
                                    <select value={edit.data.priority} onChange={(e) => edit.setData('priority', e.target.value)} className={field}>{PRIORITIES.map((x) => <option key={x} value={x}>{humanize(x)}</option>)}</select>
                                </div>
                                <div>
                                    <label className={label}>Impact</label>
                                    <select value={edit.data.impact} onChange={(e) => edit.setData('impact', Number(e.target.value))} className={field}>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} · {IMPACT_LABELS[n]}</option>)}</select>
                                </div>
                                <div>
                                    <label className={label}>Likelihood</label>
                                    <select value={edit.data.likelihood} onChange={(e) => edit.setData('likelihood', Number(e.target.value))} className={field}>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} · {LIKELIHOOD_LABELS[n]}</option>)}</select>
                                </div>
                            </div>
                            {c.status !== 'draft' && <p className="text-[11px] text-[#8A6400]">Risk is only re-scored while the change is a draft; later edits are logged but keep the routed approvals.</p>}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                {[['change_owner_id', 'Change owner'], ['implementer_id', 'Implementer'], ['change_manager_id', 'Change manager']].map(([k, l]) => (
                                    <div key={k}>
                                        <label className={label}>{l}</label>
                                        <select value={edit.data[k]} onChange={(e) => edit.setData(k, e.target.value)} className={field}>
                                            <option value="">—</option>
                                            {owners.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                        </select>
                                        <InputError message={edit.errors[k]} className="mt-1" />
                                    </div>
                                ))}
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <label className="flex items-center gap-2 pt-5 text-sm text-[#2D3748]"><input type="checkbox" checked={edit.data.outage_required} onChange={(e) => edit.setData('outage_required', e.target.checked)} className="rounded border-gray-300" /> Outage required</label>
                                <div>
                                    <label className={label}>Expected downtime (min)</label>
                                    <input type="number" min="0" value={edit.data.expected_downtime_minutes} onChange={(e) => edit.setData('expected_downtime_minutes', e.target.value)} className={field} />
                                </div>
                                <div>
                                    <label className={label}>Source reference</label>
                                    <input value={edit.data.source_reference} onChange={(e) => edit.setData('source_reference', e.target.value)} className={field} />
                                </div>
                            </div>
                            {[['description', 'Description'], ['justification', 'Justification'], ['impact_assessment', 'Impact assessment'], ['implementation_plan', 'Implementation plan'], ['backout_plan', 'Back-out plan'], ['test_plan', 'Test plan'], ['communication_plan', 'Communication plan']].map(([k, l]) => (
                                <div key={k}>
                                    <label className={label}>{l}</label>
                                    <textarea value={edit.data[k]} onChange={(e) => edit.setData(k, e.target.value)} rows={3} className={field} />
                                    <InputError message={edit.errors[k]} className="mt-1" />
                                </div>
                            ))}
                            <div>
                                <label className={label}>Affected assets ({edit.data.affected_asset_ids.length})</label>
                                <input value={assetQuery} onChange={(e) => setAssetQuery(e.target.value)} placeholder="Search assets…" className={field} />
                                <div className="mt-2 max-h-40 overflow-y-auto rounded-lg border border-gray-100">
                                    {filteredAssets.map((a) => (
                                        <label key={a.id} className="flex cursor-pointer items-center gap-2 border-b border-gray-50 px-3 py-1.5 text-xs hover:bg-gray-50">
                                            <input type="checkbox" checked={edit.data.affected_asset_ids.includes(a.id)} onChange={() => edit.setData('affected_asset_ids', edit.data.affected_asset_ids.includes(a.id) ? edit.data.affected_asset_ids.filter((x) => x !== a.id) : [...edit.data.affected_asset_ids, a.id])} className="rounded border-gray-300" />
                                            <span className="flex-1">{a.name} <span className="font-mono text-[10px] text-[#718096]">{a.asset_id_code}</span></span>
                                            {a.criticality && <StatusBadge status={a.criticality} label={humanize(a.criticality)} />}
                                        </label>
                                    ))}
                                </div>
                            </div>
                            <div>
                                <label className={label}>Affected services (comma separated)</label>
                                <input value={edit.data.affected_services.join(', ')} onChange={(e) => edit.setData('affected_services', e.target.value.split(',').map((s) => s.trim()).filter(Boolean))} className={field} />
                            </div>
                            <button disabled={edit.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Save</button>
                        </form>
                    ) : (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                            {[['Description', c.description], ['Justification', c.justification], ['Impact assessment', c.impact_assessment], ['Implementation plan', c.implementation_plan], ['Back-out plan', c.backout_plan], ['Test plan', c.test_plan], ['Communication plan', c.communication_plan]].map(([l, text]) => (
                                <div key={l} className="mb-4 last:mb-0">
                                    <h3 className="text-xs font-semibold uppercase text-[#718096]">{l}</h3>
                                    <p className={`mt-1 whitespace-pre-line ${text ? 'text-[#2D3748]' : 'italic text-[#A0AEC0]'}`}>{text || 'Not recorded yet.'}</p>
                                </div>
                            ))}
                            {c.template && <p className="mt-3 text-[11px] text-[#718096]">From standard template: <Link href={route('changes.templates')} className="underline">{c.template.name}</Link></p>}
                            {c.freeze_override_reason && <p className="mt-3 rounded-lg bg-[#B3261E]/5 p-3 text-xs text-[#B3261E]"><strong>Freeze override:</strong> {c.freeze_override_reason}</p>}
                            {c.emergency_start_reason && <p className="mt-3 rounded-lg bg-[#E5A100]/10 p-3 text-xs text-[#8A6400]"><strong>Started before approval because:</strong> {c.emergency_start_reason}</p>}
                        </div>
                    )}

                    {/* Schedule */}
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div className="mb-2 flex items-start justify-between">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Schedule</h3>
                            <Link href={route('changes.calendar', c.scheduled_start_at ? { from: String(c.scheduled_start_at).slice(0, 10) } : {})} className="text-xs text-[#0A1F44] underline">Calendar →</Link>
                        </div>
                        <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                            <div><dt className="text-xs text-[#718096]">Planned window</dt><dd className="text-[#2D3748]">{windowLabel(c.scheduled_start_at, c.scheduled_end_at)}</dd></div>
                            <div><dt className="text-xs text-[#718096]">Actual</dt><dd className="text-[#2D3748]">{c.actual_start_at ? windowLabel(c.actual_start_at, c.actual_end_at) : '—'}</dd></div>
                            <div><dt className="text-xs text-[#718096]">Outage</dt><dd className="text-[#2D3748]">{c.outage_required ? `Yes${c.expected_downtime_minutes ? ` · ${c.expected_downtime_minutes} min expected` : ''}` : 'No'}</dd></div>
                            <div><dt className="text-xs text-[#718096]">CAB</dt><dd className="text-[#2D3748]">{c.cab_required ? 'Required' : 'Not required'}{(c.cab_items || []).length > 0 && c.cab_items.map((it) => it.meeting && <Link key={it.id} href={route('changes.cab.meetings.show', it.meeting.id)} className="ml-2 text-xs text-[#0A1F44] underline">{it.meeting.title}</Link>)}</dd></div>
                        </dl>
                        {freeze && (
                            <p className="mt-3 rounded-lg bg-[#B3261E]/5 p-3 text-xs text-[#B3261E]"><strong>Freeze window:</strong> the window falls inside “{freeze.name}” ({formatDate(freeze.starts_at)} – {formatDate(freeze.ends_at)}).{c.change_type === 'emergency' && freeze.allow_emergency ? ' Emergency changes are allowed through this freeze.' : ' An override reason is required to schedule it.'}</p>
                        )}
                        {conflicts.length > 0 && (
                            <div className="mt-3 rounded-lg bg-[#E5A100]/10 p-3 text-xs text-[#8A6400]">
                                <p className="font-semibold">Conflicts with {conflicts.length} other change{conflicts.length > 1 ? 's' : ''}:</p>
                                <ul className="mt-1 space-y-1">
                                    {conflicts.map((k) => <li key={k.id}><Link href={route('changes.show', k.id)} className="font-mono underline">{k.change_code}</Link> {k.title} · {windowLabel(k.scheduled_start_at, k.scheduled_end_at)} — {k.reason}</li>)}
                                </ul>
                            </div>
                        )}
                        {canSchedule && (
                            <form onSubmit={(e) => { e.preventDefault(); schedule.post(route('changes.schedule', c.id), { preserveScroll: true }); }} className="mt-3 grid grid-cols-1 gap-3 border-t border-gray-100 pt-3 sm:grid-cols-3">
                                <div>
                                    <label className={label}>Window start</label>
                                    <input type="datetime-local" value={schedule.data.scheduled_start_at} onChange={(e) => schedule.setData('scheduled_start_at', e.target.value)} className={field} />
                                    <InputError message={schedule.errors.scheduled_start_at} className="mt-1" />
                                </div>
                                <div>
                                    <label className={label}>Window end</label>
                                    <input type="datetime-local" value={schedule.data.scheduled_end_at} onChange={(e) => schedule.setData('scheduled_end_at', e.target.value)} className={field} />
                                    <InputError message={schedule.errors.scheduled_end_at} className="mt-1" />
                                </div>
                                <div className="flex items-end">
                                    <button disabled={schedule.processing} className="w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">{c.status === 'scheduled' ? 'Reschedule' : 'Schedule'}</button>
                                </div>
                                {(freeze || schedule.errors.freeze_override_reason || errors.freeze_override_reason) && (
                                    <div className="sm:col-span-3">
                                        <label className={label}>Freeze override reason</label>
                                        <textarea value={schedule.data.freeze_override_reason} onChange={(e) => schedule.setData('freeze_override_reason', e.target.value)} rows={2} className={field} />
                                    </div>
                                )}
                                <div className="sm:col-span-3"><InputError message={schedule.errors.freeze_override_reason || schedule.errors.action || schedule.errors.note || errors.scheduled_start_at} /></div>
                            </form>
                        )}
                    </div>

                    {/* Tasks */}
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Implementation checklist <span className="ml-1 text-xs font-normal text-[#718096]">{doneTasks}/{(c.tasks || []).length} done</span></h3>
                            {canWork && !locked && <button type="button" onClick={() => setAddingTask(!addingTask)} className="text-xs text-[#0A1F44] underline">{addingTask ? 'Close' : 'Add task'}</button>}
                        </div>
                        {addingTask && (
                            <form onSubmit={(e) => { e.preventDefault(); task.post(route('changes.tasks.store', c.id), { preserveScroll: true, onSuccess: () => { task.reset(); setAddingTask(false); } }); }} className="mb-3 grid grid-cols-1 gap-2 rounded-lg bg-[#F7FAFC] p-3 sm:grid-cols-4">
                                <div className="sm:col-span-2">
                                    <input value={task.data.title} onChange={(e) => task.setData('title', e.target.value)} placeholder="Task" className="w-full rounded-lg border border-gray-200 px-3 py-1.5 text-xs" />
                                    <InputError message={task.errors.title} className="mt-1" />
                                </div>
                                <select value={task.data.type} onChange={(e) => task.setData('type', e.target.value)} className="rounded-lg border border-gray-200 py-1.5 text-xs">{TASK_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select>
                                <select value={task.data.assignee_id} onChange={(e) => task.setData('assignee_id', e.target.value)} className="rounded-lg border border-gray-200 py-1.5 text-xs"><option value="">Unassigned</option>{owners.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
                                <input type="datetime-local" value={task.data.due_at} onChange={(e) => task.setData('due_at', e.target.value)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs sm:col-span-2" />
                                <button disabled={task.processing || !task.data.title} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white disabled:opacity-50 sm:col-span-2">Add</button>
                            </form>
                        )}
                        <ul className="divide-y divide-gray-100 text-sm">
                            {(c.tasks || []).length === 0 && <li className="py-2 text-xs text-[#718096]">No tasks yet.</li>}
                            {(c.tasks || []).map((t) => (
                                <li key={t.id} className="flex items-center gap-3 py-2">
                                    <input type="checkbox" checked={['done', 'skipped'].includes(t.status)} disabled={locked || !(canWork || t.assignee_id === auth?.user?.id)} onChange={() => toggleTask(t)} className="rounded border-gray-300" />
                                    <div className="flex-1">
                                        <p className={`text-[#2D3748] ${['done', 'skipped'].includes(t.status) ? 'line-through opacity-60' : ''}`}>{t.title}</p>
                                        <p className="text-[11px] text-[#718096]">{humanize(t.type)}{t.assignee ? ` · ${t.assignee.name}` : ''}{t.due_at ? ` · due ${formatDate(t.due_at, true)}` : ''}{t.completed_at ? ` · ${humanize(t.status)} ${formatDate(t.completed_at, true)}` : ''}</p>
                                    </div>
                                    <StatusBadge status={t.status} label={humanize(t.status)} />
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* PIR */}
                    {(c.pir_required || c.pir_outcome) && (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Post-implementation review</h3>
                            {c.pir_outcome ? (
                                <div className="text-sm">
                                    <p><StatusBadge status={c.pir_outcome.startsWith('successful') ? 'approved' : 'failed'} label={humanize(c.pir_outcome)} /> <span className="ml-2 text-xs text-[#718096]">{formatDate(c.reviewed_at, true)}{c.pir_by ? ` by ${c.pir_by.name}` : ''}</span></p>
                                    <p className="mt-2 whitespace-pre-line text-[#2D3748]">{c.pir_summary || '—'}</p>
                                </div>
                            ) : c.status === 'review' && canReview ? (
                                <form onSubmit={(e) => { e.preventDefault(); pir.post(route('changes.transition', c.id), { preserveScroll: true }); }} className="space-y-2">
                                    <div>
                                        <label className={label}>Outcome</label>
                                        <select value={pir.data.pir_outcome} onChange={(e) => pir.setData('pir_outcome', e.target.value)} className={field}>{PIR_OUTCOMES.map((o) => <option key={o} value={o}>{humanize(o)}</option>)}</select>
                                    </div>
                                    <div>
                                        <label className={label}>Summary</label>
                                        <textarea value={pir.data.pir_summary} onChange={(e) => pir.setData('pir_summary', e.target.value)} rows={3} className={field} placeholder="Did it deliver the intended outcome? Incidents caused, lessons, follow-up actions." />
                                    </div>
                                    <InputError message={pir.errors.pir_summary || pir.errors.pir_outcome || pir.errors.action || pir.errors.note} />
                                    <button disabled={pir.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Record PIR and close</button>
                                </form>
                            ) : (
                                <p className="text-xs text-[#718096]">{c.status === 'review' ? 'Awaiting review by someone holding approve changes who is not the implementer.' : 'A PIR is required before this change can be closed.'}</p>
                            )}
                        </div>
                    )}

                    {/* Timeline */}
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="mb-3 text-sm font-semibold text-[#2D3748]">Timeline</h3>
                        <form onSubmit={(e) => { e.preventDefault(); comment.post(route('changes.comment', c.id), { preserveScroll: true, onSuccess: () => comment.reset() }); }} className="mb-4 flex gap-2">
                            <input value={comment.data.note} onChange={(e) => comment.setData('note', e.target.value)} placeholder="Add a comment…" className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                            <button disabled={!comment.data.note || comment.processing} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-xs text-[#0A1F44] disabled:opacity-50">Comment</button>
                        </form>
                        <ol className="space-y-3">
                            {(c.events || []).map((e) => (
                                <li key={e.id} className="flex gap-3 text-sm">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: EVENT_DOT[e.event_type] || '#A0AEC0' }} />
                                    <div>
                                        <p className="text-[#2D3748]">{e.description || humanize(e.event_type)}</p>
                                        <p className="text-[11px] text-[#718096]">{formatDate(e.created_at, true)} · {e.actor?.name || 'System'} · {humanize(e.event_type)}</p>
                                    </div>
                                </li>
                            ))}
                            {(c.events || []).length === 0 && <li className="text-xs text-[#718096]">No activity yet.</li>}
                        </ol>
                    </div>
                </div>

                {/* Side panel */}
                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Risk</h3>
                            <StatusBadge status={c.risk_level} label={`${humanize(c.risk_level)} · ${c.risk_score}`} />
                        </div>
                        <RiskMatrix impact={c.impact} likelihood={c.likelihood} size="h-5" />
                        <dl className="mt-3 space-y-2">
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Status</dt><dd><StatusBadge status={c.status} label={humanize(c.status)} /></dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Type</dt><dd><StatusBadge status={c.change_type} label={humanize(c.change_type)} /></dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Category</dt><dd className="text-[#2D3748]">{humanize(c.category)}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Priority</dt><dd><StatusBadge status={c.priority} label={humanize(c.priority)} /></dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Impact × likelihood</dt><dd className="text-[#2D3748]">{c.impact} × {c.likelihood}</dd></div>
                            {c.pir_required && <div className="flex justify-between gap-2"><dt className="text-[#718096]">PIR</dt><dd className="text-[#2D3748]">Required</dd></div>}
                            {c.submitted_at && <div className="flex justify-between gap-2"><dt className="text-[#718096]">Submitted</dt><dd className="text-[#2D3748]">{formatDate(c.submitted_at, true)}</dd></div>}
                            {c.approved_at && <div className="flex justify-between gap-2"><dt className="text-[#718096]">Approved</dt><dd className="text-right text-[#2D3748]">{formatDate(c.approved_at, true)}{c.approved_by ? <span className="block text-[11px] text-[#718096]">{c.approved_by.name}</span> : null}</dd></div>}
                            {c.closed_at && <div className="flex justify-between gap-2"><dt className="text-[#718096]">Closed</dt><dd className="text-right text-[#2D3748]">{formatDate(c.closed_at, true)}<span className="block text-[11px] text-[#718096]">{humanize(c.closure_code)}</span></dd></div>}
                        </dl>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">People</h3>
                        <dl className="space-y-2">
                            {[['Requester', c.requester], ['Change owner', c.owner], ['Implementer', c.implementer], ['Change manager', c.change_manager]].map(([l, u]) => (
                                <div key={l} className="flex justify-between gap-2"><dt className="text-[#718096]">{l}</dt><dd className="text-right text-[#2D3748]">{u ? <>{u.name}{u.job_title && <span className="block text-[11px] text-[#718096]">{u.job_title}</span>}</> : <span className="text-[#B3261E]">—</span>}</dd></div>
                            ))}
                        </dl>
                    </div>

                    {/* Next step */}
                    {(actions.length > 0 || emergencyStart) && !locked && (
                        <div className="space-y-2 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Next step</h3>
                            <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} placeholder={`Note${actions.some((a) => NOTE_REQUIRED.includes(a)) ? ' (required to revise, cancel, back out or fail)' : ''}`} className="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs" />
                            {emergencyStart && (
                                <div>
                                    <label className={label}>Reason for starting before approval</label>
                                    <textarea value={emergencyReason} onChange={(e) => setEmergencyReason(e.target.value)} rows={2} placeholder="Why the emergency cannot wait for ECAB — approvals must still complete within 24 h." className="mt-1 w-full rounded-lg border border-[#B3261E]/30 px-3 py-2 text-xs" />
                                    <InputError message={errors.emergency_start_reason} className="mt-1" />
                                </div>
                            )}
                            <InputError message={errors.note || errors.action} />
                            {actions.map((action) => {
                                const [l, kind] = ACTION_LABEL[action] || [humanize(action), 'secondary'];
                                const cls = kind === 'primary' ? 'w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white' : kind === 'danger' ? 'w-full rounded-lg border border-[#B3261E]/30 px-3 py-2 text-xs text-[#B3261E]' : 'w-full rounded-lg border border-gray-200 px-3 py-2 text-xs text-[#2D3748]';
                                return <button key={action} type="button" onClick={() => act(action)} className={cls}>{action === 'start' && emergencyStart ? 'Start now (retrospective authorisation)' : l}</button>;
                            })}
                            {c.status === 'approved' && <p className="text-[11px] text-[#718096]">Set the window in the Schedule card to move this change to scheduled.</p>}
                        </div>
                    )}
                    {locked && <p className="rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#718096] shadow-sm">This change is {c.status} and immutable. Raise a new change for further work.</p>}

                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Source</h3>
                        <p className="text-xs text-[#718096]">{source.label || sources[c.source_type] || 'Not linked'}</p>
                        {source.code ? (
                            source.url
                                ? <Link href={source.url} className="mt-1 block text-[#0A1F44] underline">{source.code} · {source.title}</Link>
                                : <p className="mt-1 text-[#2D3748]">{source.code} · {source.title}</p>
                        ) : !source.reference && <p className="mt-1 italic text-[#A0AEC0]">No source record linked.</p>}
                        {source.reference && <p className="mt-1 text-[#2D3748]">{source.reference}</p>}
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Affected assets &amp; services</h3>
                        {affectedAssets.length === 0 && (c.affected_services || []).length === 0 && <p className="text-xs italic text-[#A0AEC0]">None recorded.</p>}
                        <ul className="space-y-1">
                            {affectedAssets.map((a) => (
                                <li key={a.id} className="flex items-center justify-between gap-2 text-xs">
                                    <Link href={route('assets.show', a.id)} className="text-[#0A1F44] underline">{a.name}</Link>
                                    {a.criticality && <StatusBadge status={a.criticality} label={humanize(a.criticality)} />}
                                </li>
                            ))}
                        </ul>
                        {(c.affected_services || []).length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">{c.affected_services.map((s) => <span key={s} className="rounded-lg bg-gray-100 px-2 py-0.5 text-[11px] text-[#2D3748] whitespace-nowrap">{s}</span>)}</div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function BoardPanel({ members = [], tally, canVote, myVote, vote, changeId }) {
    const quorum = tally?.quorum || 0;
    const approve = tally?.approve || 0;
    const reject = tally?.reject || 0;
    const pctA = quorum ? Math.min(100, Math.round((approve / quorum) * 100)) : 0;
    const pctR = quorum ? Math.min(100, Math.round((reject / quorum) * 100)) : 0;
    const castVote = (v) => {
        vote.transform((data) => ({ ...data, vote: v }));
        vote.post(route('changes.vote', changeId), { preserveScroll: true, onSuccess: () => vote.setData('comment', '') });
    };

    return (
        <div className="mt-3 border-t border-[#C9A86A]/30 pt-3">
            <div className="flex items-center justify-between text-xs text-[#4A5568]">
                <span>Quorum {quorum} of {tally?.voting ?? members.filter((m) => m.is_voting).length} voting members</span>
                <span>{approve} approve · {reject} reject · {tally?.abstain || 0} abstain · {tally?.defer || 0} defer{tally?.outcome ? ` · ${humanize(tally.outcome)}` : ''}</span>
            </div>
            <div className="mt-1 flex h-2 overflow-hidden rounded-full bg-gray-100">
                <div className="h-full bg-[#2D7D46]" style={{ width: `${pctA}%` }} />
                <div className="h-full bg-[#B3261E]" style={{ width: `${pctR}%` }} />
            </div>
            <ul className="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                {members.map((m) => (
                    <li key={m.id} className="flex items-center justify-between gap-2 rounded bg-white px-2 py-1 text-xs">
                        <span className="truncate text-[#2D3748]">{m.user?.name}<span className="ml-1 text-[10px] text-[#718096]">{m.role === 'chair' ? 'Chair' : m.represents || ''}{!m.is_voting ? ' · advisory' : ''}</span></span>
                        <span title={m.comment || ''}><StatusBadge status={m.vote || 'no_vote'} label={m.vote ? humanize(m.vote) : 'No vote'} size="xs" /></span>
                    </li>
                ))}
            </ul>
            {canVote && (
                <form onSubmit={(e) => e.preventDefault()} className="mt-3 space-y-2">
                    <input value={vote.data.comment} onChange={(e) => vote.setData('comment', e.target.value)} placeholder={myVote ? `Your vote: ${myVote}. Change it with a comment…` : 'Comment (optional)'} className="w-full rounded-lg border border-gray-200 px-3 py-1.5 text-xs" />
                    <InputError message={vote.errors.vote || vote.errors.comment} />
                    <div className="flex flex-wrap gap-2">
                        {['approve', 'reject', 'abstain', 'defer'].map((v) => (
                            <button key={v} type="button" onClick={() => castVote(v)} disabled={vote.processing} className={`rounded-lg px-3 py-1.5 text-xs text-white disabled:opacity-50 ${myVote === v ? 'ring-2 ring-offset-1 ring-[#0A1F44]' : ''}`} style={{ backgroundColor: VOTE_COLOUR[v] }}>{humanize(v)}</button>
                        ))}
                    </div>
                </form>
            )}
        </div>
    );
}

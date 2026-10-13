import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { RISK_COLOUR, toDateTimeInput, windowLabel } from '@/Utils/changes';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const DECISION_STYLE = { approved: 'bg-[#2D7D46] text-white', rejected: 'bg-[#B3261E] text-white', deferred: 'bg-[#E5A100] text-white', more_info: 'bg-[#1D4ED8] text-white', pending: 'bg-gray-100 text-[#4A5568]' };

function AgendaItem({ item, index, total, canManage, decisions, meetingStatus }) {
    const c = item.change;
    const [notes, setNotes] = useState(item.notes || '');
    const [busy, setBusy] = useState(false);
    const save = (payload) => router.put(route('changes.cab.meetings.items.update', item.id), payload, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const move = (dir) => save({ sequence: Math.max(1, item.sequence + dir) });
    const tally = c?.votes_tally;

    return (
        <li className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-start gap-3">
                <div className="flex flex-col items-center gap-1 text-[10px] text-[#718096]">
                    {canManage && <button type="button" disabled={index === 0 || busy} onClick={() => move(-1)} className="disabled:opacity-30">▲</button>}
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-[#0A1F44] text-xs font-semibold text-white">{item.sequence}</span>
                    {canManage && <button type="button" disabled={index === total - 1 || busy} onClick={() => move(1)} className="disabled:opacity-30">▼</button>}
                </div>
                <div className="min-w-0 flex-1">
                    {c ? (
                        <>
                            <p className="flex flex-wrap items-center gap-2 text-xs text-[#718096]">
                                <Link href={route('changes.show', c.id)} className="font-mono text-[#0A1F44] underline">{c.change_code}</Link>
                                <StatusBadge status={c.change_type} label={humanize(c.change_type)} />
                                <span className="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" style={{ backgroundColor: RISK_COLOUR[c.risk_level] }}>{humanize(c.risk_level)}</span>
                                <StatusBadge status={c.status} label={humanize(c.status)} />
                            </p>
                            <Link href={route('changes.show', c.id)} className="mt-1 block text-sm font-medium text-[#2D3748] hover:underline">{c.title}</Link>
                            <p className="text-[11px] text-[#718096]">Requested by {c.requester || '—'} · window {windowLabel(c.scheduled_start_at)}{c.current_stage ? ` · at stage: ${c.current_stage}` : ''}</p>
                            {tally && (
                                <div className="mt-2 max-w-xs">
                                    <div className="flex justify-between text-[10px] text-[#4A5568]"><span>Votes {tally.approve} approve · {tally.reject} reject · {tally.abstain} abstain · {tally.defer} defer</span><span>quorum {tally.quorum}</span></div>
                                    <div className="mt-0.5 flex h-1.5 overflow-hidden rounded-full bg-gray-100">
                                        <div className="bg-[#2D7D46]" style={{ width: `${tally.quorum ? Math.min(100, (tally.approve / tally.quorum) * 100) : 0}%` }} />
                                        <div className="bg-[#B3261E]" style={{ width: `${tally.quorum ? Math.min(100, (tally.reject / tally.quorum) * 100) : 0}%` }} />
                                    </div>
                                </div>
                            )}
                        </>
                    ) : <p className="text-sm italic text-[#A0AEC0]">Change no longer available.</p>}
                </div>
                <span className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${DECISION_STYLE[item.decision] || DECISION_STYLE.pending}`}>{humanize(item.decision)}</span>
            </div>

            {canManage && meetingStatus !== 'cancelled' && (
                <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-50 pt-3">
                    <input value={notes} onChange={(e) => setNotes(e.target.value)} onBlur={() => notes !== (item.notes || '') && save({ notes })} placeholder="Discussion notes / conditions" className="min-w-[220px] flex-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs" />
                    {decisions.filter((d) => d !== 'pending').map((d) => (
                        <button key={d} type="button" disabled={busy || item.decision === d} onClick={() => save({ decision: d, notes })} className={`rounded-lg px-3 py-1.5 text-xs disabled:opacity-40 ${DECISION_STYLE[d]}`}>{humanize(d)}</button>
                    ))}
                </div>
            )}
            {!canManage && item.notes && <p className="mt-2 text-xs italic text-[#4A5568]">{item.notes}</p>}
        </li>
    );
}

export default function CabMeeting({ meeting: m, candidates = [], canManage = false, decisions = ['pending', 'approved', 'rejected', 'deferred', 'more_info'] }) {
    const [editing, setEditing] = useState(false);
    const [picked, setPicked] = useState([]);
    const details = useForm({ title: m.title, scheduled_at: toDateTimeInput(m.scheduled_at), chaired_by_id: m.chaired_by?.id || '' });
    const minutes = useForm({ minutes: m.minutes || '' });
    const setStatus = (status) => router.put(route('changes.cab.meetings.update', m.id), { status, minutes: minutes.data.minutes }, { preserveScroll: true });
    const addItems = () => router.post(route('changes.cab.meetings.items.store', m.id), { change_ids: picked }, { preserveScroll: true, onSuccess: () => setPicked([]) });
    const decided = m.items.filter((i) => i.decision !== 'pending').length;
    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';

    return (
        <AuthenticatedLayout header="CAB & ECAB">
            <Head title={m.title} />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'CAB & ECAB', href: route('changes.cab.index') }, { label: m.title }]}
                title={m.title}
                subtitle={`${m.board?.name || 'Board'} · ${formatDate(m.scheduled_at, true)} · chaired by ${m.chaired_by?.name || 'board chair'} · quorum ${m.board?.quorum ?? '—'}`}
                actions={(
                    <>
                        <StatusBadge status={m.status === 'held' ? 'held' : m.status === 'scheduled' ? 'scheduled' : 'cancelled'} label={humanize(m.status)} />
                        {canManage && m.status === 'scheduled' && <button type="button" onClick={() => setEditing(!editing)} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44]">{editing ? 'Close' : 'Edit details'}</button>}
                    </>
                )}
            />

            {editing && (
                <form onSubmit={(e) => { e.preventDefault(); details.put(route('changes.cab.meetings.update', m.id), { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm sm:grid-cols-3">
                    <div><label className="block text-xs font-medium text-[#4A5568]">Title</label><input value={details.data.title} onChange={(e) => details.setData('title', e.target.value)} className={field} /><InputError message={details.errors.title} /></div>
                    <div><label className="block text-xs font-medium text-[#4A5568]">When</label><input type="datetime-local" value={details.data.scheduled_at} onChange={(e) => details.setData('scheduled_at', e.target.value)} className={field} /><InputError message={details.errors.scheduled_at} /></div>
                    <div className="flex items-end"><button disabled={details.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Save</button></div>
                </form>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Agenda <span className="ml-1 text-xs font-normal text-[#718096]">{decided}/{m.items.length} decided</span></h3>
                    </div>
                    {m.items.length === 0 && <p className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096] shadow-sm">The agenda is empty.</p>}
                    <ol className="space-y-3">
                        {m.items.map((item, idx) => <AgendaItem key={item.id} item={item} index={idx} total={m.items.length} canManage={canManage} decisions={decisions} meetingStatus={m.status} />)}
                    </ol>

                    {canManage && m.status === 'scheduled' && (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Add to agenda</h3>
                            {candidates.length === 0 ? <p className="text-xs text-[#718096]">Every change waiting on {m.board?.name} is already on this agenda.</p> : (
                                <>
                                    <div className="max-h-56 overflow-y-auto rounded-lg border border-gray-100">
                                        {candidates.map((c) => (
                                            <label key={c.id} className="flex items-center gap-2 border-b border-gray-50 px-3 py-1.5 text-xs">
                                                <input type="checkbox" checked={picked.includes(c.id)} onChange={() => setPicked(picked.includes(c.id) ? picked.filter((x) => x !== c.id) : [...picked, c.id])} className="rounded border-gray-300" />
                                                <span className="font-mono text-[#718096]">{c.change_code}</span><span className="flex-1 text-[#2D3748]">{c.title}</span>
                                                <StatusBadge status={c.risk_level} label={humanize(c.risk_level)} />
                                                {c.due_at && <span className={`text-[10px] ${new Date(c.due_at) < Date.now() ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>due {formatDate(c.due_at)}</span>}
                                            </label>
                                        ))}
                                    </div>
                                    <button type="button" disabled={picked.length === 0} onClick={addItems} className="mt-2 rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white disabled:opacity-50">Add {picked.length || ''} to agenda</button>
                                </>
                            )}
                        </div>
                    )}
                </div>

                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Minutes</h3>
                        {canManage && m.status !== 'cancelled' ? (
                            <form onSubmit={(e) => { e.preventDefault(); minutes.put(route('changes.cab.meetings.update', m.id), { preserveScroll: true }); }} className="space-y-2">
                                <textarea value={minutes.data.minutes} onChange={(e) => minutes.setData('minutes', e.target.value)} rows={10} placeholder="Attendance, quorum confirmed, decisions and conditions, actions…" className="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs" />
                                <InputError message={minutes.errors.minutes} />
                                <div className="flex flex-wrap gap-2">
                                    <button disabled={minutes.processing} className="rounded-lg border border-[#0A1F44]/20 px-3 py-1.5 text-xs text-[#0A1F44] disabled:opacity-50">Save minutes</button>
                                    {m.status === 'scheduled' && <button type="button" onClick={() => setStatus('held')} className="rounded-lg bg-[#2D7D46] px-3 py-1.5 text-xs text-white">Mark held</button>}
                                    {m.status === 'scheduled' && <button type="button" onClick={() => { if (confirm('Cancel this meeting?')) setStatus('cancelled'); }} className="rounded-lg border border-[#B3261E]/30 px-3 py-1.5 text-xs text-[#B3261E]">Cancel meeting</button>}
                                    {m.status === 'held' && <button type="button" onClick={() => setStatus('scheduled')} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-[#2D3748]">Reopen</button>}
                                </div>
                            </form>
                        ) : (
                            <p className={`whitespace-pre-line text-sm ${m.minutes ? 'text-[#2D3748]' : 'italic text-[#A0AEC0]'}`}>{m.minutes || 'No minutes recorded.'}</p>
                        )}
                    </div>
                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-xs text-[#4A5568] shadow-sm">
                        <h3 className="mb-1 text-sm font-semibold text-[#2D3748]">How decisions apply</h3>
                        <ul className="list-disc space-y-1 pl-4">
                            <li>Approving or rejecting an agenda item decides the change's open stage for this board, recorded as a chair decision on the change.</li>
                            <li>Deferred and more-information items stay pending on the change; members can still vote individually.</li>
                            <li>Mark the meeting held once minutes are complete; a held meeting keeps its agenda as the record.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { relativeDue } from '@/Utils/changes';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const BOARD_TYPES = ['cab', 'ecab'];
const MEMBER_ROLES = ['chair', 'member', 'advisor'];

function BoardCard({ board: b, users, represents, canManage, candidates }) {
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);
    const edit = useForm({ name: b.name, type: b.type, chair_id: b.chair?.id || '', quorum: b.quorum, meeting_cadence: b.meeting_cadence || '', description: b.description || '', is_active: !!b.is_active });
    const member = useForm({ user_id: '', role: 'member', represents: represents[0] || '', is_voting: true });
    const voting = b.members.filter((m) => m.is_voting).length;
    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
    const label = 'block text-xs font-medium text-[#4A5568]';

    return (
        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-[#0A1F44]">{b.name} <StatusBadge status={b.type === 'ecab' ? 'emergency' : 'approved'} label={b.type.toUpperCase()} />{!b.is_active && <StatusBadge status="disabled" label="Inactive" />}</h3>
                    <p className="mt-1 text-xs text-[#718096]">Chair: {b.chair?.name || <span className="text-[#B3261E]">not set</span>} · quorum {b.quorum} of {voting} voting · {b.meeting_cadence || 'cadence not set'}</p>
                    {b.description && <p className="mt-1 text-xs text-[#4A5568]">{b.description}</p>}
                </div>
                <div className="flex items-center gap-2">
                    <span className={`rounded-lg px-2 py-0.5 text-[11px] font-semibold ${b.pending_count ? 'bg-[#C9A86A]/20 text-[#8A6400]' : 'bg-gray-100 text-[#718096]'} whitespace-nowrap`}>{b.pending_count} awaiting vote</span>
                    {canManage && <button type="button" onClick={() => setEditing(!editing)} className="text-xs text-[#0A1F44] underline">{editing ? 'Close' : 'Edit board'}</button>}
                </div>
            </div>

            {editing && (
                <form onSubmit={(e) => { e.preventDefault(); edit.put(route('changes.cab.boards.update', b.id), { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="mt-3 grid grid-cols-2 gap-3 rounded-lg bg-[#F7FAFC] p-3 sm:grid-cols-4">
                    <div className="col-span-2"><label className={label}>Name</label><input value={edit.data.name} onChange={(e) => edit.setData('name', e.target.value)} className={field} /><InputError message={edit.errors.name} /></div>
                    <div><label className={label}>Type</label><select value={edit.data.type} onChange={(e) => edit.setData('type', e.target.value)} className={field}>{BOARD_TYPES.map((t) => <option key={t} value={t}>{t.toUpperCase()}</option>)}</select></div>
                    <div><label className={label}>Quorum</label><input type="number" min="1" max="20" value={edit.data.quorum} onChange={(e) => edit.setData('quorum', e.target.value)} className={field} /><InputError message={edit.errors.quorum} /></div>
                    <div className="col-span-2"><label className={label}>Chair</label><select value={edit.data.chair_id} onChange={(e) => edit.setData('chair_id', e.target.value)} className={field}><option value="">—</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` (${u.job_title})` : ''}</option>)}</select><InputError message={edit.errors.chair_id} /></div>
                    <div className="col-span-2"><label className={label}>Meeting cadence</label><input value={edit.data.meeting_cadence} onChange={(e) => edit.setData('meeting_cadence', e.target.value)} placeholder="e.g. Weekly, Thursday 10:00" className={field} /></div>
                    <div className="col-span-2 sm:col-span-3"><label className={label}>Description</label><input value={edit.data.description} onChange={(e) => edit.setData('description', e.target.value)} className={field} /></div>
                    <label className="flex items-center gap-2 self-end pb-2 text-xs text-[#2D3748]"><input type="checkbox" checked={edit.data.is_active} onChange={(e) => edit.setData('is_active', e.target.checked)} className="rounded border-gray-300" /> Active</label>
                    <div className="col-span-2 sm:col-span-4"><button disabled={edit.processing} className="rounded-lg bg-[#0A1F44] px-4 py-1.5 text-xs text-white disabled:opacity-50">Save board</button></div>
                </form>
            )}

            <table className="mt-3 min-w-full text-sm">
                <thead><tr className="text-left text-[11px] uppercase text-[#718096]"><th className="py-1">Member</th><th className="py-1">Seat</th><th className="py-1">Represents</th><th className="py-1">Vote</th><th /></tr></thead>
                <tbody className="divide-y divide-gray-50">
                    {b.members.map((m) => (
                        <tr key={m.id}>
                            <td className="py-1.5 text-[#2D3748]">{m.user?.name}<span className="block text-[10px] text-[#718096]">{m.user?.job_title}</span></td>
                            <td className="py-1.5 text-xs">{m.role === 'chair' ? <span className="rounded bg-[#C9A86A]/20 px-1.5 py-0.5 font-semibold text-[#8A6400]">Chair</span> : humanize(m.role)}</td>
                            <td className="py-1.5 text-xs text-[#4A5568]">{m.represents || '—'}</td>
                            <td className="py-1.5 text-xs">{m.is_voting ? 'Voting' : <span className="text-[#718096]">Advisory</span>}</td>
                            <td className="py-1.5 text-right">{canManage && <button type="button" onClick={() => { if (confirm(`Remove ${m.user?.name} from ${b.name}?`)) router.delete(route('changes.cab.members.destroy', m.id), { preserveScroll: true }); }} className="text-[11px] text-[#B3261E] underline">Remove</button>}</td>
                        </tr>
                    ))}
                    {b.members.length === 0 && <tr><td colSpan={5} className="py-2 text-xs text-[#718096]">No members yet.</td></tr>}
                </tbody>
            </table>

            {canManage && (
                adding ? (
                    <form onSubmit={(e) => { e.preventDefault(); member.post(route('changes.cab.members.store', b.id), { preserveScroll: true, onSuccess: () => { member.reset(); setAdding(false); } }); }} className="mt-3 grid grid-cols-2 gap-2 rounded-lg bg-[#F7FAFC] p-3 sm:grid-cols-5">
                        <select value={member.data.user_id} onChange={(e) => member.setData('user_id', e.target.value)} className="col-span-2 rounded-lg border border-gray-200 py-1.5 text-xs"><option value="">Pick a user…</option>{users.filter((u) => !b.members.some((m) => m.user?.id === u.id)).map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` (${u.job_title})` : ''}</option>)}</select>
                        <select value={member.data.role} onChange={(e) => member.setData('role', e.target.value)} className="rounded-lg border border-gray-200 py-1.5 text-xs">{MEMBER_ROLES.map((r) => <option key={r} value={r}>{humanize(r)}</option>)}</select>
                        <select value={member.data.represents} onChange={(e) => member.setData('represents', e.target.value)} className="rounded-lg border border-gray-200 py-1.5 text-xs">{represents.map((r) => <option key={r} value={r}>{r}</option>)}</select>
                        <label className="flex items-center gap-1 text-xs text-[#2D3748]"><input type="checkbox" checked={member.data.is_voting} onChange={(e) => member.setData('is_voting', e.target.checked)} className="rounded border-gray-300" /> Voting</label>
                        <div className="col-span-2 sm:col-span-5"><InputError message={member.errors.user_id || member.errors.role} /><button disabled={member.processing || !member.data.user_id} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white disabled:opacity-50">Add member</button> <button type="button" onClick={() => setAdding(false)} className="ml-2 text-xs text-[#718096]">Cancel</button></div>
                    </form>
                ) : <button type="button" onClick={() => setAdding(true)} className="mt-3 text-xs text-[#0A1F44] underline">Add member</button>
            )}

            {candidates.length > 0 && (
                <div className="mt-3 rounded-lg border border-[#C9A86A]/40 bg-[#C9A86A]/5 p-3">
                    <p className="text-[11px] font-semibold uppercase text-[#8A6400]">Awaiting this board</p>
                    <ul className="mt-1 space-y-1 text-xs">
                        {candidates.map((c) => (
                            <li key={c.approval_id} className="flex flex-wrap items-center justify-between gap-2">
                                <span><Link href={route('changes.show', c.id)} className="font-mono text-[#0A1F44] underline">{c.change_code}</Link> <span className="text-[#2D3748]">{c.title}</span></span>
                                <span className="flex items-center gap-2 text-[10px] text-[#718096]">
                                    <StatusBadge status={c.risk_level} label={humanize(c.risk_level)} size="xs" />
                                    {c.votes_tally && <span>{c.votes_tally.approve}/{c.votes_tally.quorum} approve</span>}
                                    {c.due_at && <span className={new Date(c.due_at) < Date.now() ? 'font-semibold text-[#B3261E]' : ''}>{relativeDue(c.due_at)}</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

export default function CabIndex({ boards = [], meetings = [], users = [], agendaCandidates = [], represents = [], canManage = false }) {
    const [newBoard, setNewBoard] = useState(false);
    const [newMeeting, setNewMeeting] = useState(false);
    const board = useForm({ name: '', type: 'cab', chair_id: '', quorum: 3, meeting_cadence: '', description: '', is_active: true });
    const meeting = useForm({ board_id: boards[0]?.id || '', title: '', scheduled_at: '', chaired_by_id: '', change_ids: [] });
    const field = 'mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm';
    const label = 'block text-xs font-medium text-[#4A5568]';
    const upcoming = meetings.filter((m) => m.status === 'scheduled');
    const meetingCandidates = agendaCandidates.filter((c) => String(c.board_id) === String(meeting.data.board_id));
    const openMeeting = () => {
        const b = boards.find((x) => String(x.id) === String(meeting.data.board_id)) || boards[0];
        meeting.setData((d) => ({ ...d, board_id: b?.id || '', title: d.title || `${b?.name || 'CAB'} — ${new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`, chaired_by_id: b?.chair?.id || '', change_ids: agendaCandidates.filter((c) => c.board_id === b?.id).map((c) => c.id) }));
        setNewMeeting(true);
    };

    return (
        <AuthenticatedLayout header="CAB & ECAB">
            <Head title="CAB & ECAB" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: 'CAB & ECAB' }]}
                title="Change Advisory Boards"
                subtitle="The CAB and the Emergency CAB: who sits on them, the quorum that carries a vote, and the meetings where agenda items are decided and minuted."
                actions={canManage && (
                    <>
                        <button type="button" onClick={() => setNewBoard(true)} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44]">Add board</button>
                        <button type="button" onClick={openMeeting} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white hover:bg-[#0A1F44]/90">Schedule meeting</button>
                    </>
                )}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Boards" value={boards.length} sublabel={`${boards.filter((b) => b.type === 'ecab').length} ECAB`} tone="navy" />
                <KpiCard label="Awaiting a board" value={agendaCandidates.length} tone={agendaCandidates.length ? 'gold' : 'white'} />
                <KpiCard label="Upcoming meetings" value={upcoming.length} tone="white" />
                <KpiCard label="Overdue board stages" value={agendaCandidates.filter((c) => c.due_at && new Date(c.due_at) < Date.now()).length} tone={agendaCandidates.some((c) => c.due_at && new Date(c.due_at) < Date.now()) ? 'red' : 'white'} />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {boards.map((b) => <BoardCard key={b.id} board={b} users={users} represents={represents} canManage={canManage} candidates={agendaCandidates.filter((c) => c.board_id === b.id)} />)}
                    {boards.length === 0 && <p className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096] shadow-sm">No board has been set up yet.</p>}
                </div>
                <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Meetings</h3>
                    <ul className="divide-y divide-gray-100 text-sm">
                        {meetings.length === 0 && <li className="py-2 text-xs text-[#718096]">No meetings in the last 60 days.</li>}
                        {meetings.map((m) => (
                            <li key={m.id} className="py-2">
                                <Link href={route('changes.cab.meetings.show', m.id)} className="block hover:underline">
                                    <p className="flex items-center justify-between gap-2 text-[#2D3748]"><span className="font-medium">{m.title}</span><StatusBadge status={m.status === 'scheduled' ? 'scheduled' : m.status === 'held' ? 'held' : 'cancelled'} label={humanize(m.status)} /></p>
                                    <p className="text-[11px] text-[#718096]">{formatDate(m.scheduled_at, true)} · {m.board?.name} · {m.items_count} item{m.items_count === 1 ? '' : 's'}</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <Modal show={newBoard} onClose={() => setNewBoard(false)} maxWidth="lg">
                <form onSubmit={(e) => { e.preventDefault(); board.post(route('changes.cab.boards.store'), { onSuccess: () => { setNewBoard(false); board.reset(); } }); }} className="space-y-3 p-6">
                    <h2 className="text-lg font-semibold text-[#0A1F44]">Add a board</h2>
                    <div><label className={label}>Name</label><input value={board.data.name} onChange={(e) => board.setData('name', e.target.value)} className={field} /><InputError message={board.errors.name} /></div>
                    <div className="grid grid-cols-2 gap-3">
                        <div><label className={label}>Type</label><select value={board.data.type} onChange={(e) => board.setData('type', e.target.value)} className={field}>{BOARD_TYPES.map((t) => <option key={t} value={t}>{t.toUpperCase()}</option>)}</select></div>
                        <div><label className={label}>Quorum</label><input type="number" min="1" max="20" value={board.data.quorum} onChange={(e) => board.setData('quorum', e.target.value)} className={field} /><InputError message={board.errors.quorum} /></div>
                    </div>
                    <div><label className={label}>Chair</label><select value={board.data.chair_id} onChange={(e) => board.setData('chair_id', e.target.value)} className={field}><option value="">—</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` (${u.job_title})` : ''}</option>)}</select></div>
                    <div><label className={label}>Meeting cadence</label><input value={board.data.meeting_cadence} onChange={(e) => board.setData('meeting_cadence', e.target.value)} className={field} /></div>
                    <div><label className={label}>Description</label><textarea value={board.data.description} onChange={(e) => board.setData('description', e.target.value)} rows={2} className={field} /></div>
                    <div className="flex justify-end gap-2 pt-2"><button type="button" onClick={() => setNewBoard(false)} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button><button disabled={board.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Create board</button></div>
                </form>
            </Modal>

            <Modal show={newMeeting} onClose={() => setNewMeeting(false)} maxWidth="xl">
                <form onSubmit={(e) => { e.preventDefault(); meeting.post(route('changes.cab.meetings.store'), { onSuccess: () => setNewMeeting(false) }); }} className="space-y-3 p-6">
                    <h2 className="text-lg font-semibold text-[#0A1F44]">Schedule a meeting</h2>
                    <div className="grid grid-cols-2 gap-3">
                        <div><label className={label}>Board</label><select value={meeting.data.board_id} onChange={(e) => { const b = boards.find((x) => String(x.id) === e.target.value); meeting.setData((d) => ({ ...d, board_id: e.target.value, chaired_by_id: b?.chair?.id || '', change_ids: agendaCandidates.filter((c) => String(c.board_id) === e.target.value).map((c) => c.id) })); }} className={field}>{boards.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select><InputError message={meeting.errors.board_id} /></div>
                        <div><label className={label}>When</label><input type="datetime-local" value={meeting.data.scheduled_at} onChange={(e) => meeting.setData('scheduled_at', e.target.value)} className={field} /><InputError message={meeting.errors.scheduled_at} /></div>
                    </div>
                    <div><label className={label}>Title</label><input value={meeting.data.title} onChange={(e) => meeting.setData('title', e.target.value)} className={field} /><InputError message={meeting.errors.title} /></div>
                    <div><label className={label}>Chaired by</label><select value={meeting.data.chaired_by_id} onChange={(e) => meeting.setData('chaired_by_id', e.target.value)} className={field}><option value="">Board chair</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></div>
                    <div>
                        <label className={label}>Agenda ({meeting.data.change_ids.length} of {meetingCandidates.length} awaiting this board)</label>
                        <div className="mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-100">
                            {meetingCandidates.length === 0 && <p className="p-3 text-xs text-[#718096]">No change is currently waiting on this board.</p>}
                            {meetingCandidates.map((c) => (
                                <label key={c.id} className="flex items-center gap-2 border-b border-gray-50 px-3 py-1.5 text-xs">
                                    <input type="checkbox" checked={meeting.data.change_ids.includes(c.id)} onChange={() => meeting.setData('change_ids', meeting.data.change_ids.includes(c.id) ? meeting.data.change_ids.filter((x) => x !== c.id) : [...meeting.data.change_ids, c.id])} className="rounded border-gray-300" />
                                    <span className="font-mono text-[#718096]">{c.change_code}</span><span className="flex-1 text-[#2D3748]">{c.title}</span><StatusBadge status={c.risk_level} label={humanize(c.risk_level)} />
                                </label>
                            ))}
                        </div>
                    </div>
                    <div className="flex justify-end gap-2 pt-2"><button type="button" onClick={() => setNewMeeting(false)} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">Cancel</button><button disabled={meeting.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Schedule</button></div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}

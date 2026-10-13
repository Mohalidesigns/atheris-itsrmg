import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { RISK_COLOUR, VOTE_COLOUR, relativeDue } from '@/Utils/changes';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function ApprovalRow({ item: a }) {
    const { errors = {} } = usePage().props;
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const c = a.change;
    const opts = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setNote('') };
    const decide = (decision) => router.post(route('changes.decide', c.id), { decision, note }, opts);
    const vote = (v) => router.post(route('changes.vote', c.id), { vote: v, comment: note }, opts);

    return (
        <li className={`rounded-xl border bg-white p-4 shadow-sm ${a.overdue ? 'border-[#B3261E]/40' : 'border-gray-100'}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 text-xs text-[#718096]">
                        <Link href={route('changes.show', c.id)} className="font-mono text-[#0A1F44] underline">{c.change_code}</Link>
                        <StatusBadge status={c.change_type} label={humanize(c.change_type)} />
                        <span className="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" style={{ backgroundColor: RISK_COLOUR[c.risk_level] || '#718096' }}>{humanize(c.risk_level)} risk</span>
                        {c.status !== 'in_approval' && <StatusBadge status={c.status} label={humanize(c.status)} />}
                    </p>
                    <Link href={route('changes.show', c.id)} className="mt-1 block text-sm font-medium text-[#2D3748] hover:underline">{c.title}</Link>
                    <p className="mt-1 text-[11px] text-[#718096]">
                        Stage: <span className="font-semibold text-[#8A6400]">{a.stage_name}</span>{a.is_board ? ' (board vote)' : ''} · requested by {c.requester || '—'}{c.scheduled_start_at ? ` · window ${formatDate(c.scheduled_start_at, true)}` : ''}
                    </p>
                </div>
                <div className="text-right text-xs">
                    <p className={a.overdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}>{a.due_at ? relativeDue(a.due_at) : 'No SLA'}</p>
                    {a.due_at && <p className="text-[10px] text-[#A0AEC0]">{formatDate(a.due_at, true)}</p>}
                    {a.my_vote && <p className="mt-1 text-[10px]">Your vote: <span className="font-semibold" style={{ color: VOTE_COLOUR[a.my_vote] }}>{humanize(a.my_vote)}</span></p>}
                </div>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <input value={note} onChange={(e) => setNote(e.target.value)} placeholder={a.is_board && !a.can_decide ? 'Comment on your vote (optional)' : 'Decision note (required to reject)'} className="min-w-[200px] flex-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs" />
                {a.is_board && (
                    ['approve', 'reject', 'abstain', 'defer'].map((v) => (
                        <button key={v} type="button" disabled={busy} onClick={() => vote(v)} className={`rounded-lg px-3 py-1.5 text-xs text-white disabled:opacity-50 ${a.my_vote === v ? 'ring-2 ring-[#0A1F44] ring-offset-1' : ''}`} style={{ backgroundColor: VOTE_COLOUR[v] }}>{humanize(v)}</button>
                    ))
                )}
                {a.can_decide && (
                    <>
                        <button type="button" disabled={busy} onClick={() => decide('approve')} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white disabled:opacity-50">{a.is_board ? 'Approve (chair override)' : 'Approve'}</button>
                        <button type="button" disabled={busy} onClick={() => decide('reject')} className="rounded-lg border border-[#B3261E]/40 px-3 py-1.5 text-xs text-[#B3261E] disabled:opacity-50">Reject</button>
                    </>
                )}
            </div>
            <InputError message={errors.note || errors.decision || errors.vote} className="mt-1" />
        </li>
    );
}

export default function ChangeApprovals({ approvals = [], history = [] }) {
    const overdue = approvals.filter((a) => a.overdue).length;
    const boards = approvals.filter((a) => a.is_board).length;

    return (
        <AuthenticatedLayout header="My Approvals">
            <Head title="My Approvals" />
            <PageHeader
                breadcrumbs={[{ label: 'Change Management' }, { label: 'Change Register', href: route('changes.index') }, { label: 'My Approvals' }]}
                title="My Approvals"
                subtitle="Changes waiting on you right now: the stages you are eligible to decide, and the board votes you sit on. Earlier stages must clear before a later one reaches you."
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Waiting on me" value={approvals.length} tone="navy" />
                <KpiCard label="Overdue" value={overdue} tone={overdue ? 'red' : 'white'} />
                <KpiCard label="Board votes" value={boards} sublabel="CAB / ECAB" tone={boards ? 'gold' : 'white'} />
                <KpiCard label="Decided by me" value={history.length} sublabel="last 20" tone="white" />
            </div>

            {approvals.length === 0 ? (
                <p className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-[#718096] shadow-sm">Nothing is waiting on you. <Link href={route('changes.index', { status: 'in_approval' })} className="text-[#0A1F44] underline">See everything in approval →</Link></p>
            ) : (
                <ul className="space-y-3">{approvals.map((a) => <ApprovalRow key={a.approval_id} item={a} />)}</ul>
            )}

            <div className="mt-6 rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#2D3748]">My recent decisions</h3>
                {history.length === 0 ? <p className="p-4 text-xs text-[#718096]">No decisions recorded yet.</p> : (
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]"><th className="px-4 py-2">Change</th><th className="px-4 py-2">Stage</th><th className="px-4 py-2">Decision</th><th className="px-4 py-2">When</th><th className="px-4 py-2">Note</th></tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {history.map((h) => (
                                <tr key={h.approval_id}>
                                    <td className="px-4 py-2"><Link href={route('changes.show', h.change?.id)} className="font-mono text-xs text-[#0A1F44] underline">{h.change?.change_code}</Link> <span className="text-xs text-[#4A5568]">{h.change?.title}</span></td>
                                    <td className="px-4 py-2 text-xs text-[#4A5568]">{h.stage_name}</td>
                                    <td className="px-4 py-2"><StatusBadge status={h.status} label={humanize(h.status)} /></td>
                                    <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{formatDate(h.decided_at, true)}</td>
                                    <td className="px-4 py-2 text-xs italic text-[#718096]">{h.decision_note || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

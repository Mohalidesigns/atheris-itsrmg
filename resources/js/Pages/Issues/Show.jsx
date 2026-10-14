import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { formatDate, humanize, toDateInput } from '@/Utils/risk';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const STEPS = ['open', 'in_progress', 'remediated', 'verified', 'closed'];
const SLA = {
    on_track: ['On track', 'text-[#2D7D46]'], at_risk: ['At risk: under 20% of the window left', 'text-[#8A6400]'],
    breached: ['Past SLA', 'text-[#B3261E]'], paused: ['Paused while blocked', 'text-[#718096]'],
    met: ['Remediated within SLA', 'text-[#2D7D46]'], missed: ['Remediated after the SLA', 'text-[#B3261E]'], none: ['No SLA', 'text-[#718096]'],
};
const EVENT_DOT = { created: '#0A1F44', status: '#1D4ED8', escalated: '#B3261E', ticket: '#C9A86A', comment: '#718096', assigned: '#2D7D46', updated: '#A0AEC0', severity_changed: '#E5A100', note: '#A0AEC0' };

export default function IssueShow({ issue: i, source, slaState, slaHours, chain = [], nextEscalation, canWork, canApprove, owners = [], connections = [] }) {
    const { errors, auth } = usePage().props;
    const [note, setNote] = useState('');
    const [editing, setEditing] = useState(false);
    const [connectionId, setConnectionId] = useState(connections[0]?.id || '');
    const comment = useForm({ note: '' });
    const edit = useForm({
        title: i.title, description: i.description || '', severity: i.severity, owner_id: i.owner_id || '',
        due_date: toDateInput(i.due_date), source_reference: i.source_reference || '', root_cause: i.root_cause || '', remediation_plan: i.remediation_plan || '',
    });

    const act = (action) => router.post(route('issues.transition', i.id), { action, note }, { preserveScroll: true, onSuccess: () => setNote('') });
    const escalate = () => router.post(route('issues.escalate', i.id), { note }, { preserveScroll: true, onSuccess: () => setNote('') });
    const [slaLabel, slaClass] = SLA[slaState] || SLA.none;
    const stepIndex = STEPS.indexOf(i.status === 'blocked' ? 'in_progress' : i.status);
    const iRemediated = i.remediated_by_id && i.remediated_by_id === auth?.user?.id;
    const missingForRemediation = !i.root_cause || !i.remediation_plan;

    const buttons = {
        open: [['start', 'Start work', 'primary'], ['block', 'Mark blocked', 'secondary']],
        in_progress: [['remediate', 'Mark remediated', 'primary'], ['block', 'Mark blocked', 'secondary']],
        blocked: [['unblock', 'Unblock', 'primary']],
        remediated: canApprove && !iRemediated ? [['verify', 'Verify remediation', 'primary'], ['reject', 'Fails verification', 'secondary']] : [],
        verified: [['close', 'Close issue', 'primary']],
        closed: [['reopen', 'Reopen', 'secondary']],
    }[i.status] || [];

    return (
        <AuthenticatedLayout header="Issues Console">
            <Head title={i.issue_code || 'Issue'} />
            <PageHeader
                breadcrumbs={[{ label: 'Issues & Remediation' }, { label: 'Issues Console', href: route('issues.index') }, { label: i.issue_code }]}
                title={i.title}
                subtitle={`${i.issue_code} · ${source.label}${source.code ? ` ${source.code}` : ''} · raised ${formatDate(i.created_at)}${i.creator ? ` by ${i.creator.name}` : ''}`}
                actions={canWork && i.status !== 'closed' && (
                    <button type="button" onClick={() => setEditing(!editing)} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44]">{editing ? 'Cancel edit' : 'Edit'}</button>
                )}
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                {STEPS.map((s, idx) => (
                    <div key={s} className="flex items-center gap-2">
                        <span className={`rounded-lg px-3 py-1 text-xs ${idx < stepIndex ? 'bg-[#2D7D46]/10 text-[#2D7D46]' : idx === stepIndex ? 'bg-[#0A1F44] text-white' : 'bg-gray-100 text-[#718096]'} whitespace-nowrap`}>
                            {humanize(s)}{s === 'in_progress' && i.status === 'blocked' ? ' (blocked)' : ''}
                        </span>
                        {idx < STEPS.length - 1 && <span className="text-gray-300">→</span>}
                    </div>
                ))}
                {i.escalation_level > 0 && <span className="rounded-lg bg-[#B3261E]/10 px-3 py-1 text-xs font-semibold text-[#B3261E] whitespace-nowrap">Escalated · level {i.escalation_level} ({chain[i.escalation_level]})</span>}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {editing ? (
                        <form onSubmit={(e) => { e.preventDefault(); edit.put(route('issues.update', i.id), { preserveScroll: true, onSuccess: () => setEditing(false) }); }}
                            className="space-y-3 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <div>
                                <label className="block text-xs font-medium text-[#4A5568]">Title</label>
                                <input value={edit.data.title} onChange={(e) => edit.setData('title', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                                <InputError message={edit.errors.title} className="mt-1" />
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label className="block text-xs font-medium text-[#4A5568]">Severity</label>
                                    <select value={edit.data.severity} onChange={(e) => edit.setData('severity', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                        {['critical', 'high', 'moderate', 'low'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                                    </select>
                                    <p className="mt-1 text-[10px] text-[#718096]">Changing it re-bases the SLA.</p>
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-[#4A5568]">Owner</label>
                                    <select value={edit.data.owner_id} onChange={(e) => edit.setData('owner_id', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 py-2 text-sm">
                                        <option value="">Unassigned</option>
                                        {owners.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-[#4A5568]">Target date</label>
                                    <input type="date" value={edit.data.due_date} onChange={(e) => edit.setData('due_date', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                                </div>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-[#4A5568]">Source reference</label>
                                <input value={edit.data.source_reference} onChange={(e) => edit.setData('source_reference', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                            </div>
                            {[['description', 'Description'], ['root_cause', 'Root cause'], ['remediation_plan', 'Remediation plan']].map(([k, label]) => (
                                <div key={k}>
                                    <label className="block text-xs font-medium text-[#4A5568]">{label}</label>
                                    <textarea value={edit.data[k]} onChange={(e) => edit.setData(k, e.target.value)} rows={3} className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                                </div>
                            ))}
                            <button disabled={edit.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white disabled:opacity-50">Save</button>
                        </form>
                    ) : (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                            {[['Description', i.description], ['Root cause', i.root_cause], ['Remediation plan', i.remediation_plan]].map(([label, text]) => (
                                <div key={label} className="mb-4 last:mb-0">
                                    <h3 className="text-xs font-semibold uppercase text-[#718096]">{label}</h3>
                                    <p className={`mt-1 whitespace-pre-line ${text ? 'text-[#2D3748]' : 'italic text-[#A0AEC0]'}`}>{text || 'Not recorded yet.'}</p>
                                </div>
                            ))}
                            {i.status === 'blocked' && i.blocked_reason && (
                                <p className="mt-3 rounded-lg bg-[#E5A100]/10 p-3 text-xs text-[#8A6400]"><strong>Blocked:</strong> {i.blocked_reason}</p>
                            )}
                        </div>
                    )}

                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="mb-3 text-sm font-semibold text-[#2D3748]">Timeline</h3>
                        <form onSubmit={(e) => { e.preventDefault(); comment.post(route('issues.comment', i.id), { preserveScroll: true, onSuccess: () => comment.reset() }); }} className="mb-4 flex gap-2">
                            <input value={comment.data.note} onChange={(e) => comment.setData('note', e.target.value)} placeholder="Add a comment…" className="flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                            <button disabled={!comment.data.note || comment.processing} className="rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-xs text-[#0A1F44] disabled:opacity-50">Comment</button>
                        </form>
                        <ol className="space-y-3">
                            {(i.events || []).map((e) => (
                                <li key={e.id} className="flex gap-3 text-sm">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: EVENT_DOT[e.event_type] || '#A0AEC0' }} />
                                    <div>
                                        <p className="text-[#2D3748]">{e.description || humanize(e.event_type)}</p>
                                        <p className="text-[11px] text-[#718096]">{formatDate(e.created_at, true)} · {e.actor?.name || 'System'}</p>
                                    </div>
                                </li>
                            ))}
                            {(i.events || []).length === 0 && <li className="text-xs text-[#718096]">No activity yet.</li>}
                        </ol>
                    </div>
                </div>

                <div className="space-y-4">
                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <dl className="space-y-2">
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Status</dt><dd><StatusBadge status={i.status} label={humanize(i.status)} /></dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Severity</dt><dd><StatusBadge status={i.severity} /></dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Owner</dt><dd className="text-right text-[#2D3748]">{i.owner ? i.owner.name : <span className="text-[#B3261E]">Unassigned</span>}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">SLA</dt><dd className="text-[#2D3748]">{slaHours} h policy</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">SLA due</dt><dd className={`text-right ${slaClass}`}>{formatDate(i.sla_due_at, true)}<span className="block text-[11px]">{slaLabel}</span></dd></div>
                            {i.sla_paused_minutes > 0 && <div className="flex justify-between gap-2"><dt className="text-[#718096]">Blocked time</dt><dd className="text-[#2D3748]">{Math.round(i.sla_paused_minutes / 60)} h added</dd></div>}
                            {i.due_date && <div className="flex justify-between gap-2"><dt className="text-[#718096]">Target date</dt><dd className="text-[#2D3748]">{formatDate(i.due_date)}</dd></div>}
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Remediated</dt><dd className="text-right text-[#2D3748]">{i.remediated_at ? `${formatDate(i.remediated_at)} · ${i.remediator?.name || 'via ITSM'}` : '—'}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-[#718096]">Verified</dt><dd className="text-right text-[#2D3748]">{i.verified_at ? `${formatDate(i.verified_at)} · ${i.verifier?.name}` : '—'}</dd></div>
                            {i.closed_at && <div className="flex justify-between gap-2"><dt className="text-[#718096]">Closed</dt><dd className="text-[#2D3748]">{formatDate(i.closed_at)}</dd></div>}
                        </dl>
                        <p className="mt-3 text-[11px] text-[#718096]">Escalation chain: {chain.join(' → ')}</p>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">Source</h3>
                        <p className="text-xs text-[#718096]">{source.label}</p>
                        {source.code ? (
                            source.url
                                ? <Link href={source.url} className="mt-1 block text-[#0A1F44] underline">{source.code} · {source.title}</Link>
                                : <p className="mt-1 text-[#2D3748]">{source.code} · {source.title}</p>
                        ) : !source.reference && <p className="mt-1 italic text-[#A0AEC0]">No source record linked.</p>}
                        {source.reference && <p className="mt-1 text-[#2D3748]">{source.reference}</p>}
                    </div>

                    {(canWork || canApprove) && buttons.length + (i.status !== 'closed' ? 1 : 0) > 0 && (
                        <div className="space-y-2 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Next step</h3>
                            <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2}
                                placeholder={i.status === 'remediated' ? 'Verification evidence or reason for rejection' : 'Note (required to block, reopen or escalate)'}
                                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs" />
                            <InputError message={errors?.note || errors?.action} />
                            {i.status === 'in_progress' && missingForRemediation && !errors?.action && (
                                <p className="text-[11px] text-[#8A6400]">Record the root cause and remediation plan (Edit) before marking it remediated.</p>
                            )}
                            {i.status === 'remediated' && !(canApprove && !iRemediated) && (
                                <p className="text-[11px] text-[#718096]">Awaiting verification by someone other than the remediator, holding the approve issues permission.</p>
                            )}
                            {buttons.map(([action, label, kind]) => (
                                <button key={action} type="button" onClick={() => act(action)}
                                    className={kind === 'primary' ? 'w-full rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white' : 'w-full rounded-lg border border-gray-200 px-3 py-2 text-xs text-[#2D3748]'}>
                                    {label}
                                </button>
                            ))}
                            {canWork && ['open', 'in_progress', 'blocked'].includes(i.status) && nextEscalation && (
                                <button type="button" onClick={escalate} className="w-full rounded-lg border border-[#B3261E]/30 px-3 py-2 text-xs text-[#B3261E]">Escalate to {nextEscalation}</button>
                            )}
                        </div>
                    )}

                    <div className="rounded-xl border border-gray-100 bg-white p-5 text-sm shadow-sm">
                        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">ITSM ticket</h3>
                        {i.external_ticket_id ? (
                            <div className="space-y-1">
                                <p>
                                    {i.external_ticket_url
                                        ? <a href={i.external_ticket_url} target="_blank" rel="noreferrer" className="font-mono text-[#0A1F44] underline">{i.external_ticket_id}</a>
                                        : <span className="font-mono">{i.external_ticket_id}</span>}
                                    <span className="ml-2 text-xs text-[#718096]">{i.itsm_connection?.name || humanize(i.external_ticket_system || '')}{i.itsm_connection?.mode === 'sandbox' ? ' (sandbox)' : ''}</span>
                                </p>
                                {i.external_ticket_status && <p className="text-xs text-[#4A5568]">Ticket status: {i.external_ticket_status}</p>}
                                {i.ticket_synced_at && <p className="text-[11px] text-[#718096]">Last synced {formatDate(i.ticket_synced_at, true)}</p>}
                            </div>
                        ) : connections.length === 0 ? (
                            <p className="text-xs text-[#718096]">No ITSM connector is set up. <Link href={route('itsm.index')} className="text-[#0A1F44] underline">ITSM Integrations →</Link></p>
                        ) : canWork && i.status !== 'closed' ? (
                            <div className="flex gap-2">
                                <select value={connectionId} onChange={(e) => setConnectionId(e.target.value)} className="flex-1 rounded-lg border border-gray-200 py-1.5 text-xs">
                                    {connections.map((c) => <option key={c.id} value={c.id}>{c.name}{c.mode === 'sandbox' ? ' (sandbox)' : ''}</option>)}
                                </select>
                                <button type="button" onClick={() => router.post(route('issues.push', i.id), { connection_id: connectionId }, { preserveScroll: true })}
                                    className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white">Create ticket</button>
                            </div>
                        ) : <p className="text-xs text-[#718096]">Not ticketed.</p>}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

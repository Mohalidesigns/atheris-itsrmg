import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PolicyStatus, fmtDate, isPast } from '@/Components/Policies/PolicyBadges';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PencilIcon, TrashIcon, CheckBadgeIcon } from '@/Components/Material/Symbols';

const STEPS = ['draft', 'in_review', 'approved', 'active'];
const cap = (s) => (s || '').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

function Card({ title, right, children, className = '' }) {
    return (
        <div className={`bg-white rounded-xl border border-gray-100 shadow-sm ${className}`}>
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-[#2D3748]">{title}</h3>
                {right}
            </div>
            {children}
        </div>
    );
}

/** The workflow actions available for the policy's status, with a reason box where one is needed. */
function Workflow({ policy, can }) {
    const form = useForm({ action: '', note: '' });
    const [needsNote, setNeedsNote] = useState(null);
    const run = (action) => {
        form.transform((d) => ({ ...d, action }));
        form.post(route('policies.transition', policy.id), { preserveScroll: true, onSuccess: () => { setNeedsNote(null); form.reset(); } });
    };
    const actions = {
        draft: can.edit ? [['submit', 'Submit for approval', false]] : [],
        in_review: can.approve ? [['approve', 'Approve', false], ['return', 'Return to author', true]] : [],
        approved: can.approve ? [['publish', 'Publish (put in force)', false], ['retire', 'Retire', true]] : [],
        active: can.approve ? [['retire', 'Retire', true]] : [],
        retired: [],
    }[policy.status] || [];

    return (
        <div className="mb-4 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <ol className="flex flex-wrap items-center gap-2 text-xs">
                {STEPS.map((s, i) => {
                    const reached = STEPS.indexOf(policy.status) >= i || policy.status === 'retired';
                    return (
                        <li key={s} className="flex items-center gap-2">
                            <span className={`rounded-lg px-2 py-0.5 ${policy.status === s ? 'bg-[#0A1F44] text-white' : reached ? 'bg-[#0A1F44]/10 text-[#0A1F44]' : 'bg-gray-100 text-[#718096]'}`}>{s === 'active' ? 'In force' : cap(s)}</span>
                            {i < STEPS.length - 1 && <span className="text-gray-300">→</span>}
                        </li>
                    );
                })}
                {policy.status === 'retired' && <li className="rounded-lg bg-gray-200 px-2 py-0.5 text-[#718096]">Retired {fmtDate(policy.retired_at)}</li>}
            </ol>
            {policy.status_note && <p className="mt-2 text-xs text-[#8A6400]">Note: {policy.status_note}</p>}
            {actions.length > 0 && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    {needsNote ? (
                        <>
                            <input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="Reason (required)" className="min-w-0 flex-1 rounded-lg border-gray-300 text-sm" />
                            <button type="button" onClick={() => run(needsNote)} disabled={form.processing} className="rounded-lg bg-[#B3261E] px-3 py-2 text-sm font-semibold text-white">{cap(needsNote)}</button>
                            <button type="button" onClick={() => setNeedsNote(null)} className="text-sm text-[#718096]">Cancel</button>
                        </>
                    ) : actions.map(([action, label, note]) => (
                        <button key={action} type="button" disabled={form.processing} onClick={() => (note ? setNeedsNote(action) : run(action))}
                            className={note ? 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-[#4A5568]' : 'rounded-lg bg-[#0A1F44] px-3 py-2 text-sm font-semibold text-white'}>
                            {label}
                        </button>
                    ))}
                </div>
            )}
            {Object.values(form.errors).length > 0 && <p className="mt-2 text-xs text-[#C53030]">{Object.values(form.errors).join(' ')}</p>}
        </div>
    );
}

function describe(entry) {
    const changed = Object.keys(entry.attribute_changes?.attributes || {}).filter((k) => !['updated_at', 'created_at'].includes(k));
    if (entry.event === 'created') return 'Created';
    if (changed.includes('status')) return `Status → ${cap(entry.attribute_changes.attributes.status)}`;
    return changed.length ? `Changed ${changed.map((k) => k.replace(/_/g, ' ')).join(', ')}` : entry.description;
}

export default function ShowPolicy({ policy, attestationSummary = {}, recentAttestations = [], userAttestation, trail = [], categories = {}, can = {} }) {
    const [tab, setTab] = useState('text');
    const reviewOverdue = policy.status === 'active' && isPast(policy.review_date);
    const attestedCurrent = userAttestation?.status === 'completed' && userAttestation?.version_number === policy.version_number;
    const pct = attestationSummary.requested ? Math.round((attestationSummary.completed / attestationSummary.requested) * 100) : null;

    const destroy = () => {
        if (confirm(`Delete draft ${policy.policy_code}?`)) router.delete(route('policies.destroy', policy.id));
    };

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">{policy.policy_code}</span>
                <span>{policy.title}</span>
            </div>
        }>
            <Head title={`${policy.policy_code} - ${policy.title}`} />

            <div className="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-4">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><PolicyStatus status={policy.status} /></div>
                    <p className="mt-1 text-[11px] text-[#718096]">Version {policy.version_number}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Owner</p>
                    <p className={`mt-1 text-sm font-medium ${policy.owner ? 'text-[#2D3748]' : 'text-[#B3261E]'}`}>{policy.owner?.name || 'Unassigned'}</p>
                    <p className="text-[11px] text-[#718096]">{categories[policy.category] || policy.category}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Approved</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{policy.approver?.name || '—'}</p>
                    <p className="text-[11px] text-[#718096]">{fmtDate(policy.approved_at) || ''}{policy.submitter ? ` · submitted by ${policy.submitter.name}` : ''}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">In force / review</p>
                    <p className="mt-1 text-sm text-[#2D3748]">{fmtDate(policy.effective_date) || '—'}</p>
                    <p className={`text-[11px] ${reviewOverdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>Review {fmtDate(policy.review_date) || '—'}{reviewOverdue ? ' · overdue' : ''}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Attested (v{policy.version_number})</p>
                    <p className="mt-1 text-lg font-bold text-[#0A1F44]">{pct == null ? '—' : `${pct}%`}</p>
                    <p className="text-[11px] text-[#718096]">{attestationSummary.completed ?? 0} of {attestationSummary.requested ?? 0}{attestationSummary.overdue ? ` · ${attestationSummary.overdue} overdue` : ''}</p>
                </div>
            </div>

            <Workflow policy={policy} can={can} />

            <div className="flex flex-wrap gap-2 mb-4">
                {can.edit && (
                    <Link href={route('policies.edit', policy.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        <PencilIcon className="w-4 h-4" /> Edit
                    </Link>
                )}
                {policy.status === 'active' && (attestedCurrent ? (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-lg bg-[#D1FAE5] text-[#2D7D46]"><CheckBadgeIcon className="w-4 h-4" /> You attested to v{policy.version_number} on {fmtDate(userAttestation.acknowledged_at)}</span>
                ) : (
                    <button type="button" onClick={() => router.post(route('policies.attest', policy.id), {}, { preserveScroll: true })}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold rounded-lg bg-[#0A1F44] text-white">
                        <CheckBadgeIcon className="w-4 h-4" /> I have read and accept v{policy.version_number}
                    </button>
                ))}
                <Link href={route('policy-exceptions.index', { policy_id: policy.id })} className="inline-flex items-center px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">Exceptions</Link>
                {can.delete && policy.status === 'draft' && (
                    <button type="button" onClick={destroy} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg text-[#C53030]"><TrashIcon className="w-4 h-4" /> Delete draft</button>
                )}
            </div>

            <div className="mb-4 flex gap-1 border-b border-gray-200">
                {[['text', 'Policy text'], ['controls', `Controls (${(policy.controls || []).length})`], ['attestations', 'Attestations'], ['exceptions', `Exceptions (${(policy.exceptions || []).length})`], ['versions', `Versions (${(policy.versions || []).length})`], ['history', 'History']].map(([k, label]) => (
                    <button key={k} type="button" onClick={() => setTab(k)} className={`px-4 py-2 text-sm border-b-2 ${tab === k ? 'border-[#C9A86A] text-[#0A1F44] font-semibold' : 'border-transparent text-[#718096]'}`}>{label}</button>
                ))}
            </div>

            {tab === 'text' && (
                <Card title="Policy text">
                    <div className="px-5 py-4">
                        {policy.description && <p className="mb-3 text-sm text-[#4A5568]">{policy.description}</p>}
                        {policy.content ? <pre className="whitespace-pre-wrap font-sans text-sm text-[#2D3748]">{policy.content}</pre> : <p className="text-sm italic text-[#718096]">No text yet. A policy needs its text and an owner before it can be submitted.</p>}
                    </div>
                </Card>
            )}

            {tab === 'controls' && (
                <Card title="Controls that implement this policy" right={<Link href={route('control-standards.index')} className="text-xs text-[#0A1F44] underline">Control Standards →</Link>}>
                    {(policy.controls || []).length === 0 ? <p className="px-5 py-6 text-center text-sm text-[#B3261E]">No control is mapped. Without one, nothing shows the policy is being followed.</p> : (
                        <ul className="divide-y divide-gray-50">
                            {policy.controls.map((c) => (
                                <li key={c.id} className="px-5 py-2 flex items-center justify-between text-sm">
                                    <div><Link href={route('controls.show', c.id)} className="font-mono-data text-xs text-[#1A365D] hover:underline">{c.control_code}</Link> <span className="text-[#2D3748]">{c.title}</span></div>
                                    <span className={`text-xs ${c.effectiveness === 'ineffective' ? 'font-semibold text-[#B3261E]' : c.effectiveness === 'effective' ? 'text-[#2D7D46]' : 'text-[#718096]'}`}>{cap(c.effectiveness)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {tab === 'attestations' && (
                <Card title={`Attestations to version ${policy.version_number}`} right={<Link href={route('policy-attestations.index', { policy_id: policy.id })} className="text-xs text-[#0A1F44] underline">All attestations →</Link>}>
                    <div className="grid grid-cols-4 gap-2 px-5 py-3 text-center text-xs">
                        {[['Asked', attestationSummary.requested], ['Completed', attestationSummary.completed], ['Overdue', attestationSummary.overdue], ['Waived', attestationSummary.waived]].map(([l, n]) => (
                            <div key={l} className="rounded-lg border border-gray-100 py-2"><p className="text-lg font-bold text-[#0A1F44]">{n ?? 0}</p><p className="text-[#718096]">{l}</p></div>
                        ))}
                    </div>
                    <ul className="divide-y divide-gray-50">
                        {recentAttestations.map((a) => (
                            <li key={a.id} className="px-5 py-2 flex items-center justify-between text-sm">
                                <span className="text-[#2D3748]">{a.user?.name} <span className="text-[11px] text-[#718096]">{a.user?.department}</span></span>
                                <span className="text-xs text-[#718096]">{a.status === 'completed' ? `Attested ${fmtDate(a.acknowledged_at)}` : a.status === 'pending' ? `Due ${fmtDate(a.due_date)}` : cap(a.status)}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {tab === 'exceptions' && (
                <Card title="Exceptions" right={<Link href={route('policy-exceptions.index', { policy_id: policy.id })} className="text-xs text-[#0A1F44] underline">Request or review →</Link>}>
                    {(policy.exceptions || []).length === 0 ? <p className="px-5 py-6 text-center text-sm text-[#718096]">No exceptions to this policy.</p> : (
                        <ul className="divide-y divide-gray-50">
                            {policy.exceptions.map((e) => (
                                <li key={e.id} className="px-5 py-2 flex items-center justify-between text-sm">
                                    <div><span className="font-mono-data text-xs text-[#718096]">{e.exception_code}</span> <span className="text-[#2D3748]">{e.title}</span></div>
                                    <span className="flex items-center gap-2 text-xs text-[#718096]">until {fmtDate(e.expires_on)} <PolicyStatus status={e.status === 'approved' && isPast(e.expires_on) ? 'expired' : e.status} /></span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {tab === 'versions' && (
                <Card title="Version history">
                    <ul className="divide-y divide-gray-50">
                        {(policy.versions || []).map((v) => (
                            <li key={v.id} className="px-5 py-2 flex items-center justify-between text-sm">
                                <span><span className="font-mono-data text-xs">v{v.version_number}</span> <span className="text-[#2D3748]">{v.change_summary}</span></span>
                                <span className="text-xs text-[#718096]">{v.creator?.name || '—'} · {fmtDate(v.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {tab === 'history' && (
                <Card title="History">
                    <ul className="divide-y divide-gray-50">
                        {trail.length === 0 && <li className="px-5 py-6 text-center text-sm text-[#718096]">No recorded changes.</li>}
                        {trail.map((t) => (
                            <li key={t.id} className="px-5 py-2 flex items-center justify-between text-sm">
                                <span className="text-[#2D3748]">{describe(t)}</span>
                                <span className="text-xs text-[#718096]">{t.causer?.name || 'System'} · {new Date(t.created_at).toLocaleString('en-GB')}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </AuthenticatedLayout>
    );
}

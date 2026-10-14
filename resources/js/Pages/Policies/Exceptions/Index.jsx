import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import KpiCard from '@/Components/KpiCard';
import Modal from '@/Components/Modal';
import { PolicyStatus, fmtDate, isPast } from '@/Components/Policies/PolicyBadges';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon } from '@/Components/Material/Symbols';

const input = 'mt-1 w-full rounded-lg border-gray-300 text-sm';

function RequestModal({ show, onClose, policies, controls, risks, users, riskRatings, maxMonths, presetPolicy }) {
    const form = useForm({ policy_id: presetPolicy || '', control_id: '', risk_id: '', owner_id: '', title: '', scope: '', justification: '', compensating_controls: '', risk_rating: 'medium', starts_on: '', expires_on: '' });
    const submit = (e) => { e.preventDefault(); form.post(route('policy-exceptions.store'), { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } }); };
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="space-y-3 p-6">
                <h3 className="text-base font-semibold text-[#2D3748]">Request a policy exception</h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label className="text-xs text-[#718096]">Policy *
                        <select value={form.data.policy_id} onChange={(e) => form.setData('policy_id', e.target.value)} className={input}>
                            <option value="">Select…</option>
                            {policies.map((p) => <option key={p.id} value={p.id}>{p.policy_code} — {p.title}</option>)}
                        </select>{err('policy_id')}
                    </label>
                    <label className="text-xs text-[#718096]">Control affected
                        <select value={form.data.control_id} onChange={(e) => form.setData('control_id', e.target.value)} className={input}>
                            <option value="">Whole policy</option>
                            {controls.map((c) => <option key={c.id} value={c.id}>{c.control_code} — {c.title}</option>)}
                        </select>
                    </label>
                </div>
                <label className="block text-xs text-[#718096]">What is the exception? *
                    <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className={input} />{err('title')}
                </label>
                <label className="block text-xs text-[#718096]">Scope (systems, teams)
                    <input value={form.data.scope} onChange={(e) => form.setData('scope', e.target.value)} className={input} />
                </label>
                <label className="block text-xs text-[#718096]">Business justification *
                    <textarea rows={2} value={form.data.justification} onChange={(e) => form.setData('justification', e.target.value)} className={input} />{err('justification')}
                </label>
                <label className="block text-xs text-[#718096]">Compensating controls {['critical', 'high'].includes(form.data.risk_rating) && '*'}
                    <textarea rows={2} value={form.data.compensating_controls} onChange={(e) => form.setData('compensating_controls', e.target.value)} className={input} />{err('compensating_controls')}
                </label>
                <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <label className="text-xs text-[#718096]">Risk rating *
                        <select value={form.data.risk_rating} onChange={(e) => form.setData('risk_rating', e.target.value)} className={input}>
                            {riskRatings.map((r) => <option key={r} value={r}>{r} (max {maxMonths[r]} months)</option>)}
                        </select>
                    </label>
                    <label className="text-xs text-[#718096]">Starts
                        <input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} className={input} />
                    </label>
                    <label className="text-xs text-[#718096]">Ends *
                        <input type="date" value={form.data.expires_on} onChange={(e) => form.setData('expires_on', e.target.value)} className={input} />{err('expires_on')}
                    </label>
                    <label className="text-xs text-[#718096]">Linked risk
                        <select value={form.data.risk_id} onChange={(e) => form.setData('risk_id', e.target.value)} className={input}>
                            <option value="">None</option>
                            {risks.map((r) => <option key={r.id} value={r.id}>{r.risk_id_code}</option>)}
                        </select>
                    </label>
                </div>
                <label className="block text-xs text-[#718096]">Exception owner (defaults to you)
                    <select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className={input}>
                        <option value="">Me</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                </label>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-[#718096]">Cancel</button>
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Submit request</button>
                </div>
            </form>
        </Modal>
    );
}

function Decision({ exception, me }) {
    const form = useForm({ decision: '', notes: '', expires_on: '' });
    const [mode, setMode] = useState(null);
    const send = (decision) => { form.transform((d) => ({ ...d, decision })); form.post(route('policy-exceptions.decide', exception.id), { preserveScroll: true, onSuccess: () => setMode(null) }); };

    if (exception.status === 'requested' && exception.requested_by === me) return <span className="text-[11px] text-[#718096]">Awaiting another approver</span>;
    if (exception.status === 'requested') {
        return mode ? (
            <div className="flex flex-wrap gap-1">
                {mode === 'reject' ? (
                    <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Reason" className="w-40 rounded border-gray-300 text-xs" />
                ) : (
                    <input type="date" value={form.data.expires_on} onChange={(e) => form.setData('expires_on', e.target.value)} title="Shorten the term (optional)" className="rounded border-gray-300 text-xs" />
                )}
                <button type="button" onClick={() => send(mode)} disabled={form.processing} className="rounded bg-[#0A1F44] px-2 text-xs text-white">Confirm</button>
                <button type="button" onClick={() => setMode(null)} className="text-xs text-[#718096]">Cancel</button>
                {Object.values(form.errors).length > 0 && <p className="w-full text-[11px] text-[#C53030]">{Object.values(form.errors).join(' ')}</p>}
            </div>
        ) : (
            <div className="flex gap-2">
                <button type="button" onClick={() => setMode('approve')} className="rounded bg-[#2D7D46] px-2 py-1 text-xs font-semibold text-white">Approve</button>
                <button type="button" onClick={() => setMode('reject')} className="rounded border border-[#C53030]/40 px-2 py-1 text-xs text-[#C53030]">Reject</button>
            </div>
        );
    }
    if (exception.status === 'approved' && !isPast(exception.expires_on)) {
        return mode ? (
            <div className="flex gap-1">
                <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Why revoke" className="w-36 rounded border-gray-300 text-xs" />
                <button type="button" onClick={() => send('revoke')} className="rounded bg-[#B3261E] px-2 text-xs text-white">Revoke</button>
            </div>
        ) : <button type="button" onClick={() => setMode('revoke')} className="text-xs text-[#C53030] underline">Revoke…</button>;
    }
    return null;
}

export default function ExceptionsIndex({ exceptions, metrics = {}, filters = {}, policies = [], controls = [], risks = [], users = [], statuses = [], riskRatings = [], maxMonths = {}, can = {}, me }) {
    const [requesting, setRequesting] = useState(false);
    const apply = (key, value) => router.get(route('policy-exceptions.index'), { ...filters, [key]: value || undefined, page: undefined }, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header="Policy Exceptions">
            <Head title="Policy Exceptions" />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Policy Exceptions</h2>
                    <p className="text-sm text-[#718096]">Time-bound, risk-accepted departures from a policy. The requester cannot approve their own request; the term is capped by risk rating.</p>
                </div>
                <button type="button" onClick={() => setRequesting(true)} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg">
                    <PlusIcon className="w-4 h-4" /> Request exception
                </button>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <KpiCard label="Awaiting decision" value={metrics.exceptions_requested ?? 0} tone={metrics.exceptions_requested ? 'amber' : 'white'} />
                <KpiCard label="Live exceptions" value={metrics.exceptions_live ?? 0} tone="navy" />
                <KpiCard label="Ending within 30 days" value={metrics.exceptions_expiring ?? 0} tone="white" />
                <KpiCard label="High or critical, live" value={metrics.exceptions_high_risk ?? 0} tone={metrics.exceptions_high_risk ? 'red' : 'white'} />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 flex flex-wrap gap-3">
                    <select value={filters.status || ''} onChange={(e) => apply('status', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All statuses</option>
                        {statuses.map((s) => <option key={s} value={s}>{s.replace(/^./, (c) => c.toUpperCase())}</option>)}
                    </select>
                    <select value={filters.policy_id || ''} onChange={(e) => apply('policy_id', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All policies</option>
                        {policies.map((p) => <option key={p.id} value={p.id}>{p.policy_code} — {p.title}</option>)}
                    </select>
                    <button type="button" onClick={() => apply('flag', filters.flag === 'expiring' ? '' : 'expiring')} className={`text-xs ${filters.flag === 'expiring' ? 'font-semibold text-[#0A1F44] underline' : 'text-[#718096]'}`}>Ending within 30 days</button>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50 text-left text-[#718096]">
                                <th className="px-4 py-3 font-medium">Exception</th>
                                <th className="px-4 py-3 font-medium">Policy / control</th>
                                <th className="px-4 py-3 font-medium">Risk</th>
                                <th className="px-4 py-3 font-medium">Term</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Decision</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {exceptions.data.length === 0 && <tr><td colSpan={6} className="px-4 py-12 text-center text-[#718096]">No exceptions match.</td></tr>}
                            {exceptions.data.map((e) => {
                                const status = e.status === 'approved' && isPast(e.expires_on) ? 'expired' : e.status;
                                return (
                                    <tr key={e.id} className="align-top hover:bg-gray-50/50">
                                        <td className="px-4 py-3 max-w-sm">
                                            <span className="font-mono-data text-xs text-[#718096]">{e.exception_code}</span>
                                            <p className="font-medium text-[#2D3748]">{e.title}</p>
                                            <p className="text-[11px] text-[#718096]">{e.justification}</p>
                                            {e.compensating_controls && <p className="mt-1 text-[11px] text-[#2D7D46]">Compensating: {e.compensating_controls}</p>}
                                            <p className="mt-1 text-[11px] text-[#718096]">Requested by {e.requester?.name || '—'}{e.owner && e.owner.id !== e.requester?.id ? ` · owner ${e.owner.name}` : ''}</p>
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            <Link href={route('policies.show', e.policy_id)} className="text-[#1A365D] hover:underline">{e.policy?.policy_code}</Link>
                                            <p className="text-[#718096]">{e.control ? `${e.control.control_code} ${e.control.title}` : 'Whole policy'}</p>
                                        </td>
                                        <td className="px-4 py-3 text-xs capitalize">{e.risk_rating}</td>
                                        <td className="px-4 py-3 text-xs text-[#718096] whitespace-nowrap">{fmtDate(e.starts_on) || 'on approval'} → {fmtDate(e.expires_on)}</td>
                                        <td className="px-4 py-3"><PolicyStatus status={status} />
                                            {e.decider && <p className="mt-1 text-[11px] text-[#718096]">{e.decider.name}{e.decision_notes ? `: ${e.decision_notes}` : ''}</p>}
                                        </td>
                                        <td className="px-4 py-3">{can.decide && <Decision exception={e} me={me} />}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                    <p className="text-xs text-[#718096]">{exceptions.total} matching</p>
                    <Pagination links={exceptions.links} />
                </div>
            </div>

            <RequestModal show={requesting} onClose={() => setRequesting(false)} policies={policies} controls={controls} risks={risks} users={users} riskRatings={riskRatings} maxMonths={maxMonths} presetPolicy={filters.policy_id} />
        </AuthenticatedLayout>
    );
}

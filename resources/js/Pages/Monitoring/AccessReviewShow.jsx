import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { fmtDate, fmtDateTime, Pill } from '@/Components/Monitoring/format';

function Decide({ item }) {
    const [decision, setDecision] = useState(null);
    const form = useForm({ decision: '', comment: '' });
    const submit = (d, comment = '') => router.post(route('access-reviews.items.decide', item.id), { decision: d, comment }, { preserveScroll: true, onSuccess: () => setDecision(null) });
    if (decision) {
        return (
            <form onSubmit={(e) => { e.preventDefault(); submit(decision, form.data.comment); }} className="flex flex-col gap-1">
                <input autoFocus value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} placeholder={`Why ${decision}? *`} className="rounded-lg border-gray-300 text-xs" />
                <div className="flex gap-1"><button type="submit" className="rounded bg-[#0A1F44] px-2 py-1 text-[11px] text-white">Confirm {decision}</button><button type="button" onClick={() => setDecision(null)} className="text-[11px] text-[#718096]">cancel</button></div>
            </form>
        );
    }
    return (
        <div className="flex gap-1">
            <button onClick={() => submit('keep')} className="rounded border border-[#2D7D46] px-2 py-0.5 text-[11px] text-[#2D7D46]">Keep</button>
            <button onClick={() => setDecision('revoke')} className="rounded border border-[#C53030] px-2 py-0.5 text-[11px] text-[#C53030]">Revoke</button>
            <button onClick={() => setDecision('modify')} className="rounded border border-[#8A6400] px-2 py-0.5 text-[11px] text-[#8A6400]">Modify</button>
        </div>
    );
}

function AddItem({ campaign }) {
    const form = useForm({ account_name: '', entitlement: '', privileged: false });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('access-reviews.items.store', campaign.id), { preserveScroll: true, onSuccess: () => form.reset() }); }} className="mb-3 flex flex-wrap items-end gap-2 rounded-xl border border-gray-100 bg-white p-3 text-xs shadow-sm">
            <label className="text-[11px] text-[#718096]">Account<input value={form.data.account_name} onChange={(e) => form.setData('account_name', e.target.value)} className="mt-1 block rounded-lg border-gray-300 text-xs" />{err('account_name')}</label>
            <label className="text-[11px] text-[#718096]">Entitlement<input value={form.data.entitlement} onChange={(e) => form.setData('entitlement', e.target.value)} className="mt-1 block rounded-lg border-gray-300 text-xs" />{err('entitlement')}</label>
            <label className="flex items-center gap-1 text-[11px] text-[#718096]"><input type="checkbox" checked={form.data.privileged} onChange={(e) => form.setData('privileged', e.target.checked)} className="rounded border-gray-300" /> privileged</label>
            <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white">Add entitlement</button>
        </form>
    );
}

export default function AccessReviewShow({ campaign, items = [], counts = {}, filter = null, me, can = {} }) {
    const errors = usePage().props.errors || {};
    const open = campaign.status === 'in_progress';
    const go = (f) => router.get(route('access-reviews.show', campaign.id), f ? { filter: f } : {}, { preserveState: true, preserveScroll: true, replace: true });
    const pct = counts.total ? Math.round((counts.decided / counts.total) * 100) : 0;
    return (
        <AuthenticatedLayout header="Access Reviews">
            <Head title={campaign.campaign_code} />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Access Reviews', href: route('monitoring.access-reviews') }, { label: campaign.campaign_code }]}
                title={campaign.name}
                subtitle={`${campaign.campaign_code} · ${campaign.system} · ${campaign.category} · ${campaign.cadence.replace('_', '-')} · entitlements from ${campaign.source_label}`}
                actions={open && can.complete && <button onClick={() => router.post(route('access-reviews.complete', campaign.id), {}, { preserveScroll: true })} disabled={counts.decided < counts.total || counts.total === 0} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-40">Complete campaign</button>}
            />
            {(errors.review || errors.comment) && <p className="mb-3 rounded-lg bg-[#FEE2E2] px-3 py-2 text-xs text-[#C53030]">{errors.review || errors.comment}</p>}
            <div className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm md:col-span-2">
                    <div className="flex items-center justify-between text-xs"><Pill value={campaign.overdue ? 'overdue' : campaign.status} /><span className={campaign.overdue ? 'text-[#C53030]' : 'text-[#718096]'}>{open ? `due ${fmtDate(campaign.due_at)}` : `completed ${fmtDateTime(campaign.completed_at)} by ${campaign.completer?.name || '—'}`}</span></div>
                    <div className="mt-3 h-2 overflow-hidden rounded bg-gray-100"><div className="h-2 bg-[#0A1F44]" style={{ width: `${pct}%` }} /></div>
                    <p className="mt-1 text-xs text-[#718096]">{counts.decided}/{counts.total} decided · {counts.keep} kept · {counts.revoke} to remove · {counts.privileged} privileged</p>
                </div>
                <div className="rounded-xl border border-gray-100 bg-white p-4 text-xs shadow-sm"><p className="text-[#718096]">Reviewer</p><p className="font-semibold text-[#2D3748]">{campaign.reviewer?.name || '—'}</p><p className="mt-1 text-[#718096]">Owner: {campaign.owner?.name || '—'}</p></div>
                <div className="rounded-xl border border-gray-100 bg-white p-4 text-xs shadow-sm"><p className="text-[#718096]">Removals to confirm</p><p className={`text-2xl font-bold ${counts.to_confirm ? 'text-[#8A6400]' : 'text-[#0A1F44]'}`}>{counts.to_confirm}</p></div>
            </div>
            {open && can.decide && campaign.source === 'manual' && <AddItem campaign={campaign} />}
            <div className="mb-3 flex flex-wrap gap-2 text-xs">
                {[['All', null], ['Undecided', 'undecided'], ['Privileged', 'privileged'], ['Removal to confirm', 'to_confirm']].map(([label, f]) => (
                    <button key={label} onClick={() => go(f)} className={`rounded-lg border px-3 py-1 ${filter === f ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>{label}</button>
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]"><tr className="text-left text-xs uppercase text-[#718096]"><th className="px-3 py-2">Account</th><th className="px-3 py-2">Entitlement</th><th className="px-3 py-2">Last login</th><th className="px-3 py-2">Decision</th><th className="px-3 py-2">Removal</th></tr></thead>
                    <tbody className="divide-y divide-gray-100">
                        {items.length === 0 && <tr><td colSpan={5} className="px-3 py-6 text-center text-xs text-[#718096]">No entitlements{filter ? ' match' : ' yet'}.</td></tr>}
                        {items.map((i) => {
                            const stale = i.last_login_at && (Date.now() - new Date(i.last_login_at)) / 86400000 > 90;
                            const removal = ['revoke', 'modify'].includes(i.decision);
                            return (
                                <tr key={i.id}>
                                    <td className="px-3 py-2"><p className="font-mono text-xs text-[#2D3748]">{i.account_name}</p><p className="text-xs text-[#718096]">{i.user?.name || <span className="text-[#C53030]">no matching user</span>}</p></td>
                                    <td className="px-3 py-2 text-xs">{i.entitlement}{i.privileged && <span className="ml-1 rounded bg-[#FEE2E2] px-1 text-[10px] text-[#C53030]">privileged</span>}</td>
                                    <td className={`px-3 py-2 text-xs ${stale ? 'text-[#C53030]' : 'text-[#718096]'}`}>{i.last_login_at ? fmtDate(i.last_login_at) : 'never'}{stale && <p className="text-[10px]">over 90 days</p>}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {i.decision ? <><Pill value={i.decision} /><p className="text-[#718096]">{i.decider?.name}, {fmtDate(i.decided_at)}</p>{i.comment && <p className="text-[#4A5568]">{i.comment}</p>}</> : open && can.decide ? (i.user_id === me ? <span className="text-[11px] text-[#718096]">Your own access: another reviewer decides</span> : <Decide item={i} />) : <span className="text-[#A0AEC0]">undecided</span>}
                                        {i.decision && open && can.decide && i.user_id !== me && !i.revocation_confirmed_at && <button onClick={() => router.post(route('access-reviews.items.decide', i.id), { decision: i.decision === 'keep' ? 'revoke' : 'keep', comment: i.decision === 'keep' ? 'Changed on second review.' : '' }, { preserveScroll: true })} className="text-[10px] text-[#718096] underline">change</button>}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {!removal ? '—' : i.revocation_confirmed_at ? <span className="text-[#2D7D46]">done · {i.confirmer?.name}, {fmtDate(i.revocation_confirmed_at)}</span>
                                            : can.decide && i.decided_by !== me ? <button onClick={() => router.post(route('access-reviews.items.confirm', i.id), {}, { preserveScroll: true })} className="rounded border border-[#0A1F44] px-2 py-0.5 text-[11px] text-[#0A1F44]">Confirm removed</button>
                                                : <span className="text-[#8A6400]">awaiting IT</span>}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

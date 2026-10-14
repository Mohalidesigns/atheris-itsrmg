import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { fmtDate, Pill } from '@/Components/Monitoring/format';

function Launch({ users, assets, options, onDone }) {
    const form = useForm({ name: '', system: '', asset_id: '', category: 'application', cadence: 'quarterly', source: 'atheris', reviewer_id: '', due_at: '' });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    const input = 'mt-1 w-full rounded-lg border-gray-300 text-xs';
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('access-reviews.store'), { onSuccess: onDone }); }} className="mb-4 grid grid-cols-1 gap-2 rounded-xl border border-gray-100 bg-white p-4 shadow-sm md:grid-cols-4">
            <label className="text-[11px] text-[#718096] md:col-span-2">Campaign name *<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Q4 privileged access — Finacle" className={input} />{err('name')}</label>
            <label className="text-[11px] text-[#718096]">System *<input value={form.data.system} onChange={(e) => form.setData('system', e.target.value)} placeholder="e.g. Finacle Core Banking" className={input} />{err('system')}</label>
            <label className="text-[11px] text-[#718096]">Asset<select value={form.data.asset_id} onChange={(e) => form.setData('asset_id', e.target.value)} className={input}><option value="">—</option>{assets.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select>{err('asset_id')}</label>
            <label className="text-[11px] text-[#718096]">Entitlements from<select value={form.data.source} onChange={(e) => form.setData('source', e.target.value)} className={input}>{Object.entries(options.sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
            <label className="text-[11px] text-[#718096]">Category<select value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} className={input}>{options.categories.map((c) => <option key={c}>{c}</option>)}</select></label>
            <label className="text-[11px] text-[#718096]">Cadence<select value={form.data.cadence} onChange={(e) => form.setData('cadence', e.target.value)} className={input}>{options.cadences.map((c) => <option key={c} value={c}>{c.replace('_', '-')}</option>)}</select></label>
            <label className="text-[11px] text-[#718096]">Reviewer *<select value={form.data.reviewer_id} onChange={(e) => form.setData('reviewer_id', e.target.value)} className={input}><option value="">Choose…</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>{err('reviewer_id')}</label>
            <label className="text-[11px] text-[#718096]">Due *<input type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} className={input} />{err('due_at')}</label>
            <div className="flex items-end md:col-span-3"><button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-xs font-semibold text-white disabled:opacity-50">Launch campaign</button></div>
        </form>
    );
}

export default function AccessReviews({ campaigns = [], metrics = {}, users = [], assets = [], options = {}, filters = {}, canCreate = false }) {
    const [launching, setLaunching] = useState(false);
    const go = (status) => router.get(route('monitoring.access-reviews'), status ? { status } : {}, { preserveState: true, replace: true });
    const completed = campaigns.filter((c) => c.status === 'completed').length;
    return (
        <AuthenticatedLayout header="Access Reviews">
            <Head title="Access Reviews" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Access Reviews' }]}
                title="User Access Reviews"
                subtitle="Recertification campaigns: a reviewer keeps, revokes or modifies each entitlement, nobody certifies their own access, and IT confirms every removal."
                actions={canCreate && <button onClick={() => setLaunching((v) => !v)} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">{launching ? 'Cancel' : 'Launch campaign'}</button>}
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="In progress" value={metrics.reviews_in_progress ?? 0} tone="navy" />
                <KpiCard label="Overdue" value={metrics.reviews_overdue ?? 0} tone={metrics.reviews_overdue ? 'red' : 'white'} />
                <KpiCard label="Revocations to confirm" value={metrics.revocations_pending ?? 0} tone={metrics.revocations_pending ? 'amber' : 'white'} />
                <KpiCard label="Completed (listed)" value={completed} tone="green" />
            </div>
            {launching && <Launch users={users} assets={assets} options={options} onDone={() => setLaunching(false)} />}
            <div className="mb-3 flex flex-wrap gap-2 text-xs">
                {[['All', null], ['In progress', 'in_progress'], ['Overdue', 'overdue'], ['Completed', 'completed']].map(([label, s]) => (
                    <button key={label} onClick={() => go(s)} className={`rounded-lg border px-3 py-1 ${(filters.status || null) === s ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>{label}</button>
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Campaign</th><th className="px-3 py-2">Cadence</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Progress</th><th className="px-3 py-2">Removals</th><th className="px-3 py-2">Due</th><th className="px-3 py-2">Reviewer</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {campaigns.length === 0 && <tr><td colSpan={7} className="px-3 py-6 text-center text-xs text-[#718096]">No campaigns match.</td></tr>}
                        {campaigns.map((c) => {
                            const pct = c.items_count ? Math.round((c.decided_count / c.items_count) * 100) : 0;
                            return (
                                <tr key={c.id}>
                                    <td className="px-3 py-2"><Link href={route('access-reviews.show', c.id)} className="text-[#0A1F44] hover:underline">{c.name}</Link><p className="text-xs text-[#718096]"><span className="font-mono">{c.campaign_code}</span> · {c.system} · {c.category}</p></td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.cadence.replace('_', '-')}</td>
                                    <td className="px-3 py-2"><Pill value={c.overdue ? 'overdue' : c.status} /></td>
                                    <td className="px-3 py-2 w-40"><div className="h-2 overflow-hidden rounded bg-gray-100"><div className="h-2 bg-[#0A1F44]" style={{ width: `${pct}%` }} /></div><p className="text-[11px] text-[#718096]">{c.decided_count}/{c.items_count} decided</p></td>
                                    <td className="px-3 py-2 text-xs">{c.revoke_count}{c.pending_confirmation_count > 0 && <span className="text-[#8A6400]"> ({c.pending_confirmation_count} to confirm)</span>}</td>
                                    <td className={`px-3 py-2 text-xs ${c.overdue ? 'text-[#C53030]' : 'text-[#4A5568]'}`}>{c.status === 'completed' ? `done ${fmtDate(c.completed_at)}` : fmtDate(c.due_at)}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.reviewer?.name || '—'}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

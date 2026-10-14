import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import Pagination from '@/Components/Pagination';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { fmtDateTime, Pill, severityTone } from '@/Components/Monitoring/format';

function RecordDrift({ assets, onDone }) {
    const form = useForm({ asset_id: '', title: '', severity: 'medium', baseline_value: '', observed_value: '', description: '' });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('drift.store'), { onSuccess: onDone }); }} className="mb-4 grid grid-cols-1 gap-2 rounded-xl border border-gray-100 bg-white p-4 shadow-sm md:grid-cols-3">
            <label className="text-[11px] text-[#718096]">Asset *<select value={form.data.asset_id} onChange={(e) => form.setData('asset_id', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs"><option value="">Choose…</option>{assets.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select>{err('asset_id')}</label>
            <label className="text-[11px] text-[#718096] md:col-span-2">Deviation *<input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="e.g. Audit logging disabled on the Finacle app server" className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('title')}</label>
            <label className="text-[11px] text-[#718096]">Severity<select value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs">{['critical', 'high', 'medium', 'low'].map((s) => <option key={s}>{s}</option>)}</select></label>
            <label className="text-[11px] text-[#718096]">Baseline<input value={form.data.baseline_value} onChange={(e) => form.setData('baseline_value', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" /></label>
            <label className="text-[11px] text-[#718096]">Observed *<input value={form.data.observed_value} onChange={(e) => form.setData('observed_value', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('observed_value')}</label>
            <textarea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="How it was found" className="rounded-lg border-gray-300 text-xs md:col-span-2" />
            <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Record drift</button>
        </form>
    );
}

export default function Drift({ events, buckets = {}, metrics = {}, sources = {}, scanSources = [], assets = [], filters = {}, can = {} }) {
    const [recording, setRecording] = useState(false);
    const go = (f) => router.get(route('monitoring.drift'), { ...filters, ...f }, { preserveState: true, replace: true });
    const scan = (source) => router.post(route('drift.scan'), { source }, { preserveScroll: true });
    const sortBy = (col) => go({ sort: col, direction: filters.sort === col && filters.direction !== 'asc' ? 'asc' : 'desc' });
    return (
        <AuthenticatedLayout header="Drift Detection">
            <Head title="Drift Detection" />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Drift Detection' }]}
                title="Configuration Drift"
                subtitle="Deviations from each asset's configuration baseline. A deviation inside the window of an approved change that lists the asset is authorised; anything else must be remediated or accepted."
                actions={can.create && <button onClick={() => setRecording((v) => !v)} className="rounded-lg border border-[#0A1F44] px-3 py-2 text-sm text-[#0A1F44]">{recording ? 'Cancel' : 'Record drift'}</button>}
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Open" value={buckets.open || 0} tone={buckets.open ? 'red' : 'white'} />
                <KpiCard label="Investigating" value={buckets.investigating || 0} tone="amber" />
                <KpiCard label="Authorised by a change" value={buckets.authorised || 0} tone="navy" />
                <KpiCard label="Remediated" value={buckets.remediated || 0} tone="green" />
                <KpiCard label="Risk accepted" value={buckets.accepted || 0} tone="white" />
            </div>
            {recording && <RecordDrift assets={assets} onDone={() => setRecording(false)} />}
            {can.scan && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-white p-3 text-xs shadow-sm">
                    <span className="text-[#718096]">Scan now (sandbox — no live connector):</span>
                    {scanSources.map((s) => <button key={s} onClick={() => scan(s)} className="rounded-lg border border-gray-200 px-3 py-1 text-[#0A1F44] hover:bg-gray-50">{sources[s]}</button>)}
                    <span className="ml-auto text-[#718096]">{metrics.drift_30d ?? 0} detected in 30 days, {metrics.drift_authorised_30d ?? 0} authorised</span>
                </div>
            )}
            <div className="mb-3 flex flex-wrap gap-2 text-xs">
                <select value={filters.status || ''} onChange={(e) => go({ status: e.target.value || undefined })} className="rounded-lg border-gray-300 text-xs">
                    <option value="">All statuses</option><option value="unresolved">Unresolved</option>
                    {['open', 'investigating', 'authorised', 'remediated', 'accepted'].map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
                <select value={filters.severity || ''} onChange={(e) => go({ severity: e.target.value || undefined })} className="rounded-lg border-gray-300 text-xs">
                    <option value="">All severities</option>{['critical', 'high', 'medium', 'low'].map((s) => <option key={s}>{s}</option>)}
                </select>
                <select value={filters.source || ''} onChange={(e) => go({ source: e.target.value || undefined })} className="rounded-lg border-gray-300 text-xs">
                    <option value="">All sources</option>{Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2 cursor-pointer" onClick={() => sortBy('drift_code')}>Ref</th>
                            <th className="px-3 py-2">Deviation</th>
                            <th className="px-3 py-2 cursor-pointer" onClick={() => sortBy('severity')}>Severity</th>
                            <th className="px-3 py-2 cursor-pointer" onClick={() => sortBy('detected_at')}>Detected</th>
                            <th className="px-3 py-2 cursor-pointer" onClick={() => sortBy('status')}>Status</th>
                            <th className="px-3 py-2">Owner</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {events.data.length === 0 && <tr><td colSpan={6} className="px-3 py-6 text-center text-xs text-[#718096]">No drift matches.</td></tr>}
                        {events.data.map((d) => (
                            <tr key={d.id}>
                                <td className="px-3 py-2"><Link href={route('drift.show', d.id)} className="font-mono text-xs text-[#0A1F44] hover:underline">{d.drift_code}</Link></td>
                                <td className="px-3 py-2"><p className="text-[#2D3748]">{d.title}</p><p className="text-xs text-[#718096]">{d.asset?.name} · {sources[d.source] || d.source}</p></td>
                                <td className="px-3 py-2"><Pill value={d.severity} tones={severityTone} /></td>
                                <td className="px-3 py-2 text-xs text-[#4A5568]">{fmtDateTime(d.detected_at)}</td>
                                <td className="px-3 py-2"><Pill value={d.status} />{d.change && <p className="text-[11px] text-[#718096]">{d.change.change_code}</p>}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{d.owner?.name || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="mt-3"><Pagination links={events.links} /></div>
        </AuthenticatedLayout>
    );
}

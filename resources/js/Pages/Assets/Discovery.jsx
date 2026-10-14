import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { CriticalityBadge, sourceLabels, typeLabels } from '@/Components/Assets/AssetBadges';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { ArrowPathIcon, CloudIcon, ServerIcon, ShieldCheckIcon, CodeBracketIcon, DocumentArrowUpIcon, IdentificationIcon, ArrowDownTrayIcon } from '@/Components/Material/Symbols';

const icons = { ad: ShieldCheckIcon, entra: IdentificationIcon, aws: CloudIcon, tenable: CodeBracketIcon, qualys: CodeBracketIcon, csv: DocumentArrowUpIcon };
const fmt = (v) => (v ? new Date(v).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');

function QueueRow({ asset, users, criticalities, canReview }) {
    const form = useForm({ decision: 'accept', owner_id: '', criticality: asset.criticality, reason: '' });
    const [rejecting, setRejecting] = useState(false);
    const submit = (decision) => {
        form.transform((d) => ({ ...d, decision }));
        form.post(route('assets.review', asset.id), { preserveScroll: true });
    };

    return (
        <tr className="align-top">
            <td className="px-3 py-2">
                <Link href={route('assets.show', asset.id)} className="font-mono-data text-xs text-[#1A365D] hover:underline">{asset.asset_id_code}</Link>
                <p className="text-sm text-[#2D3748]">{asset.name}</p>
                <p className="text-[11px] text-[#718096]">{[asset.hostname, asset.ip_address, asset.vendor, asset.version].filter(Boolean).join(' · ')}</p>
            </td>
            <td className="px-3 py-2 text-xs text-[#718096]">{typeLabels[asset.asset_type] || asset.asset_type}</td>
            <td className="px-3 py-2 text-xs text-[#718096]">{sourceLabels[asset.source] || asset.source}<br /><span className="font-mono-data">{asset.external_id}</span></td>
            <td className="px-3 py-2 text-xs text-[#718096]">{fmt(asset.last_seen_at)}</td>
            <td className="px-3 py-2">
                {canReview ? (
                    rejecting ? (
                        <div className="flex gap-2">
                            <input value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Why is it not the bank's?" className="w-48 rounded-lg border-gray-300 text-xs" />
                            <button type="button" onClick={() => submit('reject')} disabled={form.processing} className="rounded-lg border border-[#C53030]/40 px-2 text-xs text-[#C53030]">Reject</button>
                            <button type="button" onClick={() => setRejecting(false)} className="text-xs text-[#718096]">Cancel</button>
                        </div>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            <select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className="w-40 rounded-lg border-gray-300 text-xs">
                                <option value="">Owner…</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                            <select value={form.data.criticality} onChange={(e) => form.setData('criticality', e.target.value)} className="rounded-lg border-gray-300 text-xs">
                                {criticalities.map((c) => <option key={c} value={c}>{c}</option>)}
                            </select>
                            <button type="button" onClick={() => submit('accept')} disabled={form.processing} className="rounded-lg bg-[#2D7D46] px-2 py-1 text-xs font-semibold text-white">Accept</button>
                            <button type="button" onClick={() => setRejecting(true)} className="text-xs text-[#C53030] underline">Reject…</button>
                        </div>
                    )
                ) : <CriticalityBadge criticality={asset.criticality} />}
                {Object.values(form.errors).length > 0 && <p className="mt-1 text-[11px] text-[#C53030]">{Object.values(form.errors).join(' ')}</p>}
            </td>
        </tr>
    );
}

export default function AssetDiscovery({ jobs = [], connectors = [], metrics = {}, queue = [], users = [], criticalities = [], csvColumns = [], can = {} }) {
    const [running, setRunning] = useState(null);
    const [openJob, setOpenJob] = useState(null);
    const upload = useForm({ file: null });

    const trigger = (key) => {
        setRunning(key);
        router.post(route('asset-discovery.sync'), { source: key }, { preserveScroll: true, onFinish: () => setRunning(null) });
    };
    const importCsv = (e) => {
        e.preventDefault();
        upload.post(route('asset-discovery.import'), { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() });
    };

    return (
        <AuthenticatedLayout header="Asset Discovery">
            <Head title="Asset Discovery" />
            <PageHeader
                breadcrumbs={[{ label: 'Asset Management' }, { label: 'Asset Discovery' }]}
                title="Asset Discovery"
                subtitle="Connector runs and CSV imports reconcile into the register: known records are re-confirmed, hostname/IP matches are de-duplicated, and anything new waits here for an owner to accept it."
            />

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Found by discovery" value={metrics.discovered ?? 0} sublabel={`of ${metrics.total ?? 0} live assets`} tone="navy" icon={ServerIcon} />
                <KpiCard label="Awaiting review" value={metrics.pending_review ?? 0} sublabel="new finds without an owner" tone={metrics.pending_review ? 'amber' : 'white'} />
                <KpiCard label="Stale" value={metrics.stale ?? 0} sublabel={`not seen for 30 days`} tone="white" />
                <KpiCard label="Unowned" value={metrics.unowned ?? 0} sublabel="live assets" tone="white" />
                <KpiCard label="Last run" value={jobs[0] ? (sourceLabels[jobs[0].source] || jobs[0].source) : '—'} sublabel={fmt(jobs[0]?.finished_at)} tone="white" />
            </div>

            <div className="mb-2 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-[#2D3748]">Connectors</h3>
                <span className="text-[11px] text-[#718096]">Sandbox mode: no live credentials are configured, so each connector reads a catalogue built from this bank's estate plus hosts it has not registered.</span>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                {connectors.map((c) => {
                    const Icon = icons[c.key] || ServerIcon;
                    return (
                        <div key={c.key} className="flex flex-col rounded-xl border border-gray-200 bg-white p-4">
                            <div className="flex items-center justify-between">
                                <Icon className="w-6 h-6 text-[#0A1F44]" />
                                <span className="rounded bg-[#FFF8E6] px-1.5 text-[10px] font-semibold uppercase text-[#8A6400]">{c.mode}</span>
                            </div>
                            <p className="text-sm font-semibold text-[#2D3748] mt-2">{c.label}</p>
                            <p className="text-xs text-[#718096] mt-1">{c.assets} assets from this source · last run {fmt(c.last_run)}</p>
                            {can.sync && (
                                <button type="button" onClick={() => trigger(c.key)} disabled={running !== null}
                                    className="mt-3 inline-flex items-center justify-center gap-1 rounded-lg border border-[#0A1F44] px-2 py-1.5 text-xs font-semibold text-[#0A1F44] hover:bg-[#0A1F44] hover:text-white disabled:opacity-50">
                                    <ArrowPathIcon className={`w-3 h-3 ${running === c.key ? 'animate-spin' : ''}`} /> {running === c.key ? 'Running…' : 'Run sync'}
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>

            {can.sync && (
                <form onSubmit={importCsv} className="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 className="text-sm font-semibold text-[#2D3748]">Bulk CSV import</h3>
                            <p className="text-xs text-[#718096]">Columns: {csvColumns.join(', ')}. <strong>name</strong> and <strong>asset_type</strong> are required; owners are matched by e-mail within this bank. Bad rows are skipped and listed in the job.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <a href={route('asset-discovery.template')} className="inline-flex items-center gap-1 text-xs text-[#0A1F44] underline"><ArrowDownTrayIcon className="h-3 w-3" /> Template</a>
                            <input type="file" accept=".csv,text/csv" onChange={(e) => upload.setData('file', e.target.files[0])} className="text-xs" />
                            <button type="submit" disabled={!upload.data.file || upload.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Import</button>
                        </div>
                    </div>
                    {upload.errors.file && <p className="mt-2 text-xs text-[#C53030]">{upload.errors.file}</p>}
                </form>
            )}

            <div className="mb-6 bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Review queue</h3>
                    <span className="text-xs text-[#718096]">{queue.length} discovered asset{queue.length === 1 ? '' : 's'} not yet in the governed register</span>
                </div>
                {queue.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-[#718096]">Nothing waiting. New finds from a connector run land here.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Asset</th><th className="px-3 py-2">Type</th><th className="px-3 py-2">Source</th><th className="px-3 py-2">Last seen</th><th className="px-3 py-2">Decision</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {queue.map((a) => <QueueRow key={a.id} asset={a} users={users} criticalities={criticalities} canReview={can.review} />)}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 border-b border-gray-100"><h3 className="text-sm font-semibold text-[#2D3748]">Sync history</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Source</th><th className="px-3 py-2">Mode</th><th className="px-3 py-2 text-right">Seen</th><th className="px-3 py-2 text-right">New</th>
                                <th className="px-3 py-2 text-right">Matched</th><th className="px-3 py-2 text-right">Rejected</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Run by</th><th className="px-3 py-2">Finished</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {jobs.length === 0 && <tr><td colSpan={9} className="px-3 py-8 text-center text-[#718096]">No connector has run for this bank yet.</td></tr>}
                            {jobs.map((j) => (
                                <Fragment key={j.id}>
                                    <tr className={j.errors?.length ? 'cursor-pointer hover:bg-gray-50' : ''} onClick={() => j.errors?.length && setOpenJob(openJob === j.id ? null : j.id)}>
                                        <td className="px-3 py-2 font-medium text-[#0A1F44]">{sourceLabels[j.source] || j.source}{j.file_name && <span className="ml-1 text-xs font-normal text-[#718096]">({j.file_name})</span>}</td>
                                        <td className="px-3 py-2 text-xs uppercase text-[#718096]">{j.mode}</td>
                                        <td className="px-3 py-2 text-right">{j.records_imported}</td>
                                        <td className="px-3 py-2 text-right">{j.records_created}</td>
                                        <td className="px-3 py-2 text-right">{j.records_updated}</td>
                                        <td className={`px-3 py-2 text-right ${j.records_skipped ? 'font-semibold text-[#C53030]' : ''}`}>{j.records_skipped}{j.errors?.length ? ' ▾' : ''}</td>
                                        <td className="px-3 py-2 text-xs capitalize">{j.status}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{j.runner?.name || 'Scheduled'}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{fmt(j.finished_at)}</td>
                                    </tr>
                                    {openJob === j.id && (
                                        <tr><td colSpan={9} className="bg-[#FFF5F5] px-6 py-2">
                                            <ul className="list-disc pl-4 text-xs text-[#C53030]">{j.errors.map((e, i) => <li key={i}>{e}</li>)}</ul>
                                        </td></tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

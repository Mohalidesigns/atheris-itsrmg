import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { fmtDate, fmtDateTime, Pill } from '@/Components/Monitoring/format';

const sevTone = { critical: ['error', 'keyboard_double_arrow_up'], high: ['warning', 'keyboard_arrow_up'], moderate: ['info', 'drag_handle'], low: ['neutral', 'keyboard_arrow_down'] };
const snapTone = { ok: ['success', 'check_circle'], findings: ['warning', 'warning'], failed: ['error', 'cancel'] };

const Card = ({ title, children }) => (
    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
        <h3 className="mb-2 text-sm font-semibold text-[#2D3748]">{title}</h3>
        {children}
    </div>
);
const Row = ({ k, v }) => (
    <div className="flex justify-between gap-3 border-b border-gray-50 py-1 text-xs"><span className="text-[#718096]">{k}</span><span className="text-right text-[#2D3748]">{v ?? '—'}</span></div>
);
const kv = (o) => Object.entries(o || {}).map(([k, v]) => <Row key={k} k={k.replace(/_/g, ' ')} v={Array.isArray(v) ? v.join(', ') : String(v ?? '—')} />);

export default function Show({ integration, latest, history = [], issue, can = {} }) {
    const { errors = {} } = usePage().props;
    const a = latest?.artefacts || {};
    return (
        <AuthenticatedLayout header={integration.name}>
            <Head title={integration.name} />
            <PageHeader
                breadcrumbs={[{ label: 'Core Banking', href: route('core-banking.index') }, { label: integration.name }]}
                title={integration.name}
                subtitle={`${integration.product} · ${integration.environment} · Sandbox · last sync ${fmtDateTime(integration.last_sync_at)}`}
                actions={can.edit && (
                    <button onClick={() => router.post(route('core-banking.sync', integration.id), {}, { preserveScroll: true })} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white">Sync technical metadata</button>
                )}
            />
            {errors.sync && <p className="mb-3 rounded-lg border border-[#C53030]/30 bg-[#FFF5F5] px-3 py-2 text-xs text-[#C53030]">{errors.sync}</p>}
            {integration.last_error && <p className="mb-3 text-xs text-[#C53030]">{integration.last_error}</p>}
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <Card title="Links">
                    <Row k="Asset" v={integration.asset ? <Link href={route('assets.show', integration.asset.id)} className="underline">{integration.asset.asset_id_code} · {integration.asset.name}</Link> : null} />
                    <Row k="Owner" v={integration.owner?.name} />
                    <Row k="Issue" v={issue ? <Link href={route('issues.show', issue.id)} className="underline">{issue.issue_code} ({issue.status})</Link> : null} />
                </Card>
                <Card title="Platform">{latest ? kv(a.platform) : <p className="text-xs text-[#718096]">No capture yet.</p>}
                    {a.database && <Row k="database" v={a.database} />}{a.branch_count != null && <Row k="branches" v={a.branch_count} />}
                    {a.modules && <Row k="modules" v={a.modules.join(', ')} />}{a.channels && <Row k="channels" v={a.channels.join(', ')} />}
                </Card>
                <Card title="Operations and access">{kv(a.eod)}{kv(a.access)}
                    {a.message_success_rate != null && <><Row k="success rate" v={`${a.message_success_rate}%`} /><Row k="timeout rate" v={`${a.timeout_rate}%`} /><Row k="certificate expiry" v={fmtDate(a.certificate_expiry)} /></>}
                </Card>
                <Card title="Interfaces">
                    {(a.interfaces || []).map((i) => <Row key={i.name} k={`${i.name} (${i.protocol})`} v={`TLS ${i.tls}`} />)}
                </Card>
                <Card title={`Findings (${(latest?.findings || []).length})`}>
                    {(latest?.findings || []).map((f, idx) => (
                        <div key={idx} className="border-b border-gray-50 py-1.5 text-xs"><Pill value={f.severity} tones={sevTone} /> <span className="font-medium text-[#2D3748]">{f.title}</span><p className="text-[#718096]">{f.detail}</p></div>
                    ))}
                    {!(latest?.findings || []).length && <p className="text-xs text-[#718096]">None.</p>}
                </Card>
                <Card title="Changes since previous capture">
                    {(latest?.changes || []).map((c) => <Row key={c.key} k={c.key} v={`${c.from} → ${c.to}`} />)}
                    {!(latest?.changes || []).length && <p className="text-xs text-[#718096]">No changes.</p>}
                    <p className="mt-3 text-[11px] text-[#718096]">Embargoed keys withheld: {(latest?.embargoed_keys || []).join(', ') || 'none'}</p>
                </Card>
            </div>
            <div className="mt-3 overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-100 text-xs">
                    <thead className="bg-[#F7FAFC] text-left uppercase text-[#718096]"><tr><th className="px-3 py-2">Captured</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Findings</th><th className="px-3 py-2">Changes</th></tr></thead>
                    <tbody className="divide-y divide-gray-100">
                        {history.map((h) => (
                            <tr key={h.id}><td className="px-3 py-2">{fmtDateTime(h.captured_at)}</td><td className="px-3 py-2"><Pill value={h.status} tones={snapTone} /></td><td className="px-3 py-2">{(h.findings || []).length}</td><td className="px-3 py-2">{(h.changes || []).length}</td></tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

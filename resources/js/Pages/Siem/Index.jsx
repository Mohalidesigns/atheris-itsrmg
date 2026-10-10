import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate } from '@/Utils/risk';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';

export default function SiemIndex({ integrations = [], signals = [], stats = {}, newSecret = null, filters = {} }) {
    const { auth } = usePage().props;
    const can = (p) => (auth?.user?.permissions || []).includes(p) || (auth?.user?.roles || []).includes('Super Admin');
    const [expanded, setExpanded] = useState(null);
    const apply = (next) => router.get(route('siem.index'), { ...filters, ...next }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header="SIEM Integrations">
            <Head title="SIEM Integrations" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'SIEM Integrations' }]}
                title="SIEM / SOAR Integrations"
                subtitle="Connectors push signals to an HMAC-signed webhook. Personal data is masked on ingest, and high or critical signals raise a Security Alert."
            />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Connectors" value={stats.connectors ?? 0} tone="white" />
                <KpiCard label="Degraded" value={stats.degraded ?? 0} tone={stats.degraded ? 'amber' : 'white'} />
                <KpiCard label="Signals (24h)" value={stats.signals_24h ?? 0} tone="white" />
                <KpiCard label="High/critical untriaged" value={stats.untriaged ?? 0} tone={stats.untriaged ? 'red' : 'white'} />
            </div>

            <div className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                {integrations.length === 0 && (
                    <p className="rounded-xl border border-gray-100 bg-white p-4 text-sm text-[#718096] shadow-sm md:col-span-2 xl:col-span-4">No SIEM connectors are configured for this bank.</p>
                )}
                {integrations.map((i) => (
                    <div key={i.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div className="flex items-start justify-between gap-2">
                            <h3 className="text-sm font-semibold text-[#2D3748]">{i.label}</h3>
                            <StatusBadge status={i.status === 'active' ? 'active' : 'warn'} label={i.status} />
                        </div>
                        <p className="mt-1 text-xs text-[#718096]">Last signal {formatDate(i.last_signal_at, true)} · {i.signals_24h} in 24h</p>
                        <p className="mt-2 break-all font-mono text-[10px] text-[#4A5568]">POST {i.ingest_url}</p>
                        <p className="mt-1 text-[10px] text-[#718096]">{i.has_secret ? 'Webhook secret set' : 'No webhook secret: deliveries are rejected'}</p>
                        {newSecret?.integration_id === i.id && (
                            <div className="mt-2 rounded bg-[#C9A86A]/15 p-2">
                                <p className="text-[10px] font-semibold text-[#8A6400]">New secret (shown once):</p>
                                <p className="break-all font-mono text-[11px] text-[#2D3748]">{newSecret.secret}</p>
                            </div>
                        )}
                        {can('edit platform') && (
                            <button type="button"
                                onClick={() => confirm(i.has_secret ? 'Rotate the secret? The connector must be updated or its deliveries will be rejected.' : 'Issue a webhook secret?')
                                    && router.post(route('siem.integrations.secret', i.id), {}, { preserveScroll: true })}
                                className="mt-2 text-xs text-[#0A1F44] underline">
                                {i.has_secret ? 'Rotate secret' : 'Issue secret'}
                            </button>
                        )}
                    </div>
                ))}
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="flex flex-wrap items-center gap-2 p-4">
                    <h3 className="mr-auto text-sm font-semibold text-[#2D3748]">Recent signals</h3>
                    <select value={filters.severity || ''} onChange={(e) => apply({ severity: e.target.value || undefined })}
                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                        <option value="">All severities</option>
                        {['critical', 'high', 'medium', 'low', 'info'].map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                    <select value={filters.state || ''} onChange={(e) => apply({ state: e.target.value || undefined })}
                        className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                        <option value="">All signals</option>
                        <option value="untriaged">Untriaged</option>
                        <option value="alerted">Alert or incident raised</option>
                    </select>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Received</th>
                                <th className="px-3 py-2">Source</th>
                                <th className="px-3 py-2">Signal</th>
                                <th className="px-3 py-2">Severity</th>
                                <th className="px-3 py-2">Triage</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {signals.length === 0 && <tr><td colSpan={5} className="px-3 py-8 text-center text-[#718096]">No signals match.</td></tr>}
                            {signals.map((s) => (
                                <Fragment key={s.id}>
                                    <tr className="hover:bg-gray-50">
                                        <td className="px-3 py-2 text-xs whitespace-nowrap text-[#718096]">{formatDate(s.received_at, true)}</td>
                                        <td className="px-3 py-2 text-xs text-[#0A1F44]">{s.provider}</td>
                                        <td className="px-3 py-2">
                                            <button type="button" onClick={() => setExpanded(expanded === s.id ? null : s.id)} className="text-left text-[#2D3748] hover:underline">
                                                {s.title}
                                            </button>
                                            <span className="block font-mono text-[10px] text-[#718096]">{s.external_id || `#${s.id}`}</span>
                                        </td>
                                        <td className="px-3 py-2"><StatusBadge status={s.severity} /></td>
                                        <td className="px-3 py-2 text-xs whitespace-nowrap">
                                            {s.incident ? (
                                                <Link href={route('incidents.show', s.incident.id)} className="text-[#0A1F44] underline">{s.incident.code}</Link>
                                            ) : s.alert ? (
                                                <Link href={route('security-alerts.show', s.alert.id)} className="text-[#0A1F44] underline">{s.alert.code} · {s.alert.status}</Link>
                                            ) : can('create security-alerts') ? (
                                                <button type="button" onClick={() => router.post(route('siem.signals.alert', s.id))}
                                                    className="rounded border border-[#0A1F44]/20 px-2 py-1 text-[#0A1F44] hover:bg-[#0A1F44]/5">
                                                    Raise alert
                                                </button>
                                            ) : <span className="text-[#718096]">Untriaged</span>}
                                        </td>
                                    </tr>
                                    {expanded === s.id && (
                                        <tr>
                                            <td colSpan={5} className="bg-[#F7FAFC] px-3 py-2">
                                                <pre className="overflow-x-auto whitespace-pre-wrap text-[11px] text-[#2D3748]">{JSON.stringify(s.payload || {}, null, 2)}</pre>
                                            </td>
                                        </tr>
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

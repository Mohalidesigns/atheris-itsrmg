import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import Icon from '@/Components/Material/Icon';
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
                {integrations.map((i) => <ConnectorCard key={i.id} integration={i} newSecret={newSecret?.integration_id === i.id ? newSecret.secret : null} canEdit={can('edit platform')} />)}
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

function ConnectorCard({ integration: i, newSecret, canEdit }) {
    const [copied, setCopied] = useState(false);
    const copy = () => navigator.clipboard?.writeText(i.ingest_url).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); });
    const status = i.status === 'active' ? 'active' : i.status === 'disabled' ? 'disabled' : 'degraded';
    return (
        <div className="flex flex-col rounded-2xl bg-white p-4 shadow-sm ring-1 ring-black/5">
            <div className="flex items-start justify-between gap-2">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#DDE3F0] text-[#0A1F44]">
                    <Icon name="sensors" size={22} />
                </span>
                <StatusBadge status={status} label={i.status} />
            </div>
            <h3 className="mt-3 truncate text-base font-medium text-[#1B1B1F]" title={i.label}>{i.label}</h3>
            <p className="flex items-center gap-1 text-xs text-[#43474E]"><Icon name="schedule" size={14} />Last signal {formatDate(i.last_signal_at, true)}</p>

            <p className="mt-3 flex items-baseline gap-1.5">
                <span className="text-2xl font-semibold tabular-nums text-[#1B1B1F]">{i.signals_24h}</span>
                <span className="text-xs text-[#43474E]">signals in 24h</span>
            </p>

            <div className="mt-3 flex items-center gap-2 rounded-lg bg-[#F0F1F4] py-1 pl-2 pr-1">
                <span className="rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold text-[#0A1F44]">POST</span>
                <span className="min-w-0 flex-1 truncate font-mono text-[11px] text-[#43474E]" title={i.ingest_url}>{i.ingest_url}</span>
                <button type="button" onClick={copy} aria-label="Copy ingest URL" title={copied ? 'Copied' : 'Copy ingest URL'}
                    className="flex h-7 w-7 items-center justify-center rounded-full text-[#43474E] hover:bg-[#0A1F44]/[0.08]">
                    <Icon name={copied ? 'check' : 'content_copy'} size={16} />
                </button>
            </div>

            <p className={`mt-2 flex items-center gap-1 text-xs ${i.has_secret ? 'text-[#43474E]' : 'text-[#B3261E]'}`}>
                <Icon name={i.has_secret ? 'lock' : 'lock_open'} size={16} fill={i.has_secret} />
                {i.has_secret ? 'Webhook secret set' : 'No webhook secret: deliveries are rejected'}
            </p>

            {newSecret && (
                <div className="mt-2 rounded-lg bg-[#F3E9D2] p-2">
                    <p className="text-[11px] font-medium text-[#5E4818]">New secret (shown once):</p>
                    <p className="break-all font-mono text-[11px] text-[#1B1B1F]">{newSecret}</p>
                </div>
            )}

            {canEdit && (
                <div className="mt-auto pt-3">
                    <button type="button"
                        onClick={() => confirm(i.has_secret ? 'Rotate the secret? The connector must be updated or its deliveries will be rejected.' : 'Issue a webhook secret?')
                            && router.post(route('siem.integrations.secret', i.id), {}, { preserveScroll: true })}
                        className="-ml-3 flex h-9 items-center gap-1.5 rounded-full px-3 text-sm font-medium text-[#0A1F44] hover:bg-[#0A1F44]/[0.08]">
                        <Icon name="key" size={18} />
                        {i.has_secret ? 'Rotate secret' : 'Issue secret'}
                    </button>
                </div>
            )}
        </div>
    );
}

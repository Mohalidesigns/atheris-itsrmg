import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { formatDate, humanize } from '@/Utils/risk';
import { Head, Link } from '@inertiajs/react';
import { BellAlertIcon, BugAntIcon, ClockIcon, FireIcon, ShieldExclamationIcon } from '@/Components/Material/Symbols';

const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
const SEVERITY_COLOR = { critical: '#B3261E', high: '#E5A100', medium: '#1D4ED8', low: '#718096', info: '#A0AEC0' };

function hours(h) {
    if (h === null || h === undefined) return '—';
    return h >= 48 ? `${(h / 24).toFixed(1)} d` : `${h} h`;
}

function Panel({ title, href, linkLabel, children }) {
    return (
        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <div className="mb-3 flex items-start justify-between gap-2">
                <h3 className="text-sm font-semibold text-[#2D3748]">{title}</h3>
                {href && <Link href={href} className="whitespace-nowrap text-xs text-[#0A1F44] underline">{linkLabel}</Link>}
            </div>
            {children}
        </div>
    );
}

export default function SecurityOpsDashboard({ stats = {}, responseTimes = {}, topPriorities = [], clocks = [], activeIncidents = [], newAlerts = [], severityMix = {} }) {
    const mixTotal = SEVERITIES.reduce((n, s) => n + Number(severityMix[s] || 0), 0);

    return (
        <AuthenticatedLayout header="Security Operations">
            <Head title="Security Operations Dashboard" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Dashboard' }]}
                title="Security Operations Dashboard"
                subtitle="Exposure, detection and response, and the regulatory clocks running against them."
            />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Link href={route('vulnerabilities.index', { status: 'outstanding' })}>
                    <KpiCard label="Open findings" value={stats.vulns_outstanding ?? 0} sublabel={`${stats.vulns_critical ?? 0} critical · ${stats.vulns_high ?? 0} high`} tone="white" icon={BugAntIcon} />
                </Link>
                <Link href={route('vuln-prioritiser.index', { kev: 1 })}>
                    <KpiCard label="Known exploited" value={stats.vulns_kev ?? 0} sublabel={`${stats.vulns_p1 ?? 0} at P1`} tone={stats.vulns_kev ? 'red' : 'white'} icon={FireIcon} />
                </Link>
                <Link href={route('vulnerabilities.index', { overdue: 1 })}>
                    <KpiCard label="Past SLA" value={stats.vulns_sla_breached ?? 0} sublabel="remediation overdue" tone={stats.vulns_sla_breached ? 'amber' : 'white'} icon={ClockIcon} />
                </Link>
                <Link href={route('incidents.index', { status: 'active' })}>
                    <KpiCard label="Active incidents" value={stats.incidents_active ?? 0} sublabel={`${stats.incidents_critical ?? 0} critical`} tone={stats.incidents_critical ? 'red' : 'navy'} icon={ShieldExclamationIcon} />
                </Link>
                <Link href={route('security-alerts.index', { status: 'new' })}>
                    <KpiCard label="New alerts" value={stats.alerts_new ?? 0} sublabel={`${stats.alerts_new_high ?? 0} high or critical`} tone="white" icon={BellAlertIcon} />
                </Link>
                <Link href={route('notifications.index', { status: 'overdue' })}>
                    <KpiCard label="Notifications overdue" value={stats.notifications_overdue ?? 0} sublabel={`${stats.notifications_open ?? 0} open · ${stats.ndpa_pending ?? 0} NDPA pending`} tone={stats.notifications_overdue ? 'red' : 'white'} />
                </Link>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Panel title="Fix first: top risk-ranked findings" href={route('vuln-prioritiser.index')} linkLabel="Prioritiser →">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs uppercase text-[#718096]">
                                        <th className="py-2 pr-3">Score</th>
                                        <th className="py-2 pr-3">Finding</th>
                                        <th className="py-2 pr-3">Signals</th>
                                        <th className="py-2">Due</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {topPriorities.length === 0 && (
                                        <tr><td colSpan={4} className="py-6 text-center text-[#718096]">No outstanding findings.</td></tr>
                                    )}
                                    {topPriorities.map((v) => (
                                        <tr key={v.id}>
                                            <td className="py-2 pr-3">
                                                <span className="font-mono font-bold text-[#0A1F44]">{v.score}</span>
                                                <span className="ml-1 rounded bg-[#0A1F44] px-1.5 py-0.5 text-[10px] text-white">{v.band}</span>
                                            </td>
                                            <td className="py-2 pr-3">
                                                <Link href={route('vulnerabilities.show', v.id)} className="text-[#0A1F44] hover:underline">
                                                    <span className="font-mono text-xs">{v.vuln_id_code}</span> {v.title}
                                                </Link>
                                                <p className="text-xs text-[#718096]">{v.cve_id || 'No CVE'}{v.asset ? ` · ${v.asset.name}` : ''}</p>
                                            </td>
                                            <td className="py-2 pr-3 text-xs">
                                                <div className="flex flex-wrap gap-1">
                                                    <StatusBadge status={v.severity} />
                                                    {v.kev && <StatusBadge status="critical" label={v.ransomware ? 'KEV · ransomware' : 'KEV'} />}
                                                    {v.epss !== null && <span className="text-[#718096]">EPSS {(v.epss * 100).toFixed(0)}%</span>}
                                                </div>
                                            </td>
                                            <td className={`py-2 text-xs ${v.sla_breached ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>
                                                {formatDate(v.due_date)}{v.sla_breached ? ' · overdue' : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Panel>
                </div>

                <Panel title="Regulatory clocks" href={route('notifications.index')} linkLabel="Notifications →">
                    <ul className="divide-y divide-gray-100 text-xs">
                        {clocks.length === 0 && <li className="py-3 text-[#718096]">No regulatory deadlines running.</li>}
                        {clocks.map((c) => (
                            <li key={`${c.kind}-${c.id}`} className="py-2">
                                <Link href={c.href} className="block hover:underline">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-medium text-[#2D3748]">{c.label}</span>
                                        <StatusBadge status={c.overdue ? 'critical' : c.status} label={c.overdue ? 'Overdue' : humanize(c.status)} />
                                    </div>
                                    <p className="truncate text-[#718096]">{c.subject}</p>
                                    <p className={c.overdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}>Deadline {formatDate(c.deadline_at, true)}</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Panel>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Panel title="Active incidents" href={route('incidents.index', { status: 'active' })} linkLabel="All active →">
                    <ul className="divide-y divide-gray-100 text-sm">
                        {activeIncidents.length === 0 && <li className="py-3 text-xs text-[#718096]">No active incidents.</li>}
                        {activeIncidents.map((i) => (
                            <li key={i.id} className="py-2">
                                <Link href={route('incidents.show', i.id)} className="text-[#0A1F44] hover:underline">
                                    <span className="font-mono text-xs">{i.incident_id_code}</span> {i.title}
                                </Link>
                                <div className="mt-1 flex flex-wrap items-center gap-1 text-xs text-[#718096]">
                                    <StatusBadge status={i.severity} />
                                    <span>{humanize(i.status)}</span>
                                    <span>· {i.assignee?.name || 'Unassigned'}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Panel>

                <Panel title="Alerts awaiting triage" href={route('security-alerts.index', { status: 'new' })} linkLabel="Alert queue →">
                    <ul className="divide-y divide-gray-100 text-sm">
                        {newAlerts.length === 0 && <li className="py-3 text-xs text-[#718096]">The alert queue is clear.</li>}
                        {newAlerts.map((a) => (
                            <li key={a.id} className="py-2">
                                <Link href={route('security-alerts.show', a.id)} className="text-[#0A1F44] hover:underline">
                                    <span className="font-mono text-xs">{a.alert_id_code}</span> {a.title}
                                </Link>
                                <div className="mt-1 flex items-center gap-1 text-xs text-[#718096]">
                                    <StatusBadge status={a.severity} />
                                    <span>{a.source || '—'} · {formatDate(a.received_at, true)}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                    {stats.siem_untriaged > 0 && (
                        <Link href={route('siem.index', { state: 'untriaged' })} className="mt-2 block text-xs text-[#B3261E] underline">
                            {stats.siem_untriaged} high/critical SIEM signals not yet triaged →
                        </Link>
                    )}
                </Panel>

                <Panel title={`Response performance (${responseTimes.window_days ?? 180} days)`}>
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        {[
                            ['Mean time to respond', hours(responseTimes.mttr_respond_h)],
                            ['Mean time to contain', hours(responseTimes.mttr_contain_h)],
                            ['Mean time to resolve', hours(responseTimes.mttr_resolve_h)],
                            ['Mean time to remediate a finding', responseTimes.vuln_mttr_days != null ? `${responseTimes.vuln_mttr_days} d` : '—'],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-lg border border-gray-100 px-3 py-2">
                                <dt className="text-[11px] text-[#718096]">{label}</dt>
                                <dd className="text-lg font-bold text-[#0A1F44]">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <h4 className="mt-4 text-xs font-semibold text-[#2D3748]">Open findings by severity</h4>
                    <div className="mt-2 flex h-3 overflow-hidden rounded-full bg-gray-100">
                        {SEVERITIES.map((s) => Number(severityMix[s] || 0) > 0 && (
                            <div key={s} title={`${s}: ${severityMix[s]}`} style={{ width: `${(severityMix[s] / Math.max(mixTotal, 1)) * 100}%`, background: SEVERITY_COLOR[s] }} />
                        ))}
                    </div>
                    <div className="mt-1 flex flex-wrap gap-x-3 text-[11px] text-[#718096]">
                        {SEVERITIES.map((s) => <span key={s} className="capitalize">{s} {severityMix[s] || 0}</span>)}
                    </div>
                    {stats.siem_degraded > 0 && (
                        <Link href={route('siem.index')} className="mt-3 block text-xs text-[#8A6400] underline">
                            {stats.siem_degraded} SIEM connector{stats.siem_degraded > 1 ? 's' : ''} degraded →
                        </Link>
                    )}
                </Panel>
            </div>
        </AuthenticatedLayout>
    );
}

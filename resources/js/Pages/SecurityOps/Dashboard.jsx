import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    ShieldExclamationIcon,
    ExclamationTriangleIcon,
    FireIcon,
    BoltIcon,
    ArrowRightIcon,
    PlusIcon,
} from '@heroicons/react/24/outline';

function StatCard({ title, value, color, icon: Icon, subtitle }) {
    const bgMap = {
        red: 'bg-[#C53030]', orange: 'bg-[#DD6B20]', gold: 'bg-[#D4AF37]',
        green: 'bg-[#2D7D46]', navy: 'bg-[#1A365D]', teal: 'bg-[#319795]',
    };
    return (
        <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
            <div className="flex items-center justify-between">
                <p className="text-xs text-[#718096] uppercase font-medium">{title}</p>
                {Icon && (
                    <span className={`w-8 h-8 rounded-lg flex items-center justify-center ${bgMap[color]}/10`}>
                        <Icon className="w-4 h-4" style={{ color: bgMap[color]?.replace('bg-[', '').replace(']', '') }} />
                    </span>
                )}
            </div>
            <div className="flex items-end gap-2 mt-1">
                <span className="text-2xl font-bold font-mono-data text-[#2D3748]">{value}</span>
                {color && <span className={`w-2 h-2 rounded-full mb-1.5 ${bgMap[color]}`} />}
            </div>
            {subtitle && <p className="text-xs text-[#718096] mt-0.5">{subtitle}</p>}
        </div>
    );
}

const severityColors = {
    critical: '#C53030',
    high: '#DD6B20',
    medium: '#D4AF37',
    low: '#2D7D46',
    info: '#319795',
};

function SeverityBadge({ severity }) {
    const color = severityColors[severity] || '#718096';
    return (
        <span
            className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize"
            style={{ backgroundColor: `${color}15`, color }}
        >
            {severity}
        </span>
    );
}

function StatusBadge({ status }) {
    const map = {
        open: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]' },
        in_progress: { bg: 'bg-[#319795]/10', text: 'text-[#319795]' },
        resolved: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]' },
        closed: { bg: 'bg-gray-100', text: 'text-gray-500' },
        mitigated: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]' },
        accepted: { bg: 'bg-[#D4AF37]/10', text: 'text-[#D4AF37]' },
    };
    const style = map[status] || map.open;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${style.bg} ${style.text}`}>
            {(status || '').replace(/_/g, ' ')}
        </span>
    );
}

export default function SecurityOpsDashboard({ stats, recentVulnerabilities, activeIncidents }) {
    return (
        <AuthenticatedLayout header="Security Operations">
            <Head title="Security Operations Dashboard" />

            {/* Stats Row */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <StatCard
                    title="Total Vulnerabilities"
                    value={stats.total_vulns}
                    color="navy"
                    icon={ShieldExclamationIcon}
                    subtitle={`${stats.overdue_vulns || 0} overdue`}
                />
                <StatCard
                    title="Critical Vulns"
                    value={stats.critical_vulns}
                    color="red"
                    icon={FireIcon}
                    subtitle={`${stats.high_vulns || 0} high`}
                />
                <StatCard
                    title="Open Incidents"
                    value={stats.open_incidents}
                    color="orange"
                    icon={ExclamationTriangleIcon}
                />
                <StatCard
                    title="Active Breaches"
                    value={stats.active_breaches || 0}
                    color="red"
                    icon={BoltIcon}
                />
            </div>

            {/* Actions */}
            <div className="flex gap-2 mb-6">
                <Link
                    href={route('vulnerabilities.index')}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                >
                    <ShieldExclamationIcon className="w-4 h-4" />
                    Vulnerabilities
                </Link>
                <Link
                    href={route('incidents.index')}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                >
                    <ExclamationTriangleIcon className="w-4 h-4" />
                    Incidents
                </Link>
            </div>

            {/* Bottom Row */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Recent Vulnerabilities */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-base font-semibold text-[#2D3748]">Recent Vulnerabilities</h3>
                        <Link href={route('vulnerabilities.index')} className="text-xs text-[#1A365D] hover:underline flex items-center gap-1">
                            View All <ArrowRightIcon className="w-3 h-3" />
                        </Link>
                    </div>
                    {recentVulnerabilities && recentVulnerabilities.length > 0 ? (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100">
                                        <th className="pb-2 text-left text-xs font-medium text-[#718096]">ID</th>
                                        <th className="pb-2 text-left text-xs font-medium text-[#718096]">Title</th>
                                        <th className="pb-2 text-left text-xs font-medium text-[#718096]">Severity</th>
                                        <th className="pb-2 text-left text-xs font-medium text-[#718096]">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {recentVulnerabilities.slice(0, 5).map(vuln => (
                                        <tr key={vuln.id} className="hover:bg-gray-50/50">
                                            <td className="py-2">
                                                <Link href={route('vulnerabilities.show', vuln.id)} className="font-mono-data text-xs text-[#1A365D] font-semibold hover:underline">
                                                    {vuln.vuln_id_code || `VULN-${String(vuln.id).padStart(4, '0')}`}
                                                </Link>
                                            </td>
                                            <td className="py-2">
                                                <Link href={route('vulnerabilities.show', vuln.id)} className="text-[#2D3748] hover:text-[#1A365D] truncate block max-w-[200px]">
                                                    {vuln.title}
                                                </Link>
                                            </td>
                                            <td className="py-2"><SeverityBadge severity={vuln.severity} /></td>
                                            <td className="py-2"><StatusBadge status={vuln.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <p className="text-sm text-[#718096] py-8 text-center">No vulnerabilities recorded yet.</p>
                    )}
                </div>

                {/* Active Incidents */}
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-base font-semibold text-[#2D3748]">Active Incidents</h3>
                        <Link href={route('incidents.index')} className="text-xs text-[#1A365D] hover:underline flex items-center gap-1">
                            View All <ArrowRightIcon className="w-3 h-3" />
                        </Link>
                    </div>
                    {activeIncidents && activeIncidents.length > 0 ? (
                        <div className="space-y-2">
                            {activeIncidents.slice(0, 5).map(incident => (
                                <div key={incident.id} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                                    <div className="flex items-center gap-3 min-w-0">
                                        <span className="font-mono-data text-xs text-[#1A365D] font-semibold shrink-0">
                                            {incident.incident_id_code || `INC-${String(incident.id).padStart(4, '0')}`}
                                        </span>
                                        <Link href={route('incidents.show', incident.id)} className="text-sm text-[#2D3748] hover:text-[#1A365D] truncate">
                                            {incident.title}
                                        </Link>
                                    </div>
                                    <div className="flex items-center gap-2 shrink-0">
                                        <SeverityBadge severity={incident.severity} />
                                        <StatusBadge status={incident.status} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-[#718096] py-8 text-center">No active incidents.</p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

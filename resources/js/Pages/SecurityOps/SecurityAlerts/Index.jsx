import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, BellAlertIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function SecurityAlertsIndex({ alerts, filters = {}, severities = [], statuses = [] }) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('security-alerts.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('security-alerts.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Security Alerts">
            <Head title="Security Alerts" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Security Alerts</h2>
                    <p className="text-sm text-[#718096] mt-1">
                        {alerts.total} alert{alerts.total !== 1 ? 's' : ''} · triage, investigate, and promote to incidents.
                    </p>
                </div>
                <Link href={route('security-alerts.create')}
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                    <PlusIcon className="w-4 h-4" /> Log Alert
                </Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 space-y-3">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Search alerts by title, ID, or description..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>
                    <div className="flex flex-wrap gap-3">
                        <select value={filters.severity || ''} onChange={e => applyFilter('severity', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Severities</option>
                            {severities.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                        </select>
                        <select value={filters.status || ''} onChange={e => applyFilter('status', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Statuses</option>
                            {statuses.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                        </select>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Alert ID</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Source</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Severity</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Incident</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Received</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {alerts.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        <BellAlertIcon className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                        No security alerts found.
                                    </td>
                                </tr>
                            ) : alerts.data.map(alert => (
                                <tr key={alert.id} className="hover:bg-gray-50/50 transition-colors">
                                    <td className="px-4 py-3">
                                        <Link href={route('security-alerts.show', alert.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                            {alert.alert_id_code}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={route('security-alerts.show', alert.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                            {alert.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-[#718096]">{alert.source || '--'}</td>
                                    <td className="px-4 py-3"><StatusBadge status={alert.severity} /></td>
                                    <td className="px-4 py-3"><StatusBadge status={alert.status} label={cap(alert.status)} /></td>
                                    <td className="px-4 py-3">
                                        {alert.incident ? (
                                            <Link href={route('incidents.show', alert.incident.id)} className="font-mono-data text-xs text-[#1A365D] hover:underline">
                                                {alert.incident.incident_id_code}
                                            </Link>
                                        ) : <span className="text-gray-400">--</span>}
                                    </td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">
                                        {alert.received_at ? new Date(alert.received_at).toLocaleString() : '--'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={alerts.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

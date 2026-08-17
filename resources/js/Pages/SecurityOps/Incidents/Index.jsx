import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';

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

function IncidentStatusBadge({ status }) {
    const map = {
        reported: { bg: 'bg-gray-100', text: 'text-gray-600' },
        triaged: { bg: 'bg-[#319795]/10', text: 'text-[#319795]' },
        investigating: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]' },
        containing: { bg: 'bg-[#D4AF37]/10', text: 'text-[#D4AF37]' },
        contained: { bg: 'bg-[#1A365D]/10', text: 'text-[#1A365D]' },
        eradicating: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]' },
        recovering: { bg: 'bg-[#319795]/10', text: 'text-[#319795]' },
        resolved: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]' },
        closed: { bg: 'bg-gray-100', text: 'text-gray-500' },
        post_incident: { bg: 'bg-[#1A365D]/10', text: 'text-[#1A365D]' },
    };
    const style = map[status] || map.reported;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${style.bg} ${style.text}`}>
            {(status || '').replace(/_/g, ' ')}
        </span>
    );
}

function TypeBadge({ type }) {
    return (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-[#1A365D]/5 text-[#1A365D] capitalize">
            {(type || '').replace(/_/g, ' ')}
        </span>
    );
}

export default function IncidentsIndex({ incidents, filters, statuses, types }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('incidents.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('incidents.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Security Incidents">
            <Head title="Security Incidents" />

            {/* Header Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <p className="text-sm text-[#718096]">
                        {incidents.total} incident{incidents.total !== 1 ? 's' : ''} recorded
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => setShowFilters(!showFilters)}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                    >
                        <FunnelIcon className="w-4 h-4" />
                        Filters
                    </button>
                    <Link
                        href={route('incidents.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                    >
                        <PlusIcon className="w-4 h-4" />
                        Report Incident
                    </Link>
                </div>
            </div>

            {/* Search & Filters */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
                <div className="p-4">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Search incidents by title or description..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">
                            Search
                        </button>
                    </form>

                    {showFilters && (
                        <div className="flex flex-wrap gap-3 mt-3 pt-3 border-t border-gray-100">
                            <select
                                value={filters.status || ''}
                                onChange={e => applyFilter('status', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Statuses</option>
                                {(statuses || ['reported', 'triaged', 'investigating', 'containing', 'contained', 'eradicating', 'recovering', 'resolved', 'closed', 'post_incident']).map(s => (
                                    <option key={s} value={s}>{s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>
                                ))}
                            </select>
                            <select
                                value={filters.severity || ''}
                                onChange={e => applyFilter('severity', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Severities</option>
                                {['critical', 'high', 'medium', 'low'].map(s => (
                                    <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                                ))}
                            </select>
                            <select
                                value={filters.type || ''}
                                onChange={e => applyFilter('type', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Types</option>
                                {(types || ['malware', 'phishing', 'data_leak', 'unauthorized_access', 'dos', 'insider_threat', 'other']).map(t => (
                                    <option key={t} value={t}>{t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Incident ID</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Type</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Severity</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Assigned</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Detected At</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {incidents.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        No incidents found. Report your first incident to get started.
                                    </td>
                                </tr>
                            ) : (
                                incidents.data.map(incident => (
                                    <tr key={incident.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('incidents.show', incident.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                                {incident.incident_id_code || `INC-${String(incident.id).padStart(4, '0')}`}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('incidents.show', incident.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {incident.title}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <TypeBadge type={incident.type} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <SeverityBadge severity={incident.severity} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <IncidentStatusBadge status={incident.status} />
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {incident.assignee?.name || '--'}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {incident.detected_at || '--'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={incidents.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Badge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon } from '@/Components/Material/Symbols';
import Pagination from '@/Components/Pagination';
import { formatDate } from '@/Utils/risk';

function SeverityBadge({ severity }) {
    return <Badge status={severity} />;
}

function StatusBadge({ status }) {
    return <Badge status={status || 'open'} />;
}

export default function VulnerabilitiesIndex({ vulnerabilities, filters, severities, statuses }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('vulnerabilities.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('vulnerabilities.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Vulnerabilities">
            <Head title="Vulnerabilities" />

            {/* Header Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <p className="text-sm text-[#718096]">
                        {vulnerabilities.total} vulnerabilit{vulnerabilities.total !== 1 ? 'ies' : 'y'} tracked
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
                        href={route('vulnerabilities.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                    >
                        <PlusIcon className="w-4 h-4" />
                        Add Vulnerability
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
                                placeholder="Search by title, CVE, or description..."
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
                                value={filters.severity || ''}
                                onChange={e => applyFilter('severity', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Severities</option>
                                {(severities || ['critical', 'high', 'medium', 'low', 'info']).map(s => (
                                    <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                                ))}
                            </select>
                            <select
                                value={filters.status || ''}
                                onChange={e => applyFilter('status', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Statuses</option>
                                {(statuses || ['outstanding', 'open', 'in_progress', 'remediated', 'accepted', 'false_positive']).map(s => (
                                    <option key={s} value={s}>{s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>
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
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Vuln ID</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Severity</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">CVSS</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Assigned</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Due Date</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {vulnerabilities.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        No vulnerabilities found. Add your first vulnerability to get started.
                                    </td>
                                </tr>
                            ) : (
                                vulnerabilities.data.map(vuln => (
                                    <tr key={vuln.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('vulnerabilities.show', vuln.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                                {vuln.vuln_id_code || `VULN-${String(vuln.id).padStart(4, '0')}`}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('vulnerabilities.show', vuln.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {vuln.title}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <SeverityBadge severity={vuln.severity} />
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="font-mono-data font-semibold text-[#2D3748]">
                                                {vuln.cvss_score != null ? Number(vuln.cvss_score).toFixed(1) : '--'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={vuln.status} />
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {vuln.assignee?.name || '--'}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {formatDate(vuln.due_date)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={vulnerabilities.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

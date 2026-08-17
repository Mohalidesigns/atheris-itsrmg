import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';

function NdpaStatusBadge({ breach }) {
    if (breach.ndpa_notified_at) {
        return (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-[#2D7D46]/10 text-[#2D7D46]">
                Notified
            </span>
        );
    }
    if (breach.ndpa_notification_required) {
        return (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-[#C53030]/10 text-[#C53030]">
                Required
            </span>
        );
    }
    return (
        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500">
            Not Required
        </span>
    );
}

function StatusBadge({ status }) {
    const map = {
        open: { bg: 'bg-[#DD6B20]/10', text: 'text-[#DD6B20]' },
        investigating: { bg: 'bg-[#319795]/10', text: 'text-[#319795]' },
        contained: { bg: 'bg-[#1A365D]/10', text: 'text-[#1A365D]' },
        notifying: { bg: 'bg-[#D4AF37]/10', text: 'text-[#D4AF37]' },
        remediated: { bg: 'bg-[#2D7D46]/10', text: 'text-[#2D7D46]' },
        closed: { bg: 'bg-gray-100', text: 'text-gray-500' },
    };
    const style = map[status] || map.open;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${style.bg} ${style.text}`}>
            {(status || '').replace(/_/g, ' ')}
        </span>
    );
}

export default function BreachesIndex({ breaches, filters }) {
    const [search, setSearch] = useState(filters?.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('breaches.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('breaches.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Data Breaches">
            <Head title="Data Breaches" />

            {/* Header Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <p className="text-sm text-[#718096]">
                        {breaches.total} breach{breaches.total !== 1 ? 'es' : ''} recorded
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
                        href={route('breaches.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                    >
                        <PlusIcon className="w-4 h-4" />
                        Report Breach
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
                                placeholder="Search breaches by title or description..."
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
                                value={filters?.status || ''}
                                onChange={e => applyFilter('status', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Statuses</option>
                                {['open', 'investigating', 'contained', 'notifying', 'remediated', 'closed'].map(s => (
                                    <option key={s} value={s}>{s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>
                                ))}
                            </select>
                            <select
                                value={filters?.ndpa || ''}
                                onChange={e => applyFilter('ndpa', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">NDPA Status</option>
                                <option value="required">Notification Required</option>
                                <option value="notified">Already Notified</option>
                                <option value="not_required">Not Required</option>
                            </select>
                        </div>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Breach ID</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Type</th>
                                <th className="px-4 py-3 text-right font-medium text-[#718096]">Records Affected</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">NDPA Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Assigned</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {breaches.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        No breaches found.
                                    </td>
                                </tr>
                            ) : (
                                breaches.data.map(breach => (
                                    <tr key={breach.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('breaches.show', breach.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                                {breach.breach_id_code || `BRC-${String(breach.id).padStart(4, '0')}`}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('breaches.show', breach.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {breach.title}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-[#1A365D]/5 text-[#1A365D] capitalize">
                                                {(breach.breach_type || '').replace(/_/g, ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <span className="font-mono-data font-semibold text-[#2D3748]">
                                                {breach.records_affected != null
                                                    ? Number(breach.records_affected).toLocaleString()
                                                    : '--'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <NdpaStatusBadge breach={breach} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={breach.status} />
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {breach.assignee?.name || '--'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={breaches.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

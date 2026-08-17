import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';

const riskLevelColors = {
    critical: { bg: '#FEE2E2', text: '#C53030' },
    high: { bg: '#FED7AA', text: '#DD6B20' },
    medium: { bg: '#FEF3C7', text: '#D4AF37' },
    low: { bg: '#D1FAE5', text: '#2D7D46' },
};

const statusColors = {
    active: { bg: '#D1FAE5', text: '#2D7D46' },
    inactive: { bg: '#E2E8F0', text: '#718096' },
    under_review: { bg: '#FEF3C7', text: '#D4AF37' },
    terminated: { bg: '#FEE2E2', text: '#C53030' },
};

function RiskLevelBadge({ level }) {
    const colors = riskLevelColors[level] || riskLevelColors.medium;
    return (
        <span className="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full capitalize"
            style={{ backgroundColor: colors.bg, color: colors.text }}>
            {level}
        </span>
    );
}

function VendorStatusBadge({ status }) {
    const colors = statusColors[status] || statusColors.active;
    return (
        <span className="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full capitalize"
            style={{ backgroundColor: colors.bg, color: colors.text }}>
            {status?.replace('_', ' ')}
        </span>
    );
}

export default function VendorsIndex({ vendors, filters, riskLevels, statuses }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('vendors.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('vendors.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Vendor Register">
            <Head title="Vendor Register" />

            {/* Header Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <p className="text-sm text-[#718096]">
                        {vendors.total} vendor{vendors.total !== 1 ? 's' : ''} registered
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
                        href={route('vendors.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                    >
                        <PlusIcon className="w-4 h-4" />
                        Add Vendor
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
                                placeholder="Search vendors by name, code, or contact..."
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
                                value={filters.risk_level || ''}
                                onChange={e => applyFilter('risk_level', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Risk Levels</option>
                                {riskLevels.map(r => (
                                    <option key={r} value={r}>{r.charAt(0).toUpperCase() + r.slice(1)}</option>
                                ))}
                            </select>
                            <select
                                value={filters.status || ''}
                                onChange={e => applyFilter('status', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Statuses</option>
                                {statuses.map(s => (
                                    <option key={s} value={s}>{s.replace('_', ' ').charAt(0).toUpperCase() + s.replace('_', ' ').slice(1)}</option>
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
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Vendor Code</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Name</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Risk Level</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Category</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Contract End</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Last Assessed</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {vendors.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        No vendors found. Register your first vendor to get started.
                                    </td>
                                </tr>
                            ) : (
                                vendors.data.map(vendor => (
                                    <tr key={vendor.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('vendors.show', vendor.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                                {vendor.vendor_code}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('vendors.show', vendor.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {vendor.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <RiskLevelBadge level={vendor.risk_level} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <VendorStatusBadge status={vendor.status} />
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {vendor.category || '--'}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {vendor.contract_end ? new Date(vendor.contract_end).toLocaleDateString() : '--'}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {vendor.last_assessed ? new Date(vendor.last_assessed).toLocaleDateString() : '--'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={vendors.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

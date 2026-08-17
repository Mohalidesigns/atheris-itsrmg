import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';

const criticalityColors = {
    critical: { bg: '#FEE2E2', text: '#C53030' },
    high: { bg: '#FED7AA', text: '#DD6B20' },
    medium: { bg: '#FEF3C7', text: '#D4AF37' },
    low: { bg: '#D1FAE5', text: '#2D7D46' },
};

const statusColors = {
    active: { bg: '#D1FAE5', text: '#2D7D46' },
    inactive: { bg: '#E2E8F0', text: '#718096' },
    decommissioned: { bg: '#FEE2E2', text: '#C53030' },
    under_review: { bg: '#FEF3C7', text: '#D4AF37' },
};

function CriticalityBadge({ criticality }) {
    const colors = criticalityColors[criticality] || criticalityColors.medium;
    return (
        <span className="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full capitalize"
            style={{ backgroundColor: colors.bg, color: colors.text }}>
            {criticality}
        </span>
    );
}

function AssetStatusBadge({ status }) {
    const colors = statusColors[status] || statusColors.active;
    return (
        <span className="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full capitalize"
            style={{ backgroundColor: colors.bg, color: colors.text }}>
            {status?.replace('_', ' ')}
        </span>
    );
}

const typeLabels = {
    hardware: 'Hardware',
    software: 'Software',
    cloud_service: 'Cloud Service',
    database: 'Database',
    network: 'Network',
    facility: 'Facility',
};

export default function AssetsIndex({ assets, filters, assetTypes, criticalities, statuses }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('assets.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('assets.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Asset Register">
            <Head title="Asset Register" />

            {/* Header Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <p className="text-sm text-[#718096]">
                        {assets.total} asset{assets.total !== 1 ? 's' : ''} registered
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
                        href={route('assets.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                    >
                        <PlusIcon className="w-4 h-4" />
                        Add Asset
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
                                placeholder="Search assets by name, ID, hostname, or IP..."
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
                                value={filters.asset_type || ''}
                                onChange={e => applyFilter('asset_type', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Types</option>
                                {assetTypes.map(t => (
                                    <option key={t} value={t}>{typeLabels[t] || t}</option>
                                ))}
                            </select>
                            <select
                                value={filters.criticality || ''}
                                onChange={e => applyFilter('criticality', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Criticalities</option>
                                {criticalities.map(c => (
                                    <option key={c} value={c}>{c.charAt(0).toUpperCase() + c.slice(1)}</option>
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
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Asset ID</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Name</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Type</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Criticality</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Department</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {assets.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        No assets found. Create your first asset to get started.
                                    </td>
                                </tr>
                            ) : (
                                assets.data.map(asset => (
                                    <tr key={asset.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('assets.show', asset.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                                {asset.asset_id_code}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('assets.show', asset.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {asset.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {typeLabels[asset.asset_type] || asset.asset_type}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <CriticalityBadge criticality={asset.criticality} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <AssetStatusBadge status={asset.status} />
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {asset.owner?.name || '--'}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {asset.department || '--'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={assets.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';

const statusColors = {
    draft: { bg: 'bg-gray-100', text: 'text-gray-700' },
    in_review: { bg: 'bg-yellow-50', text: 'text-yellow-700' },
    approved: { bg: 'bg-blue-50', text: 'text-blue-700' },
    published: { bg: 'bg-green-50', text: 'text-[#2D7D46]' },
    retired: { bg: 'bg-red-50', text: 'text-[#C53030]' },
};

function PolicyStatusBadge({ status }) {
    const colors = statusColors[status] || statusColors.draft;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colors.bg} ${colors.text}`}>
            {status?.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
        </span>
    );
}

export default function PoliciesIndex({ policies, filters, statuses, categories }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('policies.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('policies.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Policy Management">
            <Head title="Policy Management" />

            {/* Header Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <p className="text-sm text-[#718096]">
                        {policies.total} polic{policies.total !== 1 ? 'ies' : 'y'} registered
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
                        href={route('policies.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                    >
                        <PlusIcon className="w-4 h-4" />
                        Create Policy
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
                                placeholder="Search policies by title, code, or description..."
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
                                {statuses.map(s => (
                                    <option key={s} value={s}>
                                        {s.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                    </option>
                                ))}
                            </select>
                            <select
                                value={filters.category || ''}
                                onChange={e => applyFilter('category', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Categories</option>
                                {Object.entries(categories).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
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
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Policy Code</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Category</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Version</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Review Date</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {policies.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        No policies found. Create your first policy to get started.
                                    </td>
                                </tr>
                            ) : (
                                policies.data.map(policy => (
                                    <tr key={policy.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('policies.show', policy.id)} className="font-mono text-[#1A365D] font-medium hover:underline text-xs">
                                                {policy.policy_code}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('policies.show', policy.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {policy.title}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {categories[policy.category] || '--'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <PolicyStatusBadge status={policy.status} />
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="font-mono text-xs text-[#718096]">v{policy.version_number}</span>
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {policy.owner?.name || '--'}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096] text-xs">
                                            {policy.review_date || '--'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={policies.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

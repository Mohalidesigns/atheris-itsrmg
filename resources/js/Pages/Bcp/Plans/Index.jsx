import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';

const typeLabels = { bcp: 'BCP', dr: 'DR', crisis: 'Crisis' };
const typeColors = { bcp: '#1A365D', dr: '#2D7D46', crisis: '#C53030' };
const statusColors = {
    draft: '#718096',
    active: '#2D7D46',
    under_review: '#D4AF37',
    tested: '#1A365D',
    expired: '#C53030',
};

function TypeBadge({ type }) {
    return (
        <span
            className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full text-white"
            style={{ backgroundColor: typeColors[type] || '#718096' }}
        >
            {typeLabels[type] || type}
        </span>
    );
}

function StatusBadge({ status }) {
    return (
        <span
            className="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full"
            style={{
                backgroundColor: (statusColors[status] || '#718096') + '15',
                color: statusColors[status] || '#718096',
            }}
        >
            {status?.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
        </span>
    );
}

export default function PlansIndex({ plans, filters, planTypes, statuses }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('bcp.plans'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('bcp.plans'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="BCP/DR Plans">
            <Head title="BCP/DR Plans" />

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <p className="text-sm text-[#718096]">
                    {plans.total} plan{plans.total !== 1 ? 's' : ''} registered
                </p>
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => setShowFilters(!showFilters)}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                    >
                        <FunnelIcon className="w-4 h-4" />
                        Filters
                    </button>
                    <Link
                        href={route('bcp.plans.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] transition-colors"
                    >
                        <PlusIcon className="w-4 h-4" />
                        New Plan
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
                                placeholder="Search plans by title or code..."
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
                                value={filters.plan_type || ''}
                                onChange={e => applyFilter('plan_type', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Types</option>
                                {planTypes.map(t => (
                                    <option key={t} value={t}>{typeLabels[t] || t}</option>
                                ))}
                            </select>
                            <select
                                value={filters.status || ''}
                                onChange={e => applyFilter('status', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Statuses</option>
                                {statuses.map(s => (
                                    <option key={s} value={s}>{s.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>
            </div>

            {/* Table */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-100 bg-gray-50/50">
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Plan Code</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Title</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Type</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Status</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">RTO / RPO</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Last Tested</th>
                                <th className="text-left px-4 py-3 font-medium text-[#718096]">Next Review</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {plans.data.map(plan => (
                                <tr key={plan.id} className="hover:bg-gray-50/50 transition-colors">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('bcp.plans.show', plan.id)}
                                            className="font-mono text-sm font-semibold text-[#1A365D] hover:underline"
                                        >
                                            {plan.plan_code}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route('bcp.plans.show', plan.id)}
                                            className="text-[#2D3748] hover:text-[#1A365D]"
                                        >
                                            {plan.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3"><TypeBadge type={plan.plan_type} /></td>
                                    <td className="px-4 py-3"><StatusBadge status={plan.status} /></td>
                                    <td className="px-4 py-3 font-mono text-xs text-[#718096]">
                                        {plan.rto_hours != null ? `${plan.rto_hours}h` : '-'} / {plan.rpo_hours != null ? `${plan.rpo_hours}h` : '-'}
                                    </td>
                                    <td className="px-4 py-3 text-[#718096]">
                                        {plan.last_tested || '-'}
                                    </td>
                                    <td className="px-4 py-3 text-[#718096]">
                                        {plan.next_review_date || '-'}
                                    </td>
                                </tr>
                            ))}
                            {plans.data.length === 0 && (
                                <tr>
                                    <td colSpan="7" className="px-4 py-12 text-center text-[#718096]">
                                        No plans found. Create your first BCP/DR plan to get started.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={plans.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

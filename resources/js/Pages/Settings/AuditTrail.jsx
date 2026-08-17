import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    MagnifyingGlassIcon,
    FunnelIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    ClockIcon,
    UserIcon,
    DocumentTextIcon,
} from '@heroicons/react/24/outline';

function EventBadge({ description }) {
    const event = (description || '').toLowerCase();
    let color = 'bg-gray-100 text-gray-700';
    let label = description || 'Action';

    if (event.includes('created') || event.includes('create')) {
        color = 'bg-[#2D7D46]/10 text-[#2D7D46]';
        label = 'Created';
    } else if (event.includes('updated') || event.includes('update')) {
        color = 'bg-blue-50 text-blue-700';
        label = 'Updated';
    } else if (event.includes('deleted') || event.includes('delete')) {
        color = 'bg-[#C53030]/10 text-[#C53030]';
        label = 'Deleted';
    }

    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${color}`}>
            {label}
        </span>
    );
}

function ChangesPanel({ properties }) {
    const [expanded, setExpanded] = useState(false);

    if (!properties || (!properties.old && !properties.attributes)) {
        return <span className="text-xs text-[#718096]">--</span>;
    }

    const oldValues = properties.old || {};
    const newValues = properties.attributes || {};
    const allKeys = [...new Set([...Object.keys(oldValues), ...Object.keys(newValues)])];

    if (allKeys.length === 0) {
        return <span className="text-xs text-[#718096]">--</span>;
    }

    return (
        <div>
            <button
                onClick={() => setExpanded(!expanded)}
                className="flex items-center gap-1 text-xs text-[#1A365D] hover:text-[#2D4A7A] font-medium"
            >
                {expanded ? (
                    <ChevronUpIcon className="w-3.5 h-3.5" />
                ) : (
                    <ChevronDownIcon className="w-3.5 h-3.5" />
                )}
                {allKeys.length} field{allKeys.length !== 1 ? 's' : ''} changed
            </button>
            {expanded && (
                <div className="mt-2 space-y-1.5">
                    {allKeys.map((key) => (
                        <div key={key} className="text-xs bg-gray-50 rounded-lg p-2">
                            <span className="font-medium text-[#2D3748]">{key}</span>
                            {oldValues[key] !== undefined && (
                                <div className="mt-0.5">
                                    <span className="text-[#C53030] line-through">
                                        {typeof oldValues[key] === 'object'
                                            ? JSON.stringify(oldValues[key])
                                            : String(oldValues[key] ?? '--')}
                                    </span>
                                </div>
                            )}
                            {newValues[key] !== undefined && (
                                <div className="mt-0.5">
                                    <span className="text-[#2D7D46]">
                                        {typeof newValues[key] === 'object'
                                            ? JSON.stringify(newValues[key])
                                            : String(newValues[key] ?? '--')}
                                    </span>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function formatDateTime(dateString) {
    if (!dateString) return '--';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }) + ' ' + date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function getSubjectLabel(activity) {
    if (!activity.subject_type) return 'System';
    const baseName = activity.subject_type.split('\\').pop();
    return baseName + (activity.subject_id ? ` #${activity.subject_id}` : '');
}

export default function AuditTrail({ activities, users, subjectTypes, filters }) {
    const [filterValues, setFilterValues] = useState({
        search: filters?.search || '',
        causer_id: filters?.causer_id || '',
        subject_type: filters?.subject_type || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
    });
    const [showFilters, setShowFilters] = useState(
        !!(filters?.causer_id || filters?.subject_type || filters?.date_from || filters?.date_to)
    );

    const applyFilters = () => {
        const params = {};
        Object.entries(filterValues).forEach(([key, value]) => {
            if (value) params[key] = value;
        });
        router.get(route('settings.audit-trail'), params, { preserveState: true });
    };

    const clearFilters = () => {
        setFilterValues({
            search: '',
            causer_id: '',
            subject_type: '',
            date_from: '',
            date_to: '',
        });
        router.get(route('settings.audit-trail'), {}, { preserveState: true });
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') applyFilters();
    };

    const totalCount = activities?.total || 0;

    return (
        <AuthenticatedLayout header="Audit Trail">
            <Head title="Audit Trail" />

            {/* Header */}
            <div className="mb-6">
                <div className="flex items-center gap-3 mb-1">
                    <div className="p-2 bg-[#1A365D]/10 rounded-lg">
                        <ClockIcon className="w-6 h-6 text-[#1A365D]" />
                    </div>
                    <div>
                        <h1 className="text-xl font-bold text-[#2D3748]">Audit Trail</h1>
                        <p className="text-sm text-[#718096]">
                            {totalCount.toLocaleString()} activit{totalCount === 1 ? 'y' : 'ies'} recorded
                        </p>
                    </div>
                </div>
            </div>

            {/* Filter Bar */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
                <div className="p-4">
                    <div className="flex items-center gap-3">
                        {/* Search */}
                        <div className="flex-1 relative">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input
                                type="text"
                                placeholder="Search activity descriptions..."
                                value={filterValues.search}
                                onChange={(e) => setFilterValues({ ...filterValues, search: e.target.value })}
                                onKeyDown={handleKeyDown}
                                className="w-full pl-9 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            />
                        </div>

                        {/* Toggle Filters */}
                        <button
                            onClick={() => setShowFilters(!showFilters)}
                            className={`flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                                showFilters
                                    ? 'bg-[#1A365D] text-white'
                                    : 'bg-gray-100 text-[#718096] hover:bg-gray-200'
                            }`}
                        >
                            <FunnelIcon className="w-4 h-4" />
                            Filters
                        </button>

                        {/* Apply */}
                        <button
                            onClick={applyFilters}
                            className="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] transition-colors"
                        >
                            Apply
                        </button>
                    </div>

                    {/* Extended Filters */}
                    {showFilters && (
                        <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-4 border-t border-gray-100">
                            {/* User Filter */}
                            <div>
                                <label className="block text-xs font-medium text-[#718096] mb-1">User</label>
                                <select
                                    value={filterValues.causer_id}
                                    onChange={(e) => setFilterValues({ ...filterValues, causer_id: e.target.value })}
                                    className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                                >
                                    <option value="">All Users</option>
                                    {(users || []).map((user) => (
                                        <option key={user.id} value={user.id}>{user.name}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Entity Type Filter */}
                            <div>
                                <label className="block text-xs font-medium text-[#718096] mb-1">Entity Type</label>
                                <select
                                    value={filterValues.subject_type}
                                    onChange={(e) => setFilterValues({ ...filterValues, subject_type: e.target.value })}
                                    className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                                >
                                    <option value="">All Entities</option>
                                    {(subjectTypes || []).map((type) => (
                                        <option key={type} value={type}>{type}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Date From */}
                            <div>
                                <label className="block text-xs font-medium text-[#718096] mb-1">From Date</label>
                                <input
                                    type="date"
                                    value={filterValues.date_from}
                                    onChange={(e) => setFilterValues({ ...filterValues, date_from: e.target.value })}
                                    className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                                />
                            </div>

                            {/* Date To */}
                            <div>
                                <label className="block text-xs font-medium text-[#718096] mb-1">To Date</label>
                                <input
                                    type="date"
                                    value={filterValues.date_to}
                                    onChange={(e) => setFilterValues({ ...filterValues, date_to: e.target.value })}
                                    className="w-full px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                                />
                            </div>

                            {/* Clear Filters */}
                            <div className="sm:col-span-2 lg:col-span-4 flex justify-end">
                                <button
                                    onClick={clearFilters}
                                    className="text-xs text-[#718096] hover:text-[#C53030] font-medium"
                                >
                                    Clear all filters
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Activity Table */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead>
                            <tr className="bg-gray-50 border-b border-gray-100">
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Timestamp
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    User
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Action
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Entity
                                </th>
                                <th className="text-left px-4 py-3 text-xs font-semibold text-[#718096] uppercase tracking-wider">
                                    Changes
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {activities?.data?.length > 0 ? (
                                activities.data.map((activity) => (
                                    <tr key={activity.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <span className="text-xs font-mono text-[#718096]">
                                                {formatDateTime(activity.created_at)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <div className="w-6 h-6 bg-[#1A365D]/10 rounded-full flex items-center justify-center flex-shrink-0">
                                                    <UserIcon className="w-3.5 h-3.5 text-[#1A365D]" />
                                                </div>
                                                <span className="text-sm text-[#2D3748]">
                                                    {activity.causer?.name || 'System'}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <EventBadge description={activity.description} />
                                                <span className="text-sm text-[#718096] truncate max-w-[200px]">
                                                    {activity.description}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1.5">
                                                <DocumentTextIcon className="w-3.5 h-3.5 text-[#718096]" />
                                                <span className="text-sm text-[#2D3748]">
                                                    {getSubjectLabel(activity)}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <ChangesPanel properties={activity.properties} />
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={5} className="px-4 py-12 text-center">
                                        <ClockIcon className="w-10 h-10 text-gray-300 mx-auto" />
                                        <p className="text-sm text-[#718096] mt-2">No audit trail entries found</p>
                                        <p className="text-xs text-[#718096]">Activity will appear here as users interact with the system</p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {activities?.links && (
                    <div className="px-4 py-3 border-t border-gray-100">
                        <Pagination links={activities.links} />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

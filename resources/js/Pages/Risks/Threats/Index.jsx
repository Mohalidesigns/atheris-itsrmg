import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function ThreatsIndex({ threats, filters = {}, categories = [], severities = [], sources = [] }) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('threats.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('threats.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Threat Register">
            <Head title="Threat Register" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Threat Register</h2>
                    <p className="text-sm text-[#718096] mt-1">
                        {threats.total} threat{threats.total !== 1 ? 's' : ''} identified against your information assets.
                    </p>
                </div>
                <Link href={route('threats.create')}
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                    <PlusIcon className="w-4 h-4" /> Add Threat
                </Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 space-y-3">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Search threats by name, ID, or description..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>
                    <div className="flex flex-wrap gap-3">
                        <select value={filters.category || ''} onChange={e => applyFilter('category', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Categories</option>
                            {categories.map(c => <option key={c} value={c}>{cap(c)}</option>)}
                        </select>
                        <select value={filters.severity || ''} onChange={e => applyFilter('severity', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Severities</option>
                            {severities.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                        </select>
                        <select value={filters.source || ''} onChange={e => applyFilter('source', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Sources</option>
                            {sources.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                        </select>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Threat ID</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Name</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Category</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Source</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Likelihood</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Severity</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Assessments</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Active</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {threats.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-12 text-center text-[#718096]">
                                        <ExclamationTriangleIcon className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                        No threats found. Register your first threat to get started.
                                    </td>
                                </tr>
                            ) : threats.data.map(threat => (
                                <tr key={threat.id} className="hover:bg-gray-50/50 transition-colors">
                                    <td className="px-4 py-3">
                                        <Link href={route('threats.show', threat.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                            {threat.threat_id_code}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={route('threats.show', threat.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                            {threat.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-[#718096] capitalize">{threat.category || '--'}</td>
                                    <td className="px-4 py-3 text-[#718096] capitalize">{threat.source || '--'}</td>
                                    <td className="px-4 py-3 text-center font-mono-data">{threat.likelihood ?? '--'}</td>
                                    <td className="px-4 py-3">{threat.severity ? <StatusBadge status={threat.severity} /> : '--'}</td>
                                    <td className="px-4 py-3 text-center text-[#718096]">{threat.assessments_count}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={threat.is_active ? 'active' : 'disabled'} label={threat.is_active ? 'Active' : 'Inactive'} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={threats.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

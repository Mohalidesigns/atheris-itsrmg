import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, BuildingOfficeIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function BusinessAssetsIndex({ services, capabilities = [], filters = {}, criticalities = [] }) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('business-assets.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('business-assets.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Business Assets">
            <Head title="Business Assets" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Business Assets</h2>
                    <p className="text-sm text-[#718096] mt-1">
                        {services.total} business service{services.total !== 1 ? 's' : ''} mapped to enterprise capabilities.
                    </p>
                </div>
                <Link href={route('business-assets.create')}
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                    <PlusIcon className="w-4 h-4" /> Add Service
                </Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 space-y-3">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Search business services..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>
                    <div className="flex flex-wrap gap-3">
                        <select value={filters.criticality || ''} onChange={e => applyFilter('criticality', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Criticalities</option>
                            {criticalities.map(c => <option key={c} value={c}>{cap(c)}</option>)}
                        </select>
                        <select value={filters.capability_id || ''} onChange={e => applyFilter('capability_id', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Capabilities</option>
                            {capabilities.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Service</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Capability</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Criticality</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">RTO (min)</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">RPO (min)</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Processes</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {services.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12 text-center text-[#718096]">
                                        <BuildingOfficeIcon className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                        No business services registered yet.
                                    </td>
                                </tr>
                            ) : services.data.map(s => (
                                <tr key={s.id} className="hover:bg-gray-50/50 transition-colors">
                                    <td className="px-4 py-3">
                                        <Link href={route('business-assets.show', s.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                            {s.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-[#718096]">{s.capability?.name || '--'}</td>
                                    <td className="px-4 py-3"><StatusBadge status={s.criticality} label={cap(s.criticality)} /></td>
                                    <td className="px-4 py-3 text-center font-mono-data">{s.recovery_time_objective_min ?? '--'}</td>
                                    <td className="px-4 py-3 text-center font-mono-data">{s.recovery_point_objective_min ?? '--'}</td>
                                    <td className="px-4 py-3 text-center text-[#718096]">{s.processes_count ?? 0}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={services.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

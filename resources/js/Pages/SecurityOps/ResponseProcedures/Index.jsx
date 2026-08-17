import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, DocumentTextIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function ResponseProceduresIndex({ procedures, filters = {}, categories = [] }) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('response-procedures.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('response-procedures.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Response Procedures">
            <Head title="Response Procedures" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Response Procedures</h2>
                    <p className="text-sm text-[#718096] mt-1">
                        {procedures.total} playbook{procedures.total !== 1 ? 's' : ''} for incident response.
                    </p>
                </div>
                <Link href={route('response-procedures.create')}
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                    <PlusIcon className="w-4 h-4" /> New Procedure
                </Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 space-y-3">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Search procedures..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>
                    {categories.length > 0 && (
                        <div className="flex flex-wrap gap-3">
                            <select value={filters.category || ''} onChange={e => applyFilter('category', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                                <option value="">All Categories</option>
                                {categories.map(c => <option key={c} value={c}>{cap(c)}</option>)}
                            </select>
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Category</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Incident Types</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Steps</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Version</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Last Reviewed</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {procedures.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        <DocumentTextIcon className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                        No response procedures defined yet.
                                    </td>
                                </tr>
                            ) : procedures.data.map(p => (
                                <tr key={p.id} className="hover:bg-gray-50/50 transition-colors">
                                    <td className="px-4 py-3">
                                        <Link href={route('response-procedures.show', p.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                            {p.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-[#718096] capitalize">{cap(p.category) || '--'}</td>
                                    <td className="px-4 py-3 text-xs text-[#718096]">
                                        {(p.incident_types || []).map(t => cap(t)).join(', ') || '--'}
                                    </td>
                                    <td className="px-4 py-3 text-center font-mono-data">{(p.steps || []).length}</td>
                                    <td className="px-4 py-3 text-[#718096]">{p.version || '--'}</td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{p.last_reviewed ? new Date(p.last_reviewed).toLocaleDateString() : '--'}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={p.is_active ? 'active' : 'disabled'} label={p.is_active ? 'Active' : 'Inactive'} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={procedures.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

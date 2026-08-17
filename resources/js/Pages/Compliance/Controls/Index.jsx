import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, FunnelIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';

const effectColors = {
    effective: 'bg-green-50 text-green-700',
    partially_effective: 'bg-amber-50 text-amber-700',
    ineffective: 'bg-red-50 text-red-700',
    not_assessed: 'bg-gray-50 text-gray-500',
};

export default function ControlsIndex({ controls, filters, domains }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('controls.index'), { ...filters, search }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Control Library">
            <Head title="Control Library" />

            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <p className="text-sm text-[#718096]">{controls.total} control{controls.total !== 1 ? 's' : ''}</p>
                <div className="flex gap-2">
                    <button onClick={() => setShowFilters(!showFilters)}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        <FunnelIcon className="w-4 h-4" /> Filters
                    </button>
                    <Link href={route('controls.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                        <PlusIcon className="w-4 h-4" /> Add Control
                    </Link>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Search controls..." className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">Search</button>
                    </form>

                    {showFilters && (
                        <div className="flex flex-wrap gap-3 mt-3 pt-3 border-t border-gray-100">
                            <select value={filters.domain || ''} onChange={e => router.get(route('controls.index'), { ...filters, domain: e.target.value || undefined }, { preserveState: true })}
                                className="text-sm border-gray-200 rounded-lg">
                                <option value="">All Domains</option>
                                {domains.map(d => <option key={d} value={d}>{d}</option>)}
                            </select>
                            <select value={filters.type || ''} onChange={e => router.get(route('controls.index'), { ...filters, type: e.target.value || undefined }, { preserveState: true })}
                                className="text-sm border-gray-200 rounded-lg">
                                <option value="">All Types</option>
                                {['preventive','detective','corrective','deterrent'].map(t => <option key={t} value={t}>{t.charAt(0).toUpperCase()+t.slice(1)}</option>)}
                            </select>
                            <select value={filters.effectiveness || ''} onChange={e => router.get(route('controls.index'), { ...filters, effectiveness: e.target.value || undefined }, { preserveState: true })}
                                className="text-sm border-gray-200 rounded-lg">
                                <option value="">All Effectiveness</option>
                                {['effective','partially_effective','ineffective'].map(e_ => <option key={e_} value={e_}>{e_.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}</option>)}
                            </select>
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="border-t border-gray-100 bg-gray-50/50">
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Code</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Domain</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Type</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Effectiveness</th>
                            <th className="px-4 py-3 text-center font-medium text-[#718096]">Mappings</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                        </tr></thead>
                        <tbody className="divide-y divide-gray-50">
                            {controls.data.length === 0 ? (
                                <tr><td colSpan={7} className="px-4 py-12 text-center text-[#718096]">No controls found. Create your first control.</td></tr>
                            ) : controls.data.map(c => (
                                <tr key={c.id} className="hover:bg-gray-50/50">
                                    <td className="px-4 py-3">
                                        <Link href={route('controls.show', c.id)} className="font-mono-data text-[#1A365D] text-xs font-medium hover:underline">{c.control_code}</Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Link href={route('controls.show', c.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">{c.title}</Link>
                                        {c.is_key_control && <span className="ml-1 text-[9px] bg-[#D4AF37]/10 text-[#D4AF37] px-1.5 py-0.5 rounded font-semibold">KEY</span>}
                                    </td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{c.domain || '--'}</td>
                                    <td className="px-4 py-3 capitalize text-[#718096] text-xs">{c.type || '--'}</td>
                                    <td className="px-4 py-3">
                                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${effectColors[c.effectiveness] || effectColors.not_assessed}`}>
                                            {(c.effectiveness || 'not_assessed').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <span className="font-mono-data text-xs text-[#718096]">{c.framework_requirements_count || 0}</span>
                                    </td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{c.owner?.name || '--'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={controls.links} /></div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, ClipboardDocumentCheckIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function VendorAssessmentsIndex({ assessments, vendors = [], filters = {}, statuses = [], assessmentTypes = [] }) {
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('vendor-assessments.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('vendor-assessments.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Vendor Assessments">
            <Head title="Vendor Assessments" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Vendor Assessments</h2>
                    <p className="text-sm text-[#718096] mt-1">
                        {assessments.total} assessment{assessments.total !== 1 ? 's' : ''} across your vendor portfolio.
                    </p>
                </div>
                <Link href={route('vendor-assessments.create')}
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                    <PlusIcon className="w-4 h-4" /> New Assessment
                </Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 space-y-3">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Search assessments by title or findings..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>
                    <div className="flex flex-wrap gap-3">
                        <select value={filters.vendor_id || ''} onChange={e => applyFilter('vendor_id', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Vendors</option>
                            {vendors.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                        </select>
                        <select value={filters.status || ''} onChange={e => applyFilter('status', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Statuses</option>
                            {statuses.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                        </select>
                        <select value={filters.assessment_type || ''} onChange={e => applyFilter('assessment_type', e.target.value)}
                            className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                            <option value="">All Types</option>
                            {assessmentTypes.map(t => <option key={t} value={t}>{cap(t)}</option>)}
                        </select>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Vendor</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Type</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Score</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Assessor</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Date</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {assessments.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        <ClipboardDocumentCheckIcon className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                        No vendor assessments found.
                                    </td>
                                </tr>
                            ) : assessments.data.map(a => (
                                <tr key={a.id} className="hover:bg-gray-50/50 transition-colors">
                                    <td className="px-4 py-3">
                                        <Link href={route('vendor-assessments.show', a.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                            {a.title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        {a.vendor ? (
                                            <Link href={route('vendors.show', a.vendor.id)} className="text-[#1A365D] hover:underline">
                                                {a.vendor.name}
                                            </Link>
                                        ) : '--'}
                                    </td>
                                    <td className="px-4 py-3 text-[#718096]">{cap(a.assessment_type)}</td>
                                    <td className="px-4 py-3 text-center font-mono-data">{a.overall_score != null ? Number(a.overall_score).toFixed(1) : '--'}</td>
                                    <td className="px-4 py-3"><StatusBadge status={a.status} label={cap(a.status)} /></td>
                                    <td className="px-4 py-3 text-[#718096]">{a.assessor?.name || '--'}</td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{a.assessment_date ? new Date(a.assessment_date).toLocaleDateString() : '--'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={assessments.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

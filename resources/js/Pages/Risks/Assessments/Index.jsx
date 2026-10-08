import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatDate, humanize } from '@/Utils/risk';
import { PlusIcon } from '@heroicons/react/24/outline';
import { RatingBadge, ScoreDisplay } from '@/Components/Risk/RiskBadge';
import Pagination from '@/Components/Pagination';

export default function AssessmentsIndex({ assessments, filters = {} }) {
    const [search, setSearch] = useState(filters.search || '');
    const apply = (params) => router.get(route('risk-assessments.index'), { ...filters, ...params }, { preserveState: true, replace: true });
    const selectClass = 'text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]';

    return (
        <AuthenticatedLayout header="Risk Assessments">
            <Head title="Risk Assessments" />

            <div className="flex justify-between items-center mb-6">
                <p className="text-sm text-[#718096]">{assessments.total} assessment{assessments.total !== 1 ? 's' : ''}</p>
                <Link href={route('risk-assessments.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                    <PlusIcon className="w-4 h-4" /> New Assessment
                </Link>
            </div>

            <form onSubmit={(e) => { e.preventDefault(); apply({ search: search || undefined }); }} className="flex flex-wrap gap-2 mb-4">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by risk ID or title…" className={`${selectClass} flex-1 min-w-[220px]`} />
                <select value={filters.methodology || ''} onChange={(e) => apply({ methodology: e.target.value || undefined })} className={selectClass}>
                    <option value="">All methods</option>
                    <option value="qualitative">Qualitative</option>
                    <option value="fair">FAIR</option>
                </select>
                <select value={filters.assessment_type || ''} onChange={(e) => apply({ assessment_type: e.target.value || undefined })} className={selectClass}>
                    <option value="">Inherent &amp; residual</option>
                    <option value="inherent">Inherent</option>
                    <option value="residual">Residual</option>
                </select>
                <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200">Search</button>
            </form>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="bg-gray-50/50 border-b border-gray-100">
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Risk</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Method</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Type</th>
                            <th className="px-4 py-3 text-center font-medium text-[#718096]">Score</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Rating</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Assessor</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Date</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {assessments.data.length === 0 ? (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-[#718096]">{Object.values(filters).some(Boolean) ? 'No assessments match these filters.' : 'No assessments yet.'}</td></tr>
                        ) : assessments.data.map(a => (
                            <tr key={a.id} className="hover:bg-gray-50/50">
                                <td className="px-4 py-3">
                                    <Link href={route('risk-assessments.show', a.id)} className="font-mono-data text-[#1A365D] text-xs font-medium hover:underline">
                                        {a.risk?.risk_id_code}
                                    </Link>
                                    <Link href={route('risk-assessments.show', a.id)} className="block text-xs text-[#718096] truncate max-w-48 hover:text-[#1A365D]">{a.risk?.title}</Link>
                                </td>
                                <td className="px-4 py-3 text-[#2D3748]">{a.methodology === 'fair' ? 'FAIR' : humanize(a.methodology)}</td>
                                <td className="px-4 py-3"><span className="text-xs bg-blue-50 px-2 py-0.5 rounded capitalize text-blue-700">{a.assessment_type}</span></td>
                                <td className="px-4 py-3 text-center"><ScoreDisplay score={a.score} /></td>
                                <td className="px-4 py-3"><RatingBadge rating={a.rating} /></td>
                                <td className="px-4 py-3 text-[#718096]">{a.assessor?.name}</td>
                                <td className="px-4 py-3 text-[#718096] text-xs whitespace-nowrap">{formatDate(a.assessment_date)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={assessments.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';
import Chip from '@/Components/Compliance/Chip';
import { ASSESSMENT_STATUS, scoreColor, formatDate, isOverdue } from '@/Utils/compliance';

export default function AssessmentsIndex({ assessments, filters, frameworks, statusCounts, can }) {
    const canCreate = can?.create;
    const [search, setSearch] = useState(filters.search || '');
    const apply = (patch) => router.get(route('compliance-assessments.index'), { ...filters, ...patch, page: undefined }, { preserveState: true, replace: true });
    const total = Object.values(statusCounts).reduce((a, b) => a + Number(b), 0);

    return (
        <AuthenticatedLayout header="Compliance Assessments">
            <Head title="Compliance Assessments" />
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <div className="flex flex-wrap gap-1.5">
                    <button onClick={() => apply({ status: undefined })} className={`text-xs px-2.5 py-1 rounded-full ${!filters.status ? 'bg-[#1A365D] text-white' : 'bg-gray-100 text-[#4A5568]'}`}>All · {total}</button>
                    {Object.entries(ASSESSMENT_STATUS).map(([k, v]) => (
                        <button key={k} onClick={() => apply({ status: k })} className={`text-xs px-2.5 py-1 rounded-full ${filters.status === k ? 'bg-[#1A365D] text-white' : v.chip}`}>{v.label} · {statusCounts[k] || 0}</button>
                    ))}
                </div>
                {canCreate && <Link href={route('compliance-assessments.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] shrink-0"><PlusIcon className="w-4 h-4" /> New Assessment</Link>}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div className="p-4 flex flex-wrap gap-2">
                    <form onSubmit={(e) => { e.preventDefault(); apply({ search: search || undefined }); }} className="relative flex-1 min-w-[200px]">
                        <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search assessments…" className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg" />
                    </form>
                    <select value={filters.framework || ''} onChange={(e) => apply({ framework: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All frameworks</option>
                        {frameworks.map((f) => <option key={f.id} value={f.id}>{f.short_name}</option>)}
                    </select>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="bg-gray-50/50 border-y border-gray-100 text-left text-[#718096]">
                            <th className="px-4 py-3 font-medium">Assessment</th>
                            <th className="px-4 py-3 font-medium">Framework</th>
                            <th className="px-4 py-3 font-medium text-center">Score</th>
                            <th className="px-4 py-3 font-medium">Progress</th>
                            <th className="px-4 py-3 font-medium">Status</th>
                            <th className="px-4 py-3 font-medium">Lead</th>
                            <th className="px-4 py-3 font-medium">Dates</th>
                        </tr></thead>
                        <tbody className="divide-y divide-gray-50">
                            {assessments.data.length === 0 ? (
                                <tr><td colSpan={7} className="px-4 py-12 text-center text-[#718096]">No assessments match.</td></tr>
                            ) : assessments.data.map((a) => {
                                const assessed = a.compliant_count + a.partial_count + a.non_compliant_count + a.not_applicable_count;
                                const pct = a.total_requirements ? Math.round((assessed / a.total_requirements) * 100) : 0;
                                const meta = ASSESSMENT_STATUS[a.status] || ASSESSMENT_STATUS.planned;
                                const overdue = ['planned', 'in_progress'].includes(a.status) && isOverdue(a.due_date);
                                return (
                                    <tr key={a.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3"><Link href={route('compliance-assessments.show', a.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">{a.title}</Link></td>
                                        <td className="px-4 py-3"><span className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">{a.framework?.short_name}</span></td>
                                        <td className="px-4 py-3 text-center">{a.overall_score !== null ? <span className="font-mono-data font-bold" style={{ color: scoreColor(a.overall_score) }}>{Math.round(a.overall_score)}%</span> : <span className="text-gray-400">--</span>}</td>
                                        <td className="px-4 py-3 w-40">
                                            <div className="flex items-center gap-2"><div className="flex-1 bg-gray-100 rounded-full h-1.5"><div className="bg-[#1A365D] h-1.5 rounded-full" style={{ width: `${pct}%` }} /></div><span className="text-[11px] text-[#718096] font-mono-data">{assessed}/{a.total_requirements}</span></div>
                                        </td>
                                        <td className="px-4 py-3"><Chip className={meta.chip}>{meta.label}</Chip></td>
                                        <td className="px-4 py-3 text-[#718096] text-xs">{a.lead_assessor?.name || '--'}</td>
                                        <td className="px-4 py-3 text-[#718096] text-xs whitespace-nowrap">
                                            {formatDate(a.start_date)}
                                            {a.status === 'completed' ? <span className="block">Completed {formatDate(a.end_date)}</span>
                                                : a.due_date && <span className={`block ${overdue ? 'text-red-600 font-semibold' : ''}`}>Due {formatDate(a.due_date)}</span>}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={assessments.links} /></div>
            </div>
        </AuthenticatedLayout>
    );
}

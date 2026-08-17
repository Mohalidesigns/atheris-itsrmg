import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { PlusIcon } from '@heroicons/react/24/outline';
import { StatusBadge } from '@/Components/Risk/RiskBadge';
import Pagination from '@/Components/Pagination';

export default function AssessmentsIndex({ assessments }) {
    return (
        <AuthenticatedLayout header="Compliance Assessments">
            <Head title="Compliance Assessments" />
            <div className="flex justify-between items-center mb-6">
                <p className="text-sm text-[#718096]">{assessments.total} assessment{assessments.total !== 1 ? 's' : ''}</p>
                <Link href={route('compliance-assessments.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]"><PlusIcon className="w-4 h-4" /> New Assessment</Link>
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead><tr className="bg-gray-50/50 border-b border-gray-100">
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Assessment</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Framework</th>
                        <th className="px-4 py-3 text-center font-medium text-[#718096]">Score</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Lead</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Date</th>
                    </tr></thead>
                    <tbody className="divide-y divide-gray-50">
                        {assessments.data.length === 0 ? (
                            <tr><td colSpan={6} className="px-4 py-12 text-center text-[#718096]">No assessments yet.</td></tr>
                        ) : assessments.data.map(a => {
                            const scoreColor = a.overall_score >= 80 ? '#2D7D46' : a.overall_score >= 60 ? '#D4AF37' : a.overall_score >= 40 ? '#DD6B20' : '#C53030';
                            return (
                                <tr key={a.id} className="hover:bg-gray-50/50">
                                    <td className="px-4 py-3"><Link href={route('compliance-assessments.show', a.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">{a.title}</Link></td>
                                    <td className="px-4 py-3"><span className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">{a.framework?.short_name}</span></td>
                                    <td className="px-4 py-3 text-center">{a.overall_score !== null ? <span className="font-mono-data font-bold" style={{ color: scoreColor }}>{Math.round(a.overall_score)}%</span> : <span className="text-gray-400">--</span>}</td>
                                    <td className="px-4 py-3"><StatusBadge status={a.status} /></td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{a.lead_assessor?.name || '--'}</td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{a.start_date}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={assessments.links} /></div>
            </div>
        </AuthenticatedLayout>
    );
}

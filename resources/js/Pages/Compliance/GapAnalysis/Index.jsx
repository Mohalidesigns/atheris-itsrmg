import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import Pagination from '@/Components/Pagination';

const severityColors = {
    critical: 'bg-red-50 text-red-700',
    high: 'bg-orange-50 text-orange-700',
    medium: 'bg-amber-50 text-amber-700',
    low: 'bg-green-50 text-green-700',
};

const statusColors = {
    identified: 'bg-blue-50 text-blue-700',
    remediation_planned: 'bg-purple-50 text-purple-700',
    in_progress: 'bg-amber-50 text-amber-700',
    remediated: 'bg-green-50 text-green-700',
    accepted: 'bg-gray-50 text-gray-600',
    closed: 'bg-gray-50 text-gray-400',
};

export default function GapAnalysisIndex({ gaps }) {
    return (
        <AuthenticatedLayout header="Gap Analysis">
            <Head title="Gap Analysis" />
            <p className="text-sm text-[#718096] mb-6">{gaps.total} gap{gaps.total !== 1 ? 's' : ''} identified</p>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead><tr className="bg-gray-50/50 border-b border-gray-100">
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Code</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Gap</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Requirement</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Severity</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Assigned</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Due Date</th>
                    </tr></thead>
                    <tbody className="divide-y divide-gray-50">
                        {gaps.data.length === 0 ? (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-[#718096]">No gaps identified.</td></tr>
                        ) : gaps.data.map(g => (
                            <tr key={g.id} className="hover:bg-gray-50/50">
                                <td className="px-4 py-3 font-mono-data text-xs text-[#1A365D] font-semibold">{g.gap_code}</td>
                                <td className="px-4 py-3 text-[#2D3748] font-medium">{g.title}</td>
                                <td className="px-4 py-3 text-xs text-[#718096]">{g.requirement?.requirement_code}</td>
                                <td className="px-4 py-3">
                                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${severityColors[g.severity]}`}>{g.severity}</span>
                                </td>
                                <td className="px-4 py-3">
                                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${statusColors[g.status] || 'bg-gray-50 text-gray-500'}`}>
                                        {g.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-[#718096] text-xs">{g.assignee?.name || '--'}</td>
                                <td className="px-4 py-3 text-[#718096] text-xs">{g.due_date || '--'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={gaps.links} /></div>
            </div>
        </AuthenticatedLayout>
    );
}

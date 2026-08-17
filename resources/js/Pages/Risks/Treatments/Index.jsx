import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { PlusIcon } from '@heroicons/react/24/outline';
import { StatusBadge } from '@/Components/Risk/RiskBadge';
import Pagination from '@/Components/Pagination';

export default function TreatmentsIndex({ treatments, filters, statuses }) {
    return (
        <AuthenticatedLayout header="Risk Treatments">
            <Head title="Risk Treatments" />

            <div className="flex justify-between items-center mb-6">
                <div className="flex items-center gap-3">
                    <p className="text-sm text-[#718096]">{treatments.total} treatment{treatments.total !== 1 ? 's' : ''}</p>
                    <select value={filters.status || ''} onChange={e => router.get(route('risk-treatments.index'), { status: e.target.value || undefined }, { preserveState: true })}
                        className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                        <option value="">All Statuses</option>
                        {statuses.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1).replace('_', ' ')}</option>)}
                    </select>
                </div>
                <Link href={route('risk-treatments.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                    <PlusIcon className="w-4 h-4" /> New Treatment
                </Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="bg-gray-50/50 border-b border-gray-100">
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Treatment</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Risk</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Strategy</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Assigned To</th>
                            <th className="px-4 py-3 text-left font-medium text-[#718096]">Due Date</th>
                            <th className="px-4 py-3 text-center font-medium text-[#718096]">Progress</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {treatments.data.length === 0 ? (
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-[#718096]">No treatments yet.</td></tr>
                        ) : treatments.data.map(t => (
                            <tr key={t.id} className="hover:bg-gray-50/50">
                                <td className="px-4 py-3">
                                    <Link href={route('risk-treatments.show', t.id)} className="font-medium text-[#2D3748] hover:text-[#1A365D]">{t.title}</Link>
                                </td>
                                <td className="px-4 py-3">
                                    <Link href={route('risks.show', t.risk_id)} className="font-mono-data text-[#1A365D] text-xs hover:underline">
                                        {t.risk?.risk_id_code}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 capitalize text-[#718096]">{t.strategy}</td>
                                <td className="px-4 py-3"><StatusBadge status={t.status} /></td>
                                <td className="px-4 py-3 text-[#718096]">{t.assignee?.name || '--'}</td>
                                <td className="px-4 py-3 text-[#718096] text-xs">{t.due_date || '--'}</td>
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        <div className="w-16 bg-gray-200 rounded-full h-1.5">
                                            <div className="bg-[#2D7D46] rounded-full h-1.5" style={{ width: `${t.completion_percentage}%` }} />
                                        </div>
                                        <span className="text-xs text-[#718096]">{t.completion_percentage}%</span>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={treatments.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

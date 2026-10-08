import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon } from '@heroicons/react/24/outline';
import { TreatmentStatusBadge } from '@/Components/Risk/RiskBadge';
import { formatDate, humanize } from '@/Utils/risk';
import Pagination from '@/Components/Pagination';

export default function TreatmentsIndex({ treatments, filters, statuses, strategies = [], summary = {} }) {
    const [search, setSearch] = useState(filters.search || '');
    const apply = (params) => router.get(route('risk-treatments.index'), { ...filters, ...params }, { preserveState: true, replace: true });
    const selectClass = 'text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]';

    return (
        <AuthenticatedLayout header="Risk Treatments">
            <Head title="Risk Treatments" />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                {[
                    ['Open plans', summary.open, null],
                    ['Overdue', summary.overdue, 'overdue'],
                    ['Awaiting approval', summary.awaiting_approval, 'submitted'],
                    ['Completed', summary.completed, 'completed'],
                ].map(([label, value, status]) => (
                    <button key={label} onClick={() => apply({ status: status || undefined })}
                        className={`text-left bg-white rounded-xl border p-4 shadow-sm hover:border-[#C9A86A] ${filters.status === status ? 'border-[#C9A86A]' : 'border-gray-100'}`}>
                        <p className="text-xs text-[#718096] uppercase font-medium">{label}</p>
                        <p className={`text-2xl font-bold font-mono-data ${label === 'Overdue' && value ? 'text-[#C53030]' : 'text-[#2D3748]'}`}>{value ?? 0}</p>
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap justify-between items-center gap-3 mb-4">
                <form onSubmit={(e) => { e.preventDefault(); apply({ search: search || undefined }); }} className="flex flex-wrap items-center gap-2">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search plan or risk…" className={`${selectClass} min-w-[220px]`} />
                    <select value={filters.status || ''} onChange={e => apply({ status: e.target.value || undefined })} className={selectClass}>
                        <option value="">All Statuses</option>
                        <option value="overdue">Overdue (past due, still open)</option>
                        {statuses.filter(s => s !== 'overdue').map(s => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select>
                    <select value={filters.strategy || ''} onChange={e => apply({ strategy: e.target.value || undefined })} className={selectClass}>
                        <option value="">All Strategies</option>
                        {strategies.map(s => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select>
                    <span className="text-sm text-[#718096]">{treatments.total} plan{treatments.total !== 1 ? 's' : ''}</span>
                </form>
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
                            <tr><td colSpan={7} className="px-4 py-12 text-center text-[#718096]">{Object.values(filters || {}).some(Boolean) ? 'No treatment plans match these filters.' : 'No treatments yet.'}</td></tr>
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
                                <td className="px-4 py-3 text-[#718096]">{humanize(t.strategy)}</td>
                                <td className="px-4 py-3"><TreatmentStatusBadge status={t.status} overdue={t.is_overdue} /></td>
                                <td className="px-4 py-3 text-[#718096]">{t.assignee?.name || '--'}</td>
                                <td className={`px-4 py-3 text-xs whitespace-nowrap ${t.is_overdue ? 'text-[#C53030] font-medium' : 'text-[#718096]'}`}>{formatDate(t.due_date)}</td>
                                <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                        <div className="w-16 bg-gray-200 rounded-full h-1.5">
                                            <div className="bg-[#2D7D46] rounded-full h-1.5" style={{ width: `${t.completion_percentage || 0}%` }} />
                                        </div>
                                        <span className="text-xs text-[#718096]">{t.completion_percentage || 0}%</span>
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

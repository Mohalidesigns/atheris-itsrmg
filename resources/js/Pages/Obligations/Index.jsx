import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { formatDate } from '@/Utils/risk';

function dueTone(days) {
    if (days <= 7) return 'bg-red-50 text-red-700';
    if (days <= 30) return 'bg-amber-50 text-amber-800';
    return 'bg-gray-100 text-gray-600';
}

export default function ObligationsIndex({ obligations = [], regulators = [], regulator = null }) {
    const filter = (reg) => router.get(route('obligations.index'), reg ? { regulator: reg } : {}, { preserveState: true, replace: true });
    const dueSoon = obligations.filter((o) => o.days_to_due <= 30).length;

    return (
        <AuthenticatedLayout header="Obligations Register">
            <Head title="Obligations Register" />
            <PageHeader
                breadcrumbs={[{ label: 'Compliance' }, { label: 'Obligations Register' }]}
                title="Obligations Register"
                subtitle={`Regulatory submissions and reviews, ordered by next due date. ${dueSoon} due in the next 30 days.`} />

            <div className="flex flex-wrap gap-1.5 mb-4">
                <button onClick={() => filter(null)} className={`text-xs px-2.5 py-1 rounded-full ${!regulator ? 'bg-[#0A1F44] text-white' : 'bg-gray-100 text-[#4A5568]'}`}>All</button>
                {regulators.map((r) => (
                    <button key={r} onClick={() => filter(r)} className={`text-xs px-2.5 py-1 rounded-full ${regulator === r ? 'bg-[#0A1F44] text-white' : 'bg-gray-100 text-[#4A5568]'}`}>{r}</button>
                ))}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Regulator</th>
                            <th className="px-3 py-2">Reference</th>
                            <th className="px-3 py-2">Obligation</th>
                            <th className="px-3 py-2">Owner</th>
                            <th className="px-3 py-2">Cycle</th>
                            <th className="px-3 py-2">Effective</th>
                            <th className="px-3 py-2">Next due</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {obligations.length === 0 && <tr><td colSpan={7} className="px-3 py-10 text-center text-[#718096]">No obligations recorded.</td></tr>}
                        {obligations.map((o) => (
                            <tr key={o.id} className="align-top">
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{o.regulator_code}</td>
                                <td className="px-3 py-2 text-xs text-[#718096] font-mono">{o.reference_code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">
                                    {o.title}
                                    {o.evidence_requirement && <p className="text-[11px] text-[#718096] mt-0.5">Evidence: {o.evidence_requirement}</p>}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#2D3748]">{o.owner_role || '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{o.review_cycle_days} days</td>
                                <td className="px-3 py-2 text-xs text-[#718096] whitespace-nowrap">{formatDate(o.effective_date)}</td>
                                <td className="px-3 py-2 text-xs whitespace-nowrap">
                                    {formatDate(o.next_due)}
                                    <span className={`ml-1.5 px-1.5 py-0.5 rounded-full text-[10px] font-medium ${dueTone(o.days_to_due)}`}>{o.days_to_due === 0 ? 'today' : `${o.days_to_due}d`}</span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

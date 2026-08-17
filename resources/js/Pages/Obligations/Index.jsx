import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function ObligationsIndex({ obligations = [] }) {
    return (
        <AuthenticatedLayout header="Obligations Register">
            <Head title="Obligations Register" />
            <PageHeader
                breadcrumbs={[{ label: 'Compliance' }, { label: 'Obligations Register' }]}
                title="Obligations Register" subtitle="Platform-seeded regulatory obligations across CBN, NDPC, SEC, NCC, NAICOM, PENCOM, NDIC, BoG, CBK." />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Regulator</th>
                            <th className="px-3 py-2">Reference</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Owner Role</th>
                            <th className="px-3 py-2">Review Cycle</th>
                            <th className="px-3 py-2">Effective</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {obligations.map((o) => (
                            <tr key={o.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{o.regulator_code}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{o.reference_code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{o.title}</td>
                                <td className="px-3 py-2 text-xs text-[#2D3748]">{o.owner_role}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{o.review_cycle_days}d</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{o.effective_date}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

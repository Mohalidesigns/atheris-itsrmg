import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function CoreBankingSnapshots({ snapshots = [] }) {
    return (
        <AuthenticatedLayout header="Core Banking Snapshots">
            <Head title="Core Banking Snapshots" />
            <PageHeader
                breadcrumbs={[{ label: 'Core Banking', href: route('core-banking.index') }, { label: 'Snapshots' }]}
                title="Core Banking Snapshots"
                subtitle="Technical metadata snapshots captured on schedule. No customer data, by design."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Product</th>
                            <th className="px-3 py-2">Captured</th>
                            <th className="px-3 py-2">Artefacts</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {snapshots.map((s) => (
                            <tr key={s.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44] uppercase">{s.product}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{s.captured_at}</td>
                                <td className="px-3 py-2 text-xs text-[#2D3748]">{Object.keys(s.artefacts || {}).join(', ')}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

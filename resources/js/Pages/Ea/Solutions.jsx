import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function Solutions({ solutions = [] }) {
    return (
        <AuthenticatedLayout header="Solution Architectures">
            <Head title="Solution Architectures" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Solutions' }]}
                title="Solution Architectures"
                subtitle="Concrete solution designs instantiated from reference patterns and tied to initiatives."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Derived from pattern</th>
                            <th className="px-3 py-2">Initiative</th>
                            <th className="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {solutions.length === 0 && <tr><td colSpan={5} className="px-3 py-8 text-center text-xs text-[#718096]">No solution architectures yet.</td></tr>}
                        {solutions.map((s) => (
                            <tr key={s.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{s.code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{s.name}</td>
                                <td className="px-3 py-2 text-xs text-[#C9A86A]">{s.pattern?.code || '—'} {s.pattern ? `(${s.pattern.name})` : ''}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{s.initiative?.name || '—'}</td>
                                <td className="px-3 py-2"><StatusBadge status={s.status === 'approved' ? 'active' : 'draft'} label={s.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function SlaPolicies({ policies = [] }) {
    return (
        <AuthenticatedLayout header="SLA Policies">
            <Head title="SLA Policies" />
            <PageHeader
                breadcrumbs={[{ label: 'Issues & Remediation', href: route('issues.index') }, { label: 'SLA Policies' }]}
                title="Issue & Vulnerability SLA Policies"
                subtitle="CBN / ngCERT aligned SLA defaults: Critical < 72 h, High < 14 d, Medium < 30 d, Low < 90 d."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Severity</th>
                            <th className="px-3 py-2">Remediate within (hours)</th>
                            <th className="px-3 py-2">Escalation</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {policies.map((p) => (
                            <tr key={p.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44] capitalize">{p.severity}</td>
                                <td className="px-3 py-2">{p.hours_to_remediate}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{p.escalation_rules || 'Default escalate to CISO + CRO'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

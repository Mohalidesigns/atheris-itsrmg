import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function VulnPrioritiser({ vulns = [] }) {
    return (
        <AuthenticatedLayout header="Vulnerability Prioritiser">
            <Head title="Vulnerability Prioritiser" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Vulnerability Prioritiser' }]}
                title="Vulnerability Prioritiser"
                subtitle="CVSS × EPSS × CISA KEV × asset criticality — CBN/ngCERT SLA pre-tuned (C<72h, H<14d, M<30d, L<90d)."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Priority</th>
                            <th className="px-3 py-2">CVE / Title</th>
                            <th className="px-3 py-2">Severity</th>
                            <th className="px-3 py-2">CVSS</th>
                            <th className="px-3 py-2">EPSS</th>
                            <th className="px-3 py-2">KEV</th>
                            <th className="px-3 py-2">Asset</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {vulns.map((v) => (
                            <tr key={v.id}>
                                <td className="px-3 py-2 font-bold text-[#B3261E]">{v.priority}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{v.cve_id || v.title || 'Finding'}</td>
                                <td className="px-3 py-2"><StatusBadge status={v.severity || 'medium'} /></td>
                                <td className="px-3 py-2">{v.cvss_score || '—'}</td>
                                <td className="px-3 py-2">{(v.epss * 100).toFixed(1)}%</td>
                                <td className="px-3 py-2">{v.kev ? <StatusBadge status="critical" label="KEV" /> : <span className="text-xs text-[#718096]">—</span>}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{v.asset || v.host || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

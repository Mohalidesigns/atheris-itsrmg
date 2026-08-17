import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function SiemIndex({ integrations = [], signals = [] }) {
    return (
        <AuthenticatedLayout header="SIEM Integrations">
            <Head title="SIEM / SOAR" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'SIEM Integrations' }]}
                title="SIEM / SOAR Integrations"
                subtitle="Microsoft Sentinel, Splunk ES, QRadar, Wazuh — HMAC-signed webhook ingest, PII redaction, tenant-scoped correlation."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                {integrations.map((i) => (
                    <div key={i.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <h3 className="text-sm font-semibold text-[#2D3748] capitalize">{i.provider.replace('_', ' ')}</h3>
                            <StatusBadge status={i.status === 'active' ? 'active' : 'draft'} label={i.status} />
                        </div>
                        <p className="text-xs text-[#718096] mt-1">Last signal: {i.last_signal_at || '—'}</p>
                    </div>
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Recent signals</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Provider</th>
                            <th className="px-3 py-2">External ID</th>
                            <th className="px-3 py-2">Title</th>
                            <th className="px-3 py-2">Severity</th>
                            <th className="px-3 py-2">Received</th>
                            <th className="px-3 py-2">Incident</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {signals.map((s) => (
                            <tr key={s.id}>
                                <td className="px-3 py-2 text-xs text-[#0A1F44] capitalize">{s.provider.replace('_', ' ')}</td>
                                <td className="px-3 py-2 font-mono text-[10px] text-[#718096]">{s.external_id}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{s.title}</td>
                                <td className="px-3 py-2"><StatusBadge status={s.severity} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{s.received_at}</td>
                                <td className="px-3 py-2 text-xs">{s.incident_id ? `#${s.incident_id}` : '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

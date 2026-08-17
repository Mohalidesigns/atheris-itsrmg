import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function ItsmIndex({ integrations = [] }) {
    return (
        <AuthenticatedLayout header="ITSM Integrations">
            <Head title="ITSM Integrations" />
            <PageHeader
                breadcrumbs={[{ label: 'Issues & Remediation', href: route('issues.index') }, { label: 'ITSM Integrations' }]}
                title="ITSM Integrations" subtitle="Bi-directional connectors to Jira, ServiceNow, and Freshservice." />
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {integrations.map((i) => (
                    <div key={i.key} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <h3 className="text-sm font-semibold text-[#2D3748]">{i.name}</h3>
                            <StatusBadge status={i.status === 'connected' ? 'active' : 'draft'} label={i.status} />
                        </div>
                        <p className="text-xs text-[#718096] mt-2">{i.tickets_synced} tickets synced</p>
                        <button className="mt-3 w-full px-3 py-2 rounded-lg border border-[#0A1F44]/20 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">
                            Test connection
                        </button>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

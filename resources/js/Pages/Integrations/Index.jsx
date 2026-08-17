import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { PuzzlePieceIcon, CheckCircleIcon, ExclamationCircleIcon, PlusCircleIcon } from '@heroicons/react/24/outline';

const statusTone = (s) => ({
    connected: 'pass',
    available: 'draft',
    not_connected: 'draft',
    error: 'critical',
})[s] || 'draft';

const statusLabel = (s) => ({
    connected: 'Connected',
    available: 'Available',
    not_connected: 'Not Connected',
    error: 'Error',
})[s] || s;

export default function IntegrationsIndex({ byCategory = {}, summary = {} }) {
    return (
        <AuthenticatedLayout header="Integrations Hub">
            <Head title="Integrations Hub" />
            <PageHeader
                breadcrumbs={[{ label: 'Integrations Hub' }]}
                title="Integrations Hub"
                subtitle="50+ connectors across Identity, Scanners, Threat Intel, SIEM, CMDB, Ticketing, TPRM, Cloud Posture, Evidence Vault, Messaging, Core Banking and Switches."
            />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Connectors" value={summary.total} tone="navy" icon={PuzzlePieceIcon} />
                <KpiCard label="Connected" value={summary.connected} tone="green" icon={CheckCircleIcon} />
                <KpiCard label="Available" value={summary.available} tone="gold" icon={PlusCircleIcon} />
                <KpiCard label="Not Connected" value={summary.not_connected} tone="white" />
                <KpiCard label="Errors" value={summary.error} tone="red" icon={ExclamationCircleIcon} />
            </div>

            {Object.entries(byCategory).map(([cat, items]) => (
                <div key={cat} className="mb-6">
                    <h3 className="text-sm font-semibold text-[#0A1F44] uppercase tracking-wide mb-2">{cat} <span className="text-xs font-normal text-[#718096]">· {items.length} connector{items.length > 1 ? 's' : ''}</span></h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                        {Object.entries(items).map(([key, i]) => (
                            <Link key={key} href={route('integrations.show', key)}
                                className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:border-[#0A1F44]/30 hover:shadow-md transition block">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <h4 className="text-sm font-semibold text-[#2D3748]">{i.name}</h4>
                                        <p className="text-[10px] text-[#718096] uppercase tracking-wide">{i.vendor}</p>
                                    </div>
                                    <StatusBadge status={statusTone(i.status)} label={statusLabel(i.status)} />
                                </div>
                                <p className="text-xs text-[#718096] mt-2 line-clamp-3">{i.description}</p>
                                <div className="flex items-center justify-between mt-3 text-[10px] text-[#718096]">
                                    <span>{i.capabilities?.length || 0} capabilities</span>
                                    {i.last_sync && <span>Last sync: {i.last_sync}</span>}
                                </div>
                            </Link>
                        ))}
                    </div>
                </div>
            ))}
        </AuthenticatedLayout>
    );
}

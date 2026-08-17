import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { BuildingLibraryIcon } from '@heroicons/react/24/outline';

export default function CoreBankingIndex({ integrations = [] }) {
    return (
        <AuthenticatedLayout header="Core Banking Integrations">
            <Head title="Core Banking Integrations" />
            <PageHeader
                breadcrumbs={[{ label: 'Core Banking' }, { label: 'Integrations' }]}
                title="Core Banking — Read-Only Adapters"
                subtitle="Finacle, Flexcube, T24, BankOne + Interswitch / NIBSS NIP. Technical metadata only — strict customer-data embargo."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {integrations.map((i) => (
                    <div key={i.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <div className="flex items-center gap-2">
                                    <BuildingLibraryIcon className="w-5 h-5 text-[#0A1F44]" />
                                    <h3 className="text-sm font-semibold text-[#2D3748] uppercase">{i.product}</h3>
                                </div>
                                <p className="text-xs text-[#718096] mt-1">Last sync: {i.last_sync_at || '—'}</p>
                            </div>
                            <StatusBadge status={i.status === 'connected' ? 'active' : 'draft'} label={i.status} />
                        </div>
                        <button className="mt-3 w-full px-3 py-2 rounded-lg border border-[#0A1F44]/20 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">
                            Sync technical metadata
                        </button>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

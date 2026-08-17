import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function SsoIndex({ connections = [] }) {
    return (
        <AuthenticatedLayout header="SSO Connections">
            <Head title="SSO Connections" />
            <PageHeader
                breadcrumbs={[{ label: 'Identity & Access' }, { label: 'SSO Connections' }]}
                title="SSO — SAML 2.0 & OIDC"
                subtitle="Entra ID, Okta, Ping, Google Workspace. Atheris is an SP; upload IdP metadata here."
                actions={<button className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">+ Add connection</button>}
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {connections.map((c) => (
                    <div key={c.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{c.idp_name}</h3>
                                <p className="text-xs text-[#718096] uppercase">{c.type}</p>
                            </div>
                            <StatusBadge status={c.enabled ? 'enabled' : 'disabled'} label={c.enabled ? 'Enabled' : 'Disabled'} />
                        </div>
                        <p className="text-xs text-[#718096] mt-2 font-mono truncate">SP Entity: {c.sp_entity_id}</p>
                        <button className="mt-3 w-full px-3 py-2 rounded-lg border border-[#0A1F44]/20 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5">
                            Download SP metadata
                        </button>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function Apis({ apis = [] }) {
    const byAuth = apis.reduce((a, x) => { a[x.auth_method] = (a[x.auth_method] || 0) + 1; return a; }, {});
    return (
        <AuthenticatedLayout header="API Register">
            <Head title="API Register" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'API Register' }]}
                title="API Register"
                subtitle="All published APIs: provider, consumer, base URL, version, authentication method."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.apis')} current="ea.apis" />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <KpiCard label="APIs registered" value={apis.length} tone="navy" />
                <KpiCard label="OAuth2" value={byAuth.oauth2 || 0} tone="gold" />
                <KpiCard label="mTLS" value={byAuth.mtls || 0} tone="green" />
                <KpiCard label="API Key" value={byAuth.api_key || 0} tone="amber" />
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Base URL</th>
                            <th className="px-3 py-2">Version</th>
                            <th className="px-3 py-2">Provider</th>
                            <th className="px-3 py-2">Auth</th>
                            <th className="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {apis.map((a) => (
                            <tr key={a.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{a.code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{a.name}</td>
                                <td className="px-3 py-2 font-mono text-[10px] text-[#718096]">{a.base_url}</td>
                                <td className="px-3 py-2 text-xs">{a.version}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{a.provider_role}</td>
                                <td className="px-3 py-2"><StatusBadge status={a.auth_method === 'oauth2' ? 'pass' : a.auth_method === 'mtls' ? 'active' : 'warn'} label={a.auth_method} /></td>
                                <td className="px-3 py-2"><StatusBadge status={a.status} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

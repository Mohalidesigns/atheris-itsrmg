import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router, usePage } from '@inertiajs/react';

export default function ScimIndex({ tokens = [] }) {
    const { auth } = usePage().props;
    const canEdit = (auth?.user?.roles || []).includes('Super Admin') || (auth?.user?.permissions || []).includes('edit platform');
    const create = () => {
        const name = window.prompt('Token name (for example "Entra ID provisioning")');
        if (name) router.post(route('identity.scim.store'), { name });
    };
    const revoke = (t) => {
        if (window.confirm(`Revoke "${t.name}"? Provisioning using it stops immediately.`)) router.post(route('identity.scim.revoke', t.id));
    };
    return (
        <AuthenticatedLayout header="SCIM Tokens">
            <Head title="SCIM Tokens" />
            <PageHeader
                breadcrumbs={[{ label: 'Identity & Access' }, { label: 'SCIM Tokens' }]}
                title="SCIM v2 Provisioning"
                subtitle="User and group provisioning from Entra ID, Okta, Ping. RFC 7644 compliant."
                actions={canEdit && <button onClick={create} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">+ New token</button>}
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Scopes</th>
                            <th className="px-3 py-2">Expires</th>
                            <th className="px-3 py-2">Last Used</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {tokens.map((t) => (
                            <tr key={t.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{t.name}</td>
                                <td className="px-3 py-2 text-xs">{(t.scopes || []).map((s) => (
                                    <span key={s} className="inline-block mr-1 px-2 py-0.5 rounded bg-[#C9A86A]/15 text-[#0A1F44] text-[10px]">{s}</span>
                                ))}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{t.expires_at}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{t.last_used_at || '—'}</td>
                                <td className="px-3 py-2 text-xs">{t.revoked_at ? 'Revoked' : 'Active'}</td>
                                <td className="px-3 py-2 text-right">
                                    {canEdit && !t.revoked_at && (
                                        <button onClick={() => revoke(t)} className="text-xs text-[#B3261E] hover:underline">Revoke</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

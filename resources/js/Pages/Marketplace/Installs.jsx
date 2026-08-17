import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function Installs({ installs = [] }) {
    return (
        <AuthenticatedLayout header="Installed Packs">
            <Head title="Installed Packs" />
            <PageHeader
                breadcrumbs={[{ label: 'Marketplace' }, { label: 'Installed Packs' }]}
                title="Installed Content Packs"
                subtitle="Content bundles installed into this tenant. All signed with Cosign."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">Publisher</th>
                            <th className="px-3 py-2">Version</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Installed</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {installs.map((i) => (
                            <tr key={i.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{i.item?.name}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{i.item?.publisher}</td>
                                <td className="px-3 py-2 text-xs">v{i.version}</td>
                                <td className="px-3 py-2"><StatusBadge status={i.status === 'active' ? 'active' : 'draft'} label={i.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{i.installed_at}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

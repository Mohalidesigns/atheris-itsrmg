import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';

export default function FeatureFlagsSettings({ flags = [] }) {
    const toggle = (id) => router.post(route('settings.feature-flags.toggle', id));
    return (
        <AuthenticatedLayout header="Feature Flags">
            <Head title="Feature Flags" />
            <PageHeader
                breadcrumbs={[{ label: 'Settings' }, { label: 'Feature Flags' }]}
                title="Feature Flags"
                subtitle="LaunchDarkly-style dark-launch toggles for every module. Tenant-scoped or platform-wide."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Key</th>
                            <th className="px-3 py-2">Tenant</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Rollout %</th>
                            <th className="px-3 py-2">Toggle</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {flags.map((f) => (
                            <tr key={f.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{f.key}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{f.tenant_id ? `#${f.tenant_id}` : 'All'}</td>
                                <td className="px-3 py-2"><StatusBadge status={f.enabled ? 'enabled' : 'disabled'} label={f.enabled ? 'Enabled' : 'Disabled'} /></td>
                                <td className="px-3 py-2 text-xs">{f.rollout_percent}%</td>
                                <td className="px-3 py-2">
                                    <button onClick={() => toggle(f.id)}
                                        className={`w-10 h-5 rounded-full relative ${f.enabled ? 'bg-[#2D7D46]' : 'bg-gray-300'}`}>
                                        <span className={`absolute top-0.5 ${f.enabled ? 'right-0.5' : 'left-0.5'} w-4 h-4 rounded-full bg-white shadow transition-all`} />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

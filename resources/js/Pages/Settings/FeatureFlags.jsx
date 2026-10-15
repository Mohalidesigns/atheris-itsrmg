import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, router } from '@inertiajs/react';

export default function FeatureFlagsSettings({ flags = [], isSuperAdmin = false }) {
    const toggle = (id) => router.post(route('settings.feature-flags.toggle', id), {}, { preserveScroll: true });
    const toggleGlobal = (id) => router.post(route('settings.feature-flags.toggle-global', id), {}, { preserveScroll: true });
    const Switch = ({ on, onClick }) => (
        <button onClick={onClick} className={`w-10 h-5 rounded-full relative ${on ? 'bg-[#2D7D46]' : 'bg-gray-300'}`}>
            <span className={`absolute top-0.5 ${on ? 'right-0.5' : 'left-0.5'} w-4 h-4 rounded-full bg-white shadow transition-all`} />
        </button>
    );
    return (
        <AuthenticatedLayout header="Feature Flags">
            <Head title="Feature Flags" />
            <PageHeader
                breadcrumbs={[{ label: 'Settings' }, { label: 'Feature Flags' }]}
                title="Feature Flags"
                subtitle="Your toggle changes this bank only (a per-bank override of the platform default). Only a Super Admin can change platform defaults."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Key</th>
                            <th className="px-3 py-2">Platform default</th>
                            <th className="px-3 py-2">Your bank</th>
                            <th className="px-3 py-2">Effective</th>
                            <th className="px-3 py-2">Toggle (your bank)</th>
                            {isSuperAdmin && <th className="px-3 py-2">Toggle (default)</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {flags.map((f) => (
                            <tr key={f.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{f.key}</td>
                                <td className="px-3 py-2 text-xs">{f.default_enabled ? 'On' : 'Off'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{f.override === null ? 'Inherits default' : (f.override ? 'Override: On' : 'Override: Off')}</td>
                                <td className="px-3 py-2"><StatusBadge status={f.enabled ? 'enabled' : 'disabled'} label={f.enabled ? 'Enabled' : 'Disabled'} /></td>
                                <td className="px-3 py-2"><Switch on={f.enabled} onClick={() => toggle(f.id)} /></td>
                                {isSuperAdmin && <td className="px-3 py-2"><Switch on={f.default_enabled} onClick={() => toggleGlobal(f.id)} /></td>}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

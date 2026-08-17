import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function ThemeSettings({ theme }) {
    const tokens = theme?.tokens || { navy: '#0A1F44', gold: '#C9A86A', green: '#2D7D46' };
    return (
        <AuthenticatedLayout header="Tenant Theme">
            <Head title="Tenant Theme" />
            <PageHeader
                breadcrumbs={[{ label: 'Settings' }, { label: 'Tenant Theme' }]}
                title="Tenant Theme"
                subtitle="Customise colour tokens + logo. Live-preview changes apply across the platform instantly."
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Colour tokens</h3>
                    <div className="grid grid-cols-2 gap-4 mt-3">
                        {Object.entries(tokens).map(([k, v]) => (
                            <div key={k}>
                                <label className="block text-xs text-[#718096] mb-1 uppercase tracking-wider">{k}</label>
                                <div className="flex items-center gap-2">
                                    <span className="w-10 h-10 rounded-lg border border-gray-200" style={{ background: v }} />
                                    <input type="text" defaultValue={v}
                                        className="flex-1 rounded-lg border border-gray-200 text-sm px-3 py-2 font-mono" />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-fit">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Logo</h3>
                    <div className="mt-3 border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                        <p className="text-xs text-[#718096]">Upload tenant logo (PNG, SVG)</p>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

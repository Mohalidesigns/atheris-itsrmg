import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function SharedDirectory({ vendors = [] }) {
    return (
        <AuthenticatedLayout header="Shared Nigerian Vendor Directory">
            <Head title="Shared Vendor Directory" />
            <PageHeader
                breadcrumbs={[{ label: 'Vendor Management' }, { label: 'Shared Vendor Directory' }]}
                title="Shared Nigerian Vendor Directory"
                subtitle="Atheris-maintained baseline DDQ, contact info, and controls for Interswitch, NIBSS, CSCS, FMDQ, Unified Payments, e-Tranzact, Teamapt and more."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {vendors.map((v) => (
                    <div key={v.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-[#0A1F44]">{v.legal_name}</h3>
                                <p className="text-xs text-[#718096]">{v.category} · {v.country}</p>
                            </div>
                            <span className="text-[10px] px-2 py-0.5 rounded-full bg-[#C9A86A]/15 text-[#0A1F44]">DDQ {v.baseline_ddq_version}</span>
                        </div>
                        {v.description && <p className="text-xs text-[#2D3748] mt-2 line-clamp-3">{v.description}</p>}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

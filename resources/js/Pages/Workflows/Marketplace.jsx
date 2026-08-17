import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function WorkflowsMarketplace({ templates = [] }) {
    return (
        <AuthenticatedLayout header="Workflow Marketplace">
            <Head title="Workflow Marketplace" />
            <PageHeader
                breadcrumbs={[{ label: 'Workflow Studio', href: route('workflows.index') }, { label: 'Marketplace' }]}
                title="Nigerian Workflow Templates"
                subtitle="CBN ITSM incident, NDPC DPIA, NAICOM breach, NDIC risk finding — pre-built, review-ready."
            />
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                {templates.map((t) => (
                    <div key={t.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <div className="flex items-start justify-between">
                            <h3 className="text-sm font-semibold text-[#2D3748]">{t.name}</h3>
                            <span className="text-[10px] px-2 py-0.5 rounded-full bg-[#C9A86A]/15 text-[#0A1F44]">Template</span>
                        </div>
                        <p className="text-xs text-[#718096] mt-1">{t.category}</p>
                        <button className="mt-4 w-full px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Clone into tenant</button>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

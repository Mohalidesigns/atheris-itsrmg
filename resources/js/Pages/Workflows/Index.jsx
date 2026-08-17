import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function WorkflowsIndex({ workflows = [] }) {
    return (
        <AuthenticatedLayout header="Workflow Studio">
            <Head title="Workflow Studio" />
            <PageHeader
                breadcrumbs={[{ label: 'Workflow Studio' }]}
                title="Workflow Studio"
                subtitle="Low-code workflow builder — drag-drop decisions, approvers, SLAs, integration calls."
                actions={<Link href={route('workflows.marketplace')} className="px-3 py-2 rounded-lg bg-[#C9A86A] text-[#0A1F44] font-semibold text-sm">Marketplace</Link>}
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {workflows.map((w) => (
                    <Link key={w.id} href={route('workflows.show', w.id)}
                        className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:border-[#0A1F44]/30 hover:shadow-md transition block">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{w.name}</h3>
                                <p className="text-xs text-[#718096]">{w.category} · v{w.version}</p>
                            </div>
                            <StatusBadge status={w.status === 'published' ? 'active' : 'draft'} label={w.status} />
                        </div>
                        <div className="mt-3 flex items-center gap-1 text-xs text-[#718096]">
                            <span>{(w.definition_json?.nodes || []).length} nodes</span>
                            <span>·</span>
                            <span>{(w.definition_json?.edges || []).length} edges</span>
                        </div>
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

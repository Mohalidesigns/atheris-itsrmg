import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AtherisFlow from '@/Components/AtherisFlow';
import { Head } from '@inertiajs/react';

export default function BusinessServicesGraph({ services = [], dependencies = [], capabilities = [] }) {
    const criticalityColorNodeType = (c) => ({
        critical: 'threat',
        high: 'vulnerability',
        medium: 'service',
        low: 'process',
    })[c] || 'service';

    const nodes = [
        { id: 'core', label: 'Kano Heritage Core', type: 'risk' },
        ...services.slice(0, 16).map((s) => ({
            id: 's-' + s.id,
            label: s.name,
            type: criticalityColorNodeType(s.criticality),
        })),
    ];
    // Radial: core as centre; every service edges back to core + dependency edges
    const edges = [
        ...services.slice(0, 16).map((s) => ({ id: `ec-${s.id}`, source: 'core', target: 's-' + s.id })),
        ...dependencies.slice(0, 25).map((d, i) => ({
            id: `ed-${i}`,
            source: 's-' + d.source_id,
            target: 's-' + d.target_id,
            label: d.relation_type,
            animated: true,
        })),
    ].filter((e) => nodes.some((n) => n.id === e.source) && nodes.some((n) => n.id === e.target));

    return (
        <AuthenticatedLayout header="Business Service Graph">
            <Head title="Business Service Graph" />
            <PageHeader
                breadcrumbs={[{ label: 'Asset Management' }, { label: 'Business Services', href: route('business-services.index') }, { label: 'Dependency Graph' }]}
                title="Business Service Dependency Graph"
                subtitle="Interactive map of Nigerian DMB channels, services and their upstream/downstream dependencies. Drag nodes to explore. Animated edges show live dependencies."
            />
            <AtherisFlow nodes={nodes} edges={edges} layout="radial" height={640} />
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-[#718096]">
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#B3261E]"></span> Critical</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#E5A100]"></span> High</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#1D4ED8]"></span> Medium</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#718096]"></span> Low</span>
            </div>
        </AuthenticatedLayout>
    );
}

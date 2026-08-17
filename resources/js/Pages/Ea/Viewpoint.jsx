import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AtherisFlow from '@/Components/AtherisFlow';
import { Head, Link } from '@inertiajs/react';

export default function Viewpoint({ viewpoint, payload = { nodes: [], edges: [] } }) {
    const nodes = (payload.nodes || []).map((n) => ({
        id: n.id,
        label: n.data?.label || n.id,
        type: n.data?.type || 'default',
        position: n.position,
    }));
    const edges = (payload.edges || []).map((e) => ({
        id: e.id,
        source: e.source,
        target: e.target,
        label: e.label || e.data?.relation,
        animated: e.animated || false,
    }));

    const title = viewpoint.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

    return (
        <AuthenticatedLayout header={`Viewpoint — ${title}`}>
            <Head title={`Viewpoint — ${title}`} />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Viewpoints' }, { label: title }]}
                title={`ArchiMate Viewpoint — ${title}`}
                subtitle="Auto-generated from the live EA repository. Renders node/edge data straight from the relationship graph."
                actions={<Link href={route('ea.diagrams')} className="px-3 py-2 rounded-lg border border-gray-200 text-sm">Back to diagrams</Link>}
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-3 text-sm">
                    <span className="text-gray-500 text-xs">Nodes</span><div className="font-bold text-lg text-[#0A1F44]">{nodes.length}</div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-3 text-sm">
                    <span className="text-gray-500 text-xs">Edges</span><div className="font-bold text-lg text-[#0A1F44]">{edges.length}</div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-3 text-sm">
                    <span className="text-gray-500 text-xs">Viewpoint</span><div className="font-mono text-xs text-[#0A1F44]">{viewpoint}</div>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-3 text-sm">
                    <span className="text-gray-500 text-xs">Generated</span><div className="text-xs">{payload.generated_at || '—'}</div>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-3">
                <AtherisFlow nodes={nodes} edges={edges} layout="grid" height={620} />
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AtherisFlow from '@/Components/AtherisFlow';
import { Head, Link } from '@inertiajs/react';

const stepTypeFor = (keyOrLabel) => {
    const s = String(keyOrLabel).toLowerCase();
    if (s === 'start') return 'start';
    if (s === 'end' || s === 'close') return 'end';
    if (/(approval|review|gateway|decision)/.test(s)) return 'gateway';
    if (/(notif|notify)/.test(s)) return 'threat';
    if (/(remediate|resolve)/.test(s)) return 'control';
    return 'task';
};

export default function WorkflowShow({ workflow }) {
    const def = workflow.definition_json || { nodes: [], edges: [] };

    // Build a left-to-right pipeline layout
    const nodes = (def.nodes || []).map((n, i) => ({
        id: String(n.id),
        label: n.label,
        type: stepTypeFor(n.label),
        position: { x: 40 + i * 180, y: 220 },
    }));
    const edges = (def.edges || []).map((e, i) => ({
        id: `e-${i}`,
        source: String(e.source),
        target: String(e.target),
        animated: true,
    }));

    return (
        <AuthenticatedLayout header={workflow.name}>
            <Head title={workflow.name} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Workflow Studio', href: route('workflows.index') },
                    { label: workflow.name },
                ]}
                title={workflow.name}
                subtitle={`${workflow.category} · v${workflow.version} · ${workflow.status}`}
                actions={<StatusBadge status={workflow.status === 'published' ? 'active' : 'draft'} label={workflow.status} />}
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
                <h3 className="text-sm font-semibold text-[#2D3748] mb-2">Canvas</h3>
                <AtherisFlow nodes={nodes} edges={edges} layout="horizontal" height={480} />
                <p className="text-[10px] text-[#718096] mt-2">Low-code workflow canvas — drag nodes, zoom, and inspect edges. Full drag-drop editing ships in Phase 7.</p>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Active instances</h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Subject</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Started</th>
                            <th className="px-3 py-2">Completed</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {(workflow.instances || []).map((i) => (
                            <tr key={i.id}>
                                <td className="px-3 py-2 text-xs text-[#2D3748]">{i.subject_type} #{i.subject_id}</td>
                                <td className="px-3 py-2"><StatusBadge status={i.status === 'running' ? 'in_progress' : 'active'} label={i.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{i.started_at}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{i.completed_at || '—'}</td>
                            </tr>
                        ))}
                        {(workflow.instances || []).length === 0 && (
                            <tr><td colSpan={4} className="px-3 py-8 text-center text-xs text-[#718096]">No instances yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

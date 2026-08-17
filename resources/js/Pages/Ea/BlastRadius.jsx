import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import AtherisFlow from '@/Components/AtherisFlow';
import { Head, router } from '@inertiajs/react';

export default function BlastRadius({ app, impact = { radius: { nodes: [], edges: [] }, summary: {} }, applications = [], depth = 3 }) {
    const pickApp = (e) => router.get(route('ea.blast-radius'), { app_id: e.target.value, depth }, { preserveState: true });
    const pickDepth = (e) => router.get(route('ea.blast-radius'), { app_id: app?.id, depth: e.target.value }, { preserveState: true });

    const nodes = (impact.radius?.nodes || []).map((n) => ({
        id: n.key,
        label: n.name,
        type: n.is_root ? 'risk' : (n.type === 'application' ? (n.criticality === 'critical' ? 'threat' : 'service') : (n.type === 'node' || n.type === 'techcomponent' ? 'control' : 'process')),
        data: { criticality: n.criticality, level: n.level },
    }));
    const edges = (impact.radius?.edges || []).map((e, i) => ({
        id: `e-${i}`,
        source: e.source,
        target: e.target,
        label: e.label,
        animated: e.type === 'flow' || e.type === 'interface',
    }));

    return (
        <AuthenticatedLayout header="Blast Radius">
            <Head title="Blast Radius" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Integration' }, { label: 'Blast Radius' }]}
                title="N-Hop Change Blast Radius"
                subtitle="Multi-hop traversal of ea_relationships + ea_interfaces. Returns affected apps, processes, capabilities and control gaps."
                actions={
                    <div className="flex items-center gap-2">
                        <select onChange={pickApp} value={app?.id || ''} className="rounded-lg border border-gray-200 text-sm px-3 py-2">
                            {applications.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </select>
                        <select onChange={pickDepth} value={depth} className="rounded-lg border border-gray-200 text-sm px-3 py-2">
                            <option value={1}>1 hop</option><option value={2}>2 hops</option><option value={3}>3 hops</option><option value={4}>4 hops</option>
                        </select>
                    </div>
                }
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.blast-radius')} current="ea.blast-radius" />

            <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
                <KpiCard label="Source" value={app?.name || '—'} tone="navy" />
                <KpiCard label="Apps reached" value={impact.summary?.apps || 0} tone="gold" />
                <KpiCard label="Critical apps" value={impact.summary?.critical_apps || 0} tone="red" />
                <KpiCard label="Processes" value={impact.summary?.processes || 0} tone="white" />
                <KpiCard label="Capabilities" value={impact.summary?.capabilities || 0} tone="white" />
                <KpiCard label="Control gaps" value={impact.summary?.control_gaps || 0} tone="amber" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-[1fr,360px] gap-4">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                    <AtherisFlow nodes={nodes} edges={edges} layout="radial" height={520} />
                </div>
                <div className="space-y-3">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <h3 className="font-bold text-[#0A1F44] text-sm mb-2">Processes impacted ({impact.processes?.length || 0})</h3>
                        <ul className="text-xs space-y-1 max-h-40 overflow-y-auto">
                            {(impact.processes || []).map((p) => (
                                <li key={p.id} className="flex items-center justify-between border-b border-gray-50 pb-1">
                                    <span>{p.name}</span>
                                    <StatusBadge status={p.criticality === 'critical' ? 'critical' : 'moderate'} label={p.criticality} />
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <h3 className="font-bold text-[#0A1F44] text-sm mb-2">Capabilities ({impact.capabilities?.length || 0})</h3>
                        <ul className="text-xs space-y-1 max-h-40 overflow-y-auto">
                            {(impact.capabilities || []).map((c) => <li key={c.id} className="border-b border-gray-50 pb-1">{c.code} — {c.name}</li>)}
                        </ul>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <h3 className="font-bold text-[#0A1F44] text-sm mb-2">Initiatives ({impact.initiatives?.length || 0})</h3>
                        <ul className="text-xs space-y-1 max-h-40 overflow-y-auto">
                            {(impact.initiatives || []).map((i) => <li key={i.id} className="border-b border-gray-50 pb-1">{i.code || i.name} <span className="text-gray-500">{i.adm_phase}</span></li>)}
                        </ul>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

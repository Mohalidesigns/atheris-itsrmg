import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AtherisFlow from '@/Components/AtherisFlow';
import { Head, router } from '@inertiajs/react';
import { useMemo } from 'react';

const CRIT_TYPE = { critical: 'threat', high: 'vulnerability', medium: 'service', low: 'process' };
const COL = { capability: 40, service: 400, asset: 820 };
const ROW = 70;

/**
 * Layered map: capabilities (left) → business services (middle) → supporting assets (right).
 * Gold edges between services read "provider serves consumer"; grey edges tie a service to its
 * capability and its assets.
 */
export default function BusinessServicesGraph({ capabilities = [], services = [], dependencies = [], assets = [], filters = {}, orgName }) {
    const { nodes, edges } = useMemo(() => {
        const ordered = [...services].sort((a, b) => (a.capability_id ?? 0) - (b.capability_id ?? 0) || a.code.localeCompare(b.code));
        const nodes = [
            ...capabilities.map((c, i) => ({ id: `c-${c.id}`, label: c.name, type: 'capability', position: { x: COL.capability, y: i * ROW * 2 + 20 } })),
            ...ordered.map((s, i) => ({
                id: `s-${s.id}`,
                label: `${s.name}${s.rto_minutes != null ? ` · RTO ${s.rto_minutes}m` : ''}${s.assets_count ? ` · ${s.assets_count} assets` : ' · no assets'}`,
                type: CRIT_TYPE[s.criticality] || 'service',
                href: route('business-assets.show', s.id),
                position: { x: COL.service, y: i * ROW + 20 },
            })),
            ...assets.map((a, i) => ({
                id: `a-${a.id}`, label: `${a.code} ${a.name}`, type: 'asset',
                href: route('assets.show', a.id), position: { x: COL.asset, y: i * ROW + 20 },
            })),
        ];
        const edges = [
            ...ordered.filter((s) => s.capability_id && capabilities.some((c) => c.id === s.capability_id))
                .map((s) => ({ id: `cs-${s.id}`, source: `c-${s.capability_id}`, target: `s-${s.id}` })),
            ...dependencies.map((d) => ({ id: `d-${d.id}`, source: `s-${d.provider_id}`, target: `s-${d.consumer_id}`, label: 'serves', animated: true })),
            ...assets.flatMap((a) => a.service_ids.map((sid) => ({ id: `as-${a.id}-${sid}`, source: `a-${a.id}`, target: `s-${sid}` }))),
        ];
        return { nodes, edges };
    }, [capabilities, services, dependencies, assets]);

    const apply = (patch) => router.get(route('business-services.graph'), { ...filters, ...patch }, { preserveState: true, preserveScroll: true });
    const height = Math.max(560, Math.max(services.length, assets.length, capabilities.length * 2) * ROW + 80);

    return (
        <AuthenticatedLayout header="Business Service Graph">
            <Head title="Business Service Graph" />
            <PageHeader
                breadcrumbs={[{ label: 'Asset Management' }, { label: 'Business Services', href: route('business-services.index') }, { label: 'Dependency Graph' }]}
                title={`${orgName ? `${orgName}: ` : ''}business service dependencies`}
                subtitle="Capabilities, the services that realise them, and the physical assets each service runs on. Animated edges: the provider serves the consumer. Click a service or asset to open it."
            />
            <div className="mb-3 flex flex-wrap items-center gap-3 text-sm">
                <select value={filters.criticality || ''} onChange={(e) => apply({ criticality: e.target.value || undefined })} className="rounded-lg border-gray-200 text-sm">
                    <option value="">All criticalities</option>
                    {['critical', 'high', 'medium', 'low'].map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
                <label className="flex items-center gap-2 text-[#2D3748]">
                    <input type="checkbox" checked={filters.assets !== false} onChange={(e) => apply({ assets: e.target.checked ? 1 : 0 })} className="rounded border-gray-300" />
                    Show supporting assets
                </label>
                <span className="text-xs text-[#718096]">{services.length} services · {dependencies.length} dependencies · {assets.length} assets</span>
            </div>
            {services.length === 0 ? (
                <div className="rounded-xl border border-gray-100 bg-white p-10 text-center text-sm text-[#718096]">No business services match.</div>
            ) : (
                <AtherisFlow nodes={nodes} edges={edges} height={Math.min(height, 1400)} onNodeClick={(n) => n.href && router.visit(n.href)} />
            )}
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-[#718096]">
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#7B5AA6]"></span> Capability</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#B3261E]"></span> Critical service</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#E5A100]"></span> High</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#1D4ED8]"></span> Medium</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#718096]"></span> Low</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#2D7D46]"></span> Asset</span>
            </div>
        </AuthenticatedLayout>
    );
}

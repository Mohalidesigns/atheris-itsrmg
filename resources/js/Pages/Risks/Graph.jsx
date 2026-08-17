import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AtherisFlow from '@/Components/AtherisFlow';
import { Head } from '@inertiajs/react';

export default function RisksGraph({ risks = [], controls = [], vulns = [], assets = [] }) {
    const nodes = [
        { id: 'centre', label: 'Risk Universe', type: 'risk' },
        ...risks.slice(0, 8).map((r, i) => ({ id: 'r-' + r.id, label: (r.risk_id_code || r.title || 'Risk').slice(0, 22), type: 'risk' })),
        ...controls.slice(0, 8).map((c, i) => ({ id: 'c-' + c.id, label: (c.control_code || c.title || 'Control').slice(0, 22), type: 'control' })),
        ...vulns.slice(0, 8).map((v, i) => ({ id: 'v-' + v.id, label: (v.cve_id || v.title || 'Vuln').slice(0, 22), type: 'vulnerability' })),
        ...assets.slice(0, 8).map((a, i) => ({ id: 'a-' + a.id, label: (a.asset_id_code || a.name || 'Asset').slice(0, 22), type: 'asset' })),
    ];
    const edges = nodes.slice(1).map((n) => ({ id: `e-${n.id}`, source: 'centre', target: n.id }));

    return (
        <AuthenticatedLayout header="Risk Graph">
            <Head title="Risk Graph" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Risk Management' }, { label: 'Risk Graph' }]}
                title="Risk Graph"
                subtitle="Unified Threat → Risk → Control → Vulnerability → Asset dependency view. Drag nodes · zoom · pan · mini-map."
            />
            <AtherisFlow nodes={nodes} edges={edges} layout="radial" height={620} />
            <div className="mt-3 flex flex-wrap gap-3 text-xs text-[#718096]">
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#0A1F44]"></span> Risks</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#C9A86A]"></span> Controls</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#E5A100]"></span> Vulnerabilities</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 rounded-full bg-[#2D7D46]"></span> Assets</span>
            </div>
        </AuthenticatedLayout>
    );
}

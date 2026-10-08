import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import AtherisFlow from '@/Components/AtherisFlow';
import { Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
import { humanize } from '@/Utils/risk';

const COLUMNS = { threat: 0, risk: 1, control: 2, asset: 3, vulnerability: 4 };
const COL_WIDTH = 300;
const ROW_HEIGHT = 70;

// Layered layout: Threats → Risks → Controls / Assets ← Vulnerabilities, built from real links only.
function buildGraph(risks, vulns) {
    const nodes = new Map();
    const edges = [];
    const add = (id, type, label, href, title) => {
        if (!nodes.has(id)) nodes.set(id, { id, type, label, href, title });
    };

    risks.forEach((r) => {
        const rid = `r-${r.id}`;
        add(rid, 'risk', `${r.risk_id_code} · ${r.inherent_score ?? '—'}`, route('risks.show', r.id), r.title);
        (r.threat_assessments || []).forEach((ta) => {
            if (!ta.threat) return;
            add(`t-${ta.threat.id}`, 'threat', ta.threat.threat_id_code, route('threats.show', ta.threat.id), ta.threat.name);
            edges.push({ id: `et-${ta.id}`, source: `t-${ta.threat.id}`, target: rid, label: ta.score ? String(ta.score) : undefined });
        });
        (r.controls || []).forEach((c) => {
            add(`c-${c.id}`, 'control', c.control_code, route('controls.show', c.id), `${c.title} (${humanize(c.effectiveness)})`);
            edges.push({ id: `ec-${r.id}-${c.id}`, source: rid, target: `c-${c.id}` });
        });
        (r.assets || []).forEach((a) => {
            add(`a-${a.id}`, 'asset', a.asset_id_code, route('assets.show', a.id), a.name);
            edges.push({ id: `ea-${r.id}-${a.id}`, source: rid, target: `a-${a.id}` });
        });
    });
    vulns.forEach((v) => {
        add(`v-${v.id}`, 'vulnerability', v.cve_id || v.vuln_id_code, route('vulnerabilities.show', v.id), `${v.title} (${humanize(v.severity)})`);
        (v.asset_ids || []).forEach((aid) => edges.push({ id: `ev-${v.id}-${aid}`, source: `v-${v.id}`, target: `a-${aid}` }));
    });

    const rows = {};
    const laid = [...nodes.values()].map((n) => {
        const col = COLUMNS[n.type];
        rows[col] = (rows[col] || 0) + 1;
        return { ...n, position: { x: col * COL_WIDTH, y: (rows[col] - 1) * ROW_HEIGHT } };
    });
    // Centre each column vertically against the tallest one.
    const tallest = Math.max(...Object.values(rows), 1);
    laid.forEach((n) => { n.position.y += ((tallest - rows[COLUMNS[n.type]]) * ROW_HEIGHT) / 2; });

    return { nodes: laid, edges, counts: rows };
}

export default function RisksGraph({ risks = [], vulns = [], categories = [], filters = {} }) {
    const { nodes, edges, counts } = useMemo(() => buildGraph(risks, vulns), [risks, vulns]);
    const apply = (params) => router.get(route('risks.graph'), { ...filters, ...params }, { preserveState: true, replace: true });
    const selectClass = 'text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]';

    return (
        <AuthenticatedLayout header="Risk Graph">
            <Head title="Risk Graph" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Risk Management' }, { label: 'Risk Graph' }]}
                title="Risk Graph"
                subtitle="Threat → Risk → Control / Asset ← Vulnerability, drawn from the recorded links of your highest-exposure open risks. Click a node to open it."
            />
            <div className="flex flex-wrap items-center gap-2 mb-3">
                <select value={filters.limit || 10} onChange={(e) => apply({ limit: e.target.value })} className={selectClass}>
                    {[10, 20, 40].map((n) => <option key={n} value={n}>Top {n} risks</option>)}
                </select>
                <select value={filters.category_id || ''} onChange={(e) => apply({ category_id: e.target.value || undefined })} className={selectClass}>
                    <option value="">All categories</option>
                    {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select value={filters.rating || ''} onChange={(e) => apply({ rating: e.target.value || undefined })} className={selectClass}>
                    <option value="">All ratings</option>
                    {['critical', 'high', 'medium', 'low', 'very_low'].map((r) => <option key={r} value={r}>{humanize(r)}</option>)}
                </select>
                <span className="text-xs text-[#718096]">{nodes.length} nodes · {edges.length} links</span>
            </div>
            {nodes.length === 0 ? (
                <p className="text-sm text-[#718096] bg-white rounded-xl border border-gray-100 p-8 text-center">No open risks match these filters.</p>
            ) : (
                <AtherisFlow nodes={nodes} edges={edges} layout="grid" height={640}
                    onNodeClick={(node) => node.href && router.visit(node.href)} />
            )}
            <div className="mt-3 flex flex-wrap gap-4 text-xs text-[#718096]">
                {[
                    ['#B3261E', 'Threats', COLUMNS.threat], ['#0A1F44', 'Risks (code · inherent score)', COLUMNS.risk],
                    ['#C9A86A', 'Controls', COLUMNS.control], ['#2D7D46', 'Assets', COLUMNS.asset], ['#E5A100', 'Open vulnerabilities', COLUMNS.vulnerability],
                ].map(([color, label, col]) => (
                    <span key={label} className="flex items-center gap-1">
                        <span className="w-3 h-3 rounded-full" style={{ backgroundColor: color }} /> {label} ({counts[col] || 0})
                    </span>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

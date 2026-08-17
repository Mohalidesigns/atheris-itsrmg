import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import AtherisFlow from '@/Components/AtherisFlow';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * Impact — WS 4.2 / B14, n-hop change impact from any entity.
 *
 * §5.4 records why this is worth building rather than buying around: n-hop
 * impact is "Orbus's *weakest* rated feature (77/100). Attackable."
 *
 * The screen answers a change board's questions in their own order: how far does
 * this reach, what does it cost, what has a recovery objective tight enough to
 * need a window, and how much of the answer rests on evidence anyone has signed
 * for.
 */

const NODE_TONES = {
    EaApplication: 'service',
    Capability: 'capability',
    TechComponent: 'asset',
    EaInterface: 'process',
    Process: 'process',
    LogicalEntity: 'control',
    Site: 'vendor',
    Vendor: 'vendor',
    InfoDomain: 'control',
    Initiative: 'task',
    Plateau: 'gateway',
};

const naira = (value) => (value === null || value === undefined
    ? '—'
    : `₦${Number(value).toLocaleString(undefined, { maximumFractionDigits: 0 })}`);

export default function Impact({
    result,
    entityType,
    entityId,
    depth,
    direction,
    startTypes = [],
    entities = [],
    maxDepth = 5,
    diagrams = [],
}) {
    const [search, setSearch] = useState('');
    const graph = result?.graph || { nodes: [], edges: [], by_hop: {}, by_type: {} };
    const summary = result?.summary || {};
    const root = graph.root;

    const reload = (params) => router.get(route('ea.impact'), {
        entity_type: entityType, entity_id: entityId, depth, direction, ...params,
    }, { preserveState: true, preserveScroll: true });

    const flowNodes = useMemo(() => graph.nodes.map((node) => ({
        id: node.key,
        label: node.hop === 0 ? `★ ${node.name}` : node.name,
        type: NODE_TONES[node.label_type] || 'default',
        position: {
            x: 80 + (node.hop || 0) * 260,
            y: 40 + (graph.nodes.filter((n) => n.hop === node.hop).findIndex((n) => n.key === node.key)) * 76,
        },
    })), [graph.nodes]);

    const flowEdges = useMemo(() => graph.edges.map((edge) => ({
        id: edge.id, source: edge.source, target: edge.target, label: edge.relation,
    })), [graph.edges]);

    const filteredNodes = useMemo(() => {
        const term = search.trim().toLowerCase();
        const rows = graph.nodes.filter((n) => n.hop > 0);
        return term ? rows.filter((n) => `${n.name} ${n.label_type}`.toLowerCase().includes(term)) : rows;
    }, [graph.nodes, search]);

    return (
        <AuthenticatedLayout header="Change Impact">
            <Head title="Change Impact" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Integration Architecture' }, { label: 'Change Impact' }]}
                title="n-hop Change Impact"
                subtitle="Pick any entity and a depth. The traversal crosses every edge the repository holds — the generic ArchiMate graph, interfaces, the capability pivot, process links, technology assignments, hosting sites, vendors and data flows."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.blast-radius')} current="ea.impact" />

            <div className="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-gray-100 bg-white p-4 shadow-sm md:grid-cols-4">
                <label className="text-xs">
                    <span className="mb-1 block font-medium text-gray-500">Starting entity type</span>
                    <select
                        value={entityType}
                        onChange={(e) => reload({ entity_type: e.target.value, entity_id: null })}
                        className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                    >
                        {startTypes.map((type) => (
                            <option key={type.value} value={type.value}>{type.label} ({type.count})</option>
                        ))}
                    </select>
                </label>
                <label className="text-xs">
                    <span className="mb-1 block font-medium text-gray-500">Entity</span>
                    <select
                        value={entityId || ''}
                        onChange={(e) => reload({ entity_id: e.target.value })}
                        className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                    >
                        {entities.map((entity) => (
                            <option key={entity.id} value={entity.id}>
                                {entity.code ? `${entity.code} — ` : ''}{entity.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-xs">
                    <span className="mb-1 block font-medium text-gray-500">Depth (hops)</span>
                    <div className="flex items-center gap-2">
                        <input
                            type="range"
                            min={1}
                            max={maxDepth}
                            value={depth}
                            onChange={(e) => reload({ depth: e.target.value })}
                            className="w-full"
                        />
                        <span className="w-6 text-center text-sm font-bold text-[#0A1F44]">{depth}</span>
                    </div>
                </label>
                <label className="text-xs">
                    <span className="mb-1 block font-medium text-gray-500">Direction</span>
                    <select
                        value={direction}
                        onChange={(e) => reload({ direction: e.target.value })}
                        className="w-full rounded-lg border border-gray-200 px-2 py-2 text-sm"
                    >
                        <option value="both">both — everything connected</option>
                        <option value="downstream">downstream — what this affects</option>
                        <option value="upstream">upstream — what this depends on</option>
                    </select>
                </label>
            </div>

            {!root && (
                <div className="rounded-xl border border-gray-100 bg-white p-8 text-center text-sm text-gray-400 shadow-sm">
                    Pick an entity to analyse.
                </div>
            )}

            {root && (
                <>
                    <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
                        <KpiCard label="Entities reached" value={summary.nodes ?? 0} tone="navy"
                            sublabel={`${depth} hop${depth > 1 ? 's' : ''} from ${root.name}`} />
                        <KpiCard label="Applications" value={summary.applications ?? 0} tone="white"
                            sublabel={`${summary.critical_applications ?? 0} critical · ${summary.high_applications ?? 0} high`} />
                        <KpiCard label="Annual cost in scope" value={naira(summary.annual_cost_ngn)} tone="gold"
                            sublabel={summary.unknown_currency_apps ? `${summary.unknown_currency_apps} with unknown currency` : 'all currencies resolved'} />
                        <KpiCard
                            label="Tight RTO processes"
                            value={summary.tight_rto_processes ?? 0}
                            tone={(summary.tight_rto_processes ?? 0) > 0 ? 'amber' : 'white'}
                            sublabel="≤ 4h recovery objective"
                        />
                        <KpiCard label="Control gaps" value={summary.control_gaps ?? 0}
                            tone={(summary.control_gaps ?? 0) > 0 ? 'red' : 'white'} sublabel="on affected systems" />
                        <KpiCard
                            label="Evidence confidence"
                            value={summary.evidence_confidence === null || summary.evidence_confidence === undefined ? '—' : `${summary.evidence_confidence}%`}
                            tone={summary.evidence_confidence >= 60 ? 'green' : 'amber'}
                            sublabel="share of affected systems with an approved seal"
                        />
                    </div>

                    {graph.truncated && (
                        <div className="mb-4 rounded-lg border border-[#E5A100]/40 bg-[#E5A100]/5 px-3 py-2 text-xs text-[#8a6100]">
                            The traversal hit its node budget and stopped adding entities. Reduce the depth, or narrow
                            the direction, for a complete answer at this scale.
                        </div>
                    )}

                    <div className="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-[2fr,1fr]">
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <h3 className="mb-3 text-sm font-bold text-[#0A1F44]">
                                Impact graph — laid out by hop distance
                            </h3>
                            <AtherisFlow nodes={flowNodes} edges={flowEdges} layout="grid" height={480} />
                        </div>

                        <div className="space-y-4">
                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Reach per hop</h3>
                                {Object.entries(graph.by_hop || {}).map(([hop, count]) => (
                                    <div key={hop} className="mb-1 flex items-center gap-2 text-xs">
                                        <span className="w-14 text-gray-500">{hop === '0' ? 'origin' : `hop ${hop}`}</span>
                                        <div className="h-2 flex-1 rounded bg-gray-100">
                                            <div
                                                className="h-2 rounded bg-[#0A1F44]"
                                                style={{ width: `${Math.min(100, (count / Math.max(1, graph.nodes.length)) * 100)}%` }}
                                            />
                                        </div>
                                        <span className="w-8 text-right font-medium">{count}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">By entity type</h3>
                                {Object.entries(graph.by_type || {}).map(([type, count]) => (
                                    <div key={type} className="flex items-center justify-between border-b border-gray-50 py-1 text-xs">
                                        <span className="text-gray-600">{type}</span>
                                        <span className="font-medium text-[#0A1F44]">{count}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Regulatory dimensions</h3>
                                <dl className="space-y-1 text-xs">
                                    <div className="flex justify-between"><dt className="text-gray-500">Hosted outside Nigeria</dt><dd className="font-medium">{summary.cross_border_apps ?? 0}</dd></div>
                                    <div className="flex justify-between"><dt className="text-gray-500">Holding Nigerian payment data</dt><dd className="font-medium">{summary.nigerian_payment_data_apps ?? 0}</dd></div>
                                    <div className="flex justify-between"><dt className="text-gray-500">Past end-of-life technology</dt><dd className="font-medium">{summary.obsolete_tech ?? 0}</dd></div>
                                    <div className="flex justify-between"><dt className="text-gray-500">Initiatives already touching this</dt><dd className="font-medium">{summary.initiatives ?? 0}</dd></div>
                                </dl>
                            </div>

                            {diagrams.length > 0 && (
                                <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                    <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Drawn on</h3>
                                    <ul className="space-y-1 text-xs">
                                        {diagrams.map((diagram) => (
                                            <li key={diagram.id}>
                                                <Link href={route('ea.diagrams.show', diagram.id)} className="text-[#0A1F44] underline">
                                                    {diagram.code} — {diagram.name}
                                                </Link>
                                                {diagram.is_topology && <span className="ml-1 text-[10px] text-[#C9A86A]">topology</span>}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    </div>

                    {(result.tight_rto_processes || []).length > 0 && (
                        <div className="mb-5 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4">
                            <h3 className="mb-2 text-sm font-bold text-[#8a6100]">
                                Needs a change window — {result.tight_rto_processes.length} process(es) with a recovery objective of four hours or better
                            </h3>
                            <div className="flex flex-wrap gap-2 text-xs">
                                {result.tight_rto_processes.map((process) => (
                                    <span key={process.id} className="rounded-full border border-[#E5A100]/40 bg-white px-2 py-1">
                                        {process.name} · RTO {process.rto_hours}h
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div className="mb-3 flex items-center justify-between">
                            <h3 className="text-sm font-bold text-[#0A1F44]">Affected entities ({filteredNodes.length})</h3>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Filter…"
                                className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                            />
                        </div>
                        <div className="max-h-[420px] overflow-y-auto">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 border-b border-gray-100 bg-white text-xs text-gray-500">
                                    <tr>
                                        <th className="py-2 text-left">Hop</th>
                                        <th className="text-left">Type</th>
                                        <th className="text-left">Name</th>
                                        <th className="text-left">Reached via</th>
                                        <th className="text-left">Criticality</th>
                                        <th className="text-left">Lifecycle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredNodes.map((node) => (
                                        <tr key={node.key} className="border-b border-gray-50">
                                            <td className="py-1.5 text-xs text-gray-400">{node.hop}</td>
                                            <td className="text-xs text-gray-600">{node.label_type}</td>
                                            <td className="text-[#0A1F44]">{node.name}</td>
                                            <td className="text-xs text-gray-500">{node.via || '—'}</td>
                                            <td className="text-xs">{node.criticality || '—'}</td>
                                            <td className="text-xs">{node.lifecycle || '—'}</td>
                                        </tr>
                                    ))}
                                    {filteredNodes.length === 0 && (
                                        <tr><td colSpan={6} className="py-6 text-center text-xs text-gray-400">
                                            Nothing connects to {root.name} within {depth} hop(s) in this direction.
                                        </td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}

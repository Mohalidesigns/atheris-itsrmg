import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * ImpactTab — WS 4.2's "change-impact tab on every entity".
 *
 * Loaded on demand rather than with the page: §10 requires the module to be
 * usable on 3G, and an impact traversal is the most expensive thing a detail
 * page could ask for. Nobody opening an application record wants to pay for a
 * five-hop graph they may not look at.
 *
 * Props:
 *   entityType — model class name (short or fully qualified)
 *   entityId   — record id
 *   title      — optional heading override
 */

const naira = (value) => (value === null || value === undefined
    ? '—'
    : `₦${Number(value).toLocaleString(undefined, { maximumFractionDigits: 0 })}`);

export default function ImpactTab({ entityType, entityId, title = 'Change impact' }) {
    const [depth, setDepth] = useState(2);
    const [direction, setDirection] = useState('both');
    const [state, setState] = useState({ loading: false, data: null, error: null });
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open || !entityId) return;

        let cancelled = false;
        setState((current) => ({ ...current, loading: true, error: null }));

        window.axios.get(route('ea.impact.json'), {
            params: { entity_type: entityType, entity_id: entityId, depth, direction },
        })
            .then(({ data }) => { if (!cancelled) setState({ loading: false, data, error: null }); })
            .catch((error) => {
                if (!cancelled) {
                    setState({
                        loading: false,
                        data: null,
                        error: error?.response?.data?.message || 'Could not compute the impact for this record.',
                    });
                }
            });

        return () => { cancelled = true; };
    }, [open, entityType, entityId, depth, direction]);

    const summary = state.data?.summary || {};
    const nodes = (state.data?.graph?.nodes || []).filter((node) => node.hop > 0);

    return (
        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 className="font-bold text-[#0A1F44]">{title}</h3>
                    <p className="text-xs text-gray-500">
                        What else is affected if this record changes — across interfaces, capabilities, technology,
                        processes, hosting sites and vendors.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {open && (
                        <>
                            <select
                                value={direction}
                                onChange={(e) => setDirection(e.target.value)}
                                className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                            >
                                <option value="both">both directions</option>
                                <option value="downstream">downstream</option>
                                <option value="upstream">upstream</option>
                            </select>
                            <select
                                value={depth}
                                onChange={(e) => setDepth(Number(e.target.value))}
                                className="rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                            >
                                {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} hop{n > 1 ? 's' : ''}</option>)}
                            </select>
                        </>
                    )}
                    <button
                        onClick={() => setOpen(!open)}
                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs"
                    >
                        {open ? 'Hide' : 'Analyse'}
                    </button>
                </div>
            </div>

            {open && (
                <div className="mt-4">
                    {state.loading && <p className="text-xs text-gray-400">Traversing the graph…</p>}
                    {state.error && <p className="text-xs text-[#B3261E]">{state.error}</p>}

                    {state.data && (
                        <>
                            <div className="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4 lg:grid-cols-6">
                                {[
                                    ['Entities reached', summary.nodes ?? 0, null],
                                    ['Applications', summary.applications ?? 0, `${summary.critical_applications ?? 0} critical`],
                                    ['Annual cost', naira(summary.annual_cost_ngn), null],
                                    ['Tight RTO processes', summary.tight_rto_processes ?? 0, '≤ 4h'],
                                    ['Control gaps', summary.control_gaps ?? 0, null],
                                    ['Evidence confidence',
                                        summary.evidence_confidence === null || summary.evidence_confidence === undefined
                                            ? '—' : `${summary.evidence_confidence}%`,
                                        'sealed share'],
                                ].map(([label, value, note]) => (
                                    <div key={label} className="rounded-lg border border-gray-100 bg-[#F7FAFC] p-2">
                                        <div className="text-[10px] uppercase tracking-wide text-gray-500">{label}</div>
                                        <div className="text-lg font-bold text-[#0A1F44]">{value}</div>
                                        {note && <div className="text-[10px] text-gray-400">{note}</div>}
                                    </div>
                                ))}
                            </div>

                            {(state.data.tight_rto_processes || []).length > 0 && (
                                <div className="mb-3 rounded-lg border border-[#E5A100]/40 bg-[#E5A100]/5 px-3 py-2 text-xs text-[#8a6100]">
                                    Needs a change window:{' '}
                                    {state.data.tight_rto_processes.map((p) => `${p.name} (RTO ${p.rto_hours}h)`).join(', ')}
                                </div>
                            )}

                            <div className="max-h-72 overflow-y-auto">
                                <table className="w-full text-xs">
                                    <thead className="sticky top-0 border-b border-gray-100 bg-white text-[11px] text-gray-500">
                                        <tr>
                                            <th className="py-1.5 text-left">Hop</th>
                                            <th className="text-left">Type</th>
                                            <th className="text-left">Name</th>
                                            <th className="text-left">Reached via</th>
                                            <th className="text-left">Criticality</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {nodes.map((node) => (
                                            <tr key={node.key} className="border-b border-gray-50">
                                                <td className="py-1.5 text-gray-400">{node.hop}</td>
                                                <td className="text-gray-600">{node.label_type}</td>
                                                <td className="text-[#0A1F44]">{node.name}</td>
                                                <td className="text-gray-500">{node.via || '—'}</td>
                                                <td>{node.criticality || '—'}</td>
                                            </tr>
                                        ))}
                                        {nodes.length === 0 && (
                                            <tr><td colSpan={5} className="py-4 text-center text-gray-400">
                                                Nothing connects to this record within {depth} hop(s).
                                            </td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <div className="mt-3 text-right">
                                <Link
                                    href={route('ea.impact', { entity_type: entityType, entity_id: entityId, depth, direction })}
                                    className="text-xs text-[#0A1F44] underline"
                                >
                                    Open the full impact view →
                                </Link>
                            </div>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

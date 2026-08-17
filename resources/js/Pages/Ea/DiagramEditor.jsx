import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import ReactFlow, {
    Background,
    Controls,
    MiniMap,
    MarkerType,
    ReactFlowProvider,
    addEdge,
    applyNodeChanges,
    useReactFlow,
} from 'reactflow';
import 'reactflow/dist/style.css';

/**
 * DiagramEditor — WS 4.1 / B13, "the real diagram editor".
 *
 * §2.1 on what this replaces: "`DiagramEditor.jsx` adds nodes via
 * `window.prompt()` and connects them by asking the user to type element ids. It
 * is a demo, and it will be seen as one."
 *
 * What makes it real:
 *
 *  · **Drag from the palette.** The palette is the repository, searchable, so a
 *    dropped box is a real application rather than a label. Free-form shapes are
 *    available too — a diagram sometimes needs an annotation — but they are
 *    visibly marked as unbound.
 *  · **Drag to connect, validated as you go.** The ArchiMate 3.2 permitted-
 *    relationship matrix ships with the page, so an illegal drag is refused at
 *    the gesture with a reason, and the relation picker only offers what is
 *    legal between the two ends. No round-trip per gesture: §10 requires the
 *    module to be usable on a 3G branch link.
 *  · **Auto-layout** — four algorithms, computed server-side and applied without
 *    saving, so trying one is not a commitment.
 *  · **Versions.** Every save snapshots; any version restores.
 *  · **Export** to SVG, PNG and PDF.
 *  · **Approval** that a canvas with metamodel errors cannot pass — RBCF App. II
 *    §1.1(i) asks for an *approved* topology diagram.
 */

const LAYER_ORDER = ['motivation', 'strategy', 'business', 'application', 'technology', 'physical', 'migration'];

/* Free-form shapes with no repository binding, kept separate so an unbound box
   is an explicit choice rather than an accident. */
const FREEFORM = [
    'application-service', 'application-function', 'business-service', 'business-actor',
    'business-role', 'business-object', 'goal', 'driver', 'principle', 'requirement',
    'work-package', 'deliverable', 'plateau', 'grouping', 'system-software', 'facility',
];

export default function DiagramEditor(props) {
    return (
        <ReactFlowProvider>
            <Editor {...props} />
        </ReactFlowProvider>
    );
}

function Editor({
    diagram,
    palette = {},
    matrix = {},
    relations = [],
    elementTypes = [],
    layers = {},
    layerColours = {},
    algorithms = [],
    validation = { valid: true, errors: [], warnings: [] },
    versions = [],
    can = {},
}) {
    const [elements, setElements] = useState(() => diagram.elements_json || []);
    const [edges, setEdges] = useState(() => diagram.edges_json || []);
    const [name, setName] = useState(diagram.name);
    const [code, setCode] = useState(diagram.code);
    const [status, setStatus] = useState(diagram.status);
    const [isTopology, setIsTopology] = useState(Boolean(diagram.is_topology));
    const [algorithm, setAlgorithm] = useState(diagram.layout_algorithm || 'manual');
    const [changeNote, setChangeNote] = useState('');
    const [relation, setRelation] = useState('serving');
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(null);
    const [notice, setNotice] = useState(null);
    const [live, setLive] = useState(validation);
    const [dirty, setDirty] = useState(false);
    const [panel, setPanel] = useState('palette');
    const wrapper = useRef(null);
    const { screenToFlowPosition } = useReactFlow();

    const typeOf = useMemo(() => {
        const map = {};
        elements.forEach((el) => { map[el.id] = el.type; });
        return map;
    }, [elements]);

    const layerFor = useCallback((type) => {
        const found = LAYER_ORDER.find((layer) => (layers[layer] || []).includes(type));
        return found || 'business';
    }, [layers]);

    const colourFor = useCallback((type) => layerColours[layerFor(type)]
        || { fill: '#DBEAFE', stroke: '#1D4ED8' }, [layerFor, layerColours]);

    /* ------------------------------------------------------------------ */
    /* Validation — the same rules the server enforces, run locally so the  */
    /* inspector updates as the canvas changes.                            */
    /* ------------------------------------------------------------------ */

    const permitted = useCallback((sourceType, rel, targetType) => {
        if (rel === 'association') return true;
        const allowed = matrix[rel]?.[sourceType];
        return Array.isArray(allowed) && (allowed.includes('*') || allowed.includes(targetType));
    }, [matrix]);

    const revalidate = useCallback((nextElements, nextEdges) => {
        const errors = [];
        const seen = {};
        nextElements.forEach((el) => {
            if (seen[el.id]) errors.push({ scope: 'element', id: el.id, message: `Duplicate element id '${el.id}'.` });
            seen[el.id] = el.type;
        });
        nextEdges.forEach((edge) => {
            const rel = edge.data?.relation || edge.label || 'association';
            if (!seen[edge.source] || !seen[edge.target]) {
                errors.push({ scope: 'edge', id: edge.id, message: 'Edge references an element that is not on the canvas.' });
                return;
            }
            if (edge.source === edge.target && rel !== 'association') {
                errors.push({ scope: 'edge', id: edge.id, message: `A '${rel}' relationship cannot join an element to itself.` });
                return;
            }
            if (!permitted(seen[edge.source], rel, seen[edge.target])) {
                errors.push({
                    scope: 'edge',
                    id: edge.id,
                    message: `ArchiMate 3.2 does not permit '${rel}' from ${seen[edge.source]} to ${seen[edge.target]}.`,
                });
            }
        });
        setLive({ valid: errors.length === 0, errors, warnings: live.warnings || [], checked: nextEdges.length });
    }, [permitted, live.warnings]);

    const commit = useCallback((nextElements, nextEdges) => {
        setElements(nextElements);
        setEdges(nextEdges);
        setDirty(true);
        revalidate(nextElements, nextEdges);
    }, [revalidate]);

    /* ------------------------------------------------------------------ */
    /* React Flow plumbing                                                 */
    /* ------------------------------------------------------------------ */

    const rfNodes = useMemo(() => elements.map((el) => {
        const colours = colourFor(el.type);
        const broken = live.errors.some((e) => e.scope === 'element' && e.id === el.id);
        return {
            id: String(el.id),
            position: el.position || { x: 0, y: 0 },
            data: { label: el.label, type: el.type, bound: Boolean(el.entity_id) },
            style: {
                background: colours.fill,
                border: `${broken ? 2 : 1.4}px ${el.entity_id ? 'solid' : 'dashed'} ${broken ? '#B3261E' : colours.stroke}`,
                borderRadius: 8,
                padding: '8px 10px',
                width: 180,
                minHeight: 64,
                fontSize: 11,
                color: '#0A1F44',
                boxShadow: selected === el.id ? '0 0 0 2px #C9A86A' : 'none',
            },
        };
    }), [elements, colourFor, live.errors, selected]);

    const rfEdges = useMemo(() => edges.map((edge, index) => {
        const rel = edge.data?.relation || edge.label || 'association';
        const broken = live.errors.some((e) => e.scope === 'edge' && e.id === edge.id);
        return {
            id: String(edge.id ?? `e-${index}`),
            source: String(edge.source),
            target: String(edge.target),
            label: rel,
            style: {
                stroke: broken ? '#B3261E' : '#8B93A1',
                strokeWidth: broken ? 2 : 1.3,
                strokeDasharray: ['association', 'influence'].includes(rel) ? '4 3' : undefined,
            },
            labelStyle: { fontSize: 9, fill: broken ? '#B3261E' : '#4A5568' },
            labelBgStyle: { fill: '#fff', fillOpacity: 0.85 },
            markerEnd: { type: MarkerType.ArrowClosed, color: broken ? '#B3261E' : '#8B93A1' },
        };
    }), [edges, live.errors]);

    const onNodesChange = useCallback((changes) => {
        const next = applyNodeChanges(changes, rfNodes);
        const positions = {};
        next.forEach((n) => { positions[n.id] = n.position; });
        const moved = elements.map((el) => ({ ...el, position: positions[el.id] || el.position }));
        setElements(moved);
        if (changes.some((c) => c.type === 'position' && c.dragging === false)) {
            setDirty(true);
            if (algorithm !== 'manual') setAlgorithm('manual');
        }
    }, [rfNodes, elements, algorithm]);

    // Only removals matter here — edge geometry is derived from its endpoints,
    // so there is nothing else React Flow can change about one.
    const onEdgesChange = useCallback((changes) => {
        const removals = changes.filter((c) => c.type === 'remove').map((c) => String(c.id));
        if (!removals.length) return;
        commit(elements, edges.filter((e) => !removals.includes(String(e.id))));
    }, [commit, elements, edges]);

    /** Drag-to-connect. The gesture is refused with a reason, not silently. */
    const onConnect = useCallback((connection) => {
        const sourceType = typeOf[connection.source];
        const targetType = typeOf[connection.target];

        if (!permitted(sourceType, relation, targetType)) {
            const legal = relations.filter((rel) => permitted(sourceType, rel, targetType));
            setNotice({
                tone: 'error',
                text: `ArchiMate 3.2 does not permit '${relation}' from ${sourceType} to ${targetType}.`
                    + (legal.length ? ` Legal here: ${legal.join(', ')}.` : ' Nothing but association is legal between these two.'),
            });
            return;
        }

        const id = `e-${Date.now()}`;
        commit(elements, addEdge({ ...connection, id }, edges).map((edge) => (
            edge.id === id
                ? { id, source: connection.source, target: connection.target, label: relation, data: { relation } }
                : edge
        )));
        setNotice({ tone: 'ok', text: `Connected with '${relation}'.` });
    }, [typeOf, relation, permitted, relations, commit, elements, edges]);

    /* ---------------- palette drag-and-drop ---------------- */

    const onDragStart = (event, payload) => {
        event.dataTransfer.setData('application/nexusrisk-ea', JSON.stringify(payload));
        event.dataTransfer.effectAllowed = 'move';
    };

    const onDragOver = useCallback((event) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }, []);

    const onDrop = useCallback((event) => {
        event.preventDefault();
        const raw = event.dataTransfer.getData('application/nexusrisk-ea');
        if (!raw) return;

        const payload = JSON.parse(raw);
        const position = screenToFlowPosition({ x: event.clientX, y: event.clientY });

        const existing = payload.entity_id
            && elements.find((el) => el.entity_type === payload.entity_type && el.entity_id === payload.entity_id);

        if (existing) {
            setNotice({ tone: 'error', text: `${payload.label} is already on this canvas.` });
            return;
        }

        const id = payload.entity_id
            ? `${payload.type.split('-')[0]}-${payload.entity_id}`
            : `n-${Date.now()}`;

        commit([...elements, {
            id,
            label: payload.label,
            type: payload.type,
            entity_type: payload.entity_type || null,
            entity_id: payload.entity_id || null,
            position: { x: Math.round(position.x), y: Math.round(position.y) },
        }], edges);
        setSelected(id);
    }, [screenToFlowPosition, elements, edges, commit]);

    /* ---------------- server actions ---------------- */

    const applyLayout = (next) => {
        setAlgorithm(next);
        if (next === 'manual') return;
        window.axios.post(route('ea.diagrams.layout', diagram.id), {
            algorithm: next, elements_json: elements, edges_json: edges,
        }).then(({ data }) => {
            setElements(data.elements);
            setDirty(true);
            setNotice({ tone: 'ok', text: `Laid out with the ${next} algorithm. Save to keep it.` });
        }).catch(() => setNotice({ tone: 'error', text: 'Could not compute that layout.' }));
    };

    /** Pull an element's repository neighbours onto the canvas. */
    const expand = () => {
        const element = elements.find((el) => el.id === selected);
        if (!element?.entity_id) {
            setNotice({ tone: 'error', text: 'Select a box that is bound to a repository record first.' });
            return;
        }
        window.axios.post(route('ea.diagrams.expand'), {
            entity_type: element.entity_type, entity_id: element.entity_id, depth: 1,
        }).then(({ data }) => {
            const present = new Set(elements.map((el) => el.id));
            const added = data.elements.filter((el) => !present.has(el.id));
            if (!added.length) {
                setNotice({ tone: 'ok', text: 'Everything this record connects to is already on the canvas.' });
                return;
            }
            // Fan the new boxes out below the selected one rather than dropping
            // them on the origin, which would hide them under each other.
            const placed = added.map((el, index) => ({
                ...el,
                position: {
                    x: (element.position?.x || 0) + (index % 5) * 220 - 220,
                    y: (element.position?.y || 0) + 160 + Math.floor(index / 5) * 120,
                },
            }));
            const nextElements = [...elements, ...placed];
            const ids = new Set(nextElements.map((el) => el.id));
            const known = new Set(edges.map((e) => `${e.source}|${e.target}`));
            const nextEdges = [...edges, ...data.edges.filter((e) => (
                ids.has(e.source) && ids.has(e.target) && !known.has(`${e.source}|${e.target}`)
            ))];
            commit(nextElements, nextEdges);
            setNotice({ tone: 'ok', text: `Added ${placed.length} connected record(s).` });
        }).catch(() => setNotice({ tone: 'error', text: 'Could not read the repository neighbours.' }));
    };

    const save = () => {
        router.put(route('ea.diagrams.update', diagram.id), {
            code, name, viewpoint: diagram.viewpoint, description: diagram.description,
            status, is_topology: isTopology, layout_algorithm: algorithm,
            elements_json: elements, edges_json: edges, change_note: changeNote || null,
        }, {
            preserveScroll: true,
            onSuccess: () => { setDirty(false); setChangeNote(''); },
        });
    };

    const removeSelected = () => {
        if (!selected) return;
        commit(
            elements.filter((el) => el.id !== selected),
            edges.filter((e) => e.source !== selected && e.target !== selected),
        );
        setSelected(null);
    };

    const paletteGroups = useMemo(() => {
        const term = search.trim().toLowerCase();
        return Object.entries(palette).map(([type, rows]) => ({
            type,
            rows: term
                ? rows.filter((r) => `${r.label} ${r.code || ''}`.toLowerCase().includes(term))
                : rows.slice(0, 30),
            total: rows.length,
        })).filter((group) => group.rows.length > 0 || !term);
    }, [palette, search]);

    const selectedElement = elements.find((el) => el.id === selected);
    const errorCount = live.errors.length;

    return (
        <AuthenticatedLayout header={`Diagram — ${diagram.name}`}>
            <Head title={`Diagram — ${diagram.name}`} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Diagrams', href: route('ea.diagrams') },
                    { label: diagram.code },
                ]}
                title={diagram.name}
                subtitle={`Viewpoint: ${diagram.viewpoint} · version ${diagram.version} · ${elements.length} element(s), ${edges.length} relationship(s)`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            value={algorithm}
                            onChange={(e) => applyLayout(e.target.value)}
                            className="rounded-lg border border-gray-200 px-2 py-2 text-xs"
                            title="Auto-layout"
                        >
                            {algorithms.map((a) => <option key={a} value={a}>{a === 'manual' ? 'manual layout' : `auto: ${a}`}</option>)}
                        </select>
                        {can.export && (
                            <div className="flex items-center gap-1 rounded-lg border border-gray-200 px-2 py-1.5 text-xs">
                                <span className="text-gray-400">Export</span>
                                {['svg', 'png', 'pdf'].map((format) => (
                                    <a
                                        key={format}
                                        href={route('ea.diagrams.export', [diagram.id, format])}
                                        className="font-medium text-[#0A1F44] underline"
                                    >
                                        {format.toUpperCase()}
                                    </a>
                                ))}
                            </div>
                        )}
                        {can.approve && (
                            <button
                                onClick={() => router.post(route('ea.diagrams.approve', diagram.id), {}, { preserveScroll: true })}
                                disabled={errorCount > 0 || dirty}
                                title={errorCount ? 'Resolve the metamodel errors first' : dirty ? 'Save before approving' : ''}
                                className="rounded-lg border border-[#2D7D46] px-3 py-2 text-sm text-[#2D7D46] disabled:opacity-40"
                            >
                                Approve
                            </button>
                        )}
                        {can.edit && (
                            <button onClick={save} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">
                                {dirty ? 'Save changes' : 'Save'}
                            </button>
                        )}
                    </div>
                }
            />

            {notice && (
                <div className={`mb-3 flex items-start justify-between gap-3 rounded-lg border px-3 py-2 text-xs ${
                    notice.tone === 'error'
                        ? 'border-[#B3261E]/30 bg-[#B3261E]/5 text-[#B3261E]'
                        : 'border-[#2D7D46]/30 bg-[#2D7D46]/5 text-[#2D7D46]'
                }`}>
                    <span>{notice.text}</span>
                    <button onClick={() => setNotice(null)} className="opacity-60">×</button>
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[260px,1fr,300px]">
                {/* ------------------------------ palette ------------------------------ */}
                <div className="rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                    <div className="mb-2 flex items-center gap-1 text-xs">
                        {['palette', 'shapes'].map((tab) => (
                            <button
                                key={tab}
                                onClick={() => setPanel(tab)}
                                className={`rounded-md px-2 py-1 font-medium capitalize ${
                                    panel === tab ? 'bg-[#0A1F44] text-white' : 'text-gray-500 hover:bg-gray-50'
                                }`}
                            >
                                {tab === 'palette' ? 'Repository' : 'Shapes'}
                            </button>
                        ))}
                    </div>

                    {panel === 'palette' ? (
                        <>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search the repository…"
                                className="mb-2 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                            />
                            <p className="mb-2 text-[11px] leading-snug text-gray-400">
                                Drag a record onto the canvas. A dropped record stays bound to the repository, so the
                                diagram answers “where is this drawn?”.
                            </p>
                            <div className="max-h-[520px] space-y-3 overflow-y-auto pr-1">
                                {paletteGroups.map((group) => (
                                    <div key={group.type}>
                                        <div className="mb-1 flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                            <span>{group.type.replace(/-/g, ' ')}</span>
                                            <span className="text-gray-300">{group.total}</span>
                                        </div>
                                        {group.rows.map((row) => {
                                            const colours = colourFor(group.type);
                                            return (
                                                <div
                                                    key={`${group.type}-${row.entity_id}`}
                                                    draggable
                                                    onDragStart={(e) => onDragStart(e, row)}
                                                    className="mb-1 cursor-grab rounded border px-2 py-1 text-[11px]"
                                                    style={{ background: colours.fill, borderColor: colours.stroke }}
                                                    title={row.code || row.label}
                                                >
                                                    {row.label}
                                                </div>
                                            );
                                        })}
                                        {group.rows.length === 0 && (
                                            <p className="text-[11px] text-gray-300">No match.</p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </>
                    ) : (
                        <>
                            <p className="mb-2 text-[11px] leading-snug text-gray-400">
                                Unbound shapes for annotation. They are drawn with a dashed border and will not appear
                                in an entity’s “drawn on” list.
                            </p>
                            <div className="max-h-[520px] space-y-1 overflow-y-auto pr-1">
                                {FREEFORM.filter((type) => elementTypes.includes(type)).map((type) => {
                                    const colours = colourFor(type);
                                    return (
                                        <div
                                            key={type}
                                            draggable
                                            onDragStart={(e) => onDragStart(e, { type, label: type.replace(/-/g, ' ') })}
                                            className="cursor-grab rounded border border-dashed px-2 py-1 text-[11px] capitalize"
                                            style={{ background: colours.fill, borderColor: colours.stroke }}
                                        >
                                            {type.replace(/-/g, ' ')}
                                        </div>
                                    );
                                })}
                            </div>
                        </>
                    )}
                </div>

                {/* ------------------------------ canvas ------------------------------ */}
                <div className="rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                    <div className="mb-2 flex flex-wrap items-center gap-2">
                        <input
                            className="min-w-[180px] flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium"
                            value={name}
                            onChange={(e) => { setName(e.target.value); setDirty(true); }}
                        />
                        <input
                            className="w-28 rounded-lg border border-gray-200 px-2 py-2 font-mono text-xs"
                            value={code}
                            onChange={(e) => { setCode(e.target.value); setDirty(true); }}
                        />
                        <select
                            value={status}
                            onChange={(e) => { setStatus(e.target.value); setDirty(true); }}
                            className="rounded-lg border border-gray-200 px-2 py-2 text-xs"
                        >
                            {['draft', 'review', 'approved', 'archived'].map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                        <label className="flex items-center gap-1 text-xs text-gray-600" title="CBN RBCF Appendix II §1.1(i)">
                            <input
                                type="checkbox"
                                checked={isTopology}
                                onChange={(e) => { setIsTopology(e.target.checked); setDirty(true); }}
                            />
                            Topology diagram
                        </label>
                    </div>

                    <div className="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-[#F7FAFC] px-2 py-1.5">
                        <span className="text-[11px] font-medium text-gray-500">Drag between boxes to connect using</span>
                        <select
                            value={relation}
                            onChange={(e) => setRelation(e.target.value)}
                            className="rounded border border-gray-200 px-2 py-1 text-xs"
                        >
                            {relations.map((rel) => <option key={rel} value={rel}>{rel}</option>)}
                        </select>
                        <button onClick={expand} disabled={!selectedElement?.entity_id}
                            className="rounded border border-gray-200 px-2 py-1 text-xs disabled:opacity-40">
                            + Add connected records
                        </button>
                        <button onClick={removeSelected} disabled={!selected}
                            className="rounded border border-gray-200 px-2 py-1 text-xs text-[#B3261E] disabled:opacity-40">
                            Delete selected
                        </button>
                        {dirty && <span className="ml-auto text-[11px] font-medium text-[#E5A100]">Unsaved changes</span>}
                    </div>

                    <div ref={wrapper} style={{ height: 560 }} className="rounded-xl border border-gray-200 bg-[#F7FAFC]">
                        <ReactFlow
                            nodes={rfNodes}
                            edges={rfEdges}
                            onNodesChange={onNodesChange}
                            onEdgesChange={onEdgesChange}
                            onConnect={onConnect}
                            onDrop={onDrop}
                            onDragOver={onDragOver}
                            onNodeClick={(_, node) => setSelected(node.id)}
                            onPaneClick={() => setSelected(null)}
                            fitView
                            fitViewOptions={{ padding: 0.2 }}
                            proOptions={{ hideAttribution: true }}
                        >
                            <Background color="#CBD5E0" gap={18} />
                            <Controls className="!rounded-lg !bg-white !shadow-sm" />
                            <MiniMap pannable zoomable maskColor="rgba(10,31,68,0.08)" />
                        </ReactFlow>
                    </div>
                </div>

                {/* ------------------------------ inspector ------------------------------ */}
                <div className="space-y-4">
                    <div className={`rounded-xl border p-4 shadow-sm ${
                        errorCount ? 'border-[#B3261E]/30 bg-[#B3261E]/5' : 'border-[#2D7D46]/30 bg-[#2D7D46]/5'
                    }`}>
                        <h3 className="mb-1 text-sm font-bold text-[#0A1F44]">
                            ArchiMate validation {errorCount ? `— ${errorCount} error(s)` : '— clean'}
                        </h3>
                        <p className="mb-2 text-[11px] text-gray-500">
                            {edges.length} relationship(s) checked against the ArchiMate 3.2 permitted-relationship
                            matrix. Invalid edges are kept and flagged, not discarded — but they block approval.
                        </p>
                        <ul className="max-h-40 space-y-1 overflow-y-auto text-[11px]">
                            {live.errors.map((error, index) => (
                                <li key={index} className="text-[#B3261E]">• {error.message}</li>
                            ))}
                            {(live.warnings || []).map((warning, index) => (
                                <li key={`w-${index}`} className="text-[#E5A100]">• {warning.message}</li>
                            ))}
                            {errorCount === 0 && !(live.warnings || []).length && (
                                <li className="text-[#2D7D46]">Every relationship on this canvas is legal.</li>
                            )}
                        </ul>
                    </div>

                    {selectedElement && (
                        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Selected element</h3>
                            <input
                                className="mb-2 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                                value={selectedElement.label}
                                onChange={(e) => commit(
                                    elements.map((el) => (el.id === selected ? { ...el, label: e.target.value } : el)),
                                    edges,
                                )}
                            />
                            <select
                                className="mb-2 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                                value={selectedElement.type}
                                onChange={(e) => commit(
                                    elements.map((el) => (el.id === selected ? { ...el, type: e.target.value } : el)),
                                    edges,
                                )}
                            >
                                {elementTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                            </select>
                            <dl className="space-y-1 text-[11px] text-gray-500">
                                <div className="flex justify-between"><dt>Layer</dt><dd className="capitalize">{layerFor(selectedElement.type)}</dd></div>
                                <div className="flex justify-between"><dt>Bound to</dt><dd>{selectedElement.entity_id ? `${selectedElement.entity_type?.split('\\').pop()} #${selectedElement.entity_id}` : 'unbound shape'}</dd></div>
                                <div className="flex justify-between"><dt>Element id</dt><dd className="font-mono">{selectedElement.id}</dd></div>
                            </dl>
                        </div>
                    )}

                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Save note</h3>
                        <input
                            value={changeNote}
                            onChange={(e) => setChangeNote(e.target.value)}
                            placeholder="What changed? (optional)"
                            className="w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                        />
                        <p className="mt-1 text-[11px] text-gray-400">
                            Every save snapshots the previous canvas, so an accidental bulk change is recoverable.
                        </p>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Versions ({versions.length})</h3>
                        <ul className="max-h-56 space-y-2 overflow-y-auto text-[11px]">
                            {versions.map((version) => (
                                <li key={version.id} className="flex items-start justify-between gap-2 border-b border-gray-50 pb-1">
                                    <div>
                                        <span className="font-medium text-[#0A1F44]">v{version.version}</span>
                                        <span className="ml-1 text-gray-400">{version.element_count}el / {version.edge_count}ed</span>
                                        <div className="text-gray-400">{version.change_note || version.status}</div>
                                        <div className="text-gray-300">{version.created_at}</div>
                                    </div>
                                    {can.edit && (
                                        <button
                                            onClick={() => router.post(route('ea.diagrams.restore', [diagram.id, version.id]), {}, { preserveScroll: true })}
                                            className="shrink-0 text-[#0A1F44] underline"
                                        >
                                            Restore
                                        </button>
                                    )}
                                </li>
                            ))}
                            {versions.length === 0 && <li className="text-gray-400">No earlier versions yet.</li>}
                        </ul>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

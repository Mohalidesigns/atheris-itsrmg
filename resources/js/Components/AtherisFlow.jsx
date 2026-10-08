import ReactFlow, { Background, Controls, MiniMap, MarkerType } from 'reactflow';
import 'reactflow/dist/style.css';
import { useMemo } from 'react';

const NODE_COLORS = {
    risk: '#0A1F44',
    control: '#C9A86A',
    threat: '#B3261E',
    vulnerability: '#E5A100',
    asset: '#2D7D46',
    service: '#1D4ED8',
    process: '#718096',
    capability: '#7B5AA6',
    vendor: '#00897B',
    task: '#334155',
    gateway: '#C9A86A',
    start: '#2D7D46',
    end: '#B3261E',
    default: '#2D3748',
};

/**
 * Shared React-Flow canvas with Atheris branding.
 *
 * Props:
 *   nodes: [{ id, label, type, position?: {x,y}, data? }]
 *   edges: [{ id, source, target, label?, animated? }]
 *   layout: 'radial' | 'grid' | 'horizontal' | 'vertical' (default 'radial')
 *   height: number (default 500)
 */
export default function AtherisFlow({ nodes = [], edges = [], layout = 'radial', height = 520, fitView = true, onNodeClick }) {
    const rfNodes = useMemo(() => layoutNodes(nodes, layout).map((n) => ({
        id: String(n.id),
        position: n.position || { x: 0, y: 0 },
        data: { label: n.label || String(n.id), ...(n.data || {}) },
        title: n.title,
        style: {
            background: NODE_COLORS[n.type] || NODE_COLORS.default,
            color: '#fff',
            border: '2px solid rgba(255,255,255,0.2)',
            borderRadius: 12,
            padding: '10px 14px',
            fontSize: 11,
            fontWeight: 600,
            minWidth: 120,
            maxWidth: 220,
            textAlign: 'center',
            cursor: onNodeClick && n.href ? 'pointer' : undefined,
        },
        href: n.href,
    })), [nodes, layout, onNodeClick]);

    const rfEdges = useMemo(() => edges.map((e, idx) => ({
        id: String(e.id ?? `e-${idx}`),
        source: String(e.source),
        target: String(e.target),
        label: e.label,
        animated: Boolean(e.animated),
        style: { stroke: '#C9A86A', strokeWidth: 1.5 },
        labelStyle: { fontSize: 10, fill: '#2D3748' },
        labelBgStyle: { fill: '#fff', fillOpacity: 0.8 },
        markerEnd: { type: MarkerType.ArrowClosed, color: '#C9A86A' },
    })), [edges]);

    return (
        <div style={{ height }} className="rounded-xl border border-gray-200 bg-[#F7FAFC]">
            <ReactFlow
                nodes={rfNodes}
                edges={rfEdges}
                fitView={fitView}
                onNodeClick={onNodeClick ? (_, node) => onNodeClick(node) : undefined}
                fitViewOptions={{ padding: 0.2 }}
                defaultEdgeOptions={{ style: { stroke: '#C9A86A' } }}
                proOptions={{ hideAttribution: true }}
            >
                <Background color="#CBD5E0" gap={18} />
                <Controls className="!bg-white !rounded-lg !shadow-sm" />
                <MiniMap
                    nodeColor={(n) => n.style?.background || NODE_COLORS.default}
                    maskColor="rgba(10, 31, 68, 0.1)"
                    pannable
                    zoomable
                />
            </ReactFlow>
        </div>
    );
}

/* ============================== Layout helpers ============================== */
function layoutNodes(nodes, layout) {
    if (nodes.length === 0) return [];
    const n = nodes.length;

    if (layout === 'horizontal') {
        return nodes.map((node, i) => ({
            ...node,
            position: node.position || { x: i * 200 + 40, y: 240 },
        }));
    }

    if (layout === 'vertical') {
        return nodes.map((node, i) => ({
            ...node,
            position: node.position || { x: 360, y: i * 110 + 40 },
        }));
    }

    if (layout === 'grid') {
        const cols = Math.ceil(Math.sqrt(n));
        return nodes.map((node, i) => ({
            ...node,
            position: node.position || { x: (i % cols) * 220 + 40, y: Math.floor(i / cols) * 140 + 40 },
        }));
    }

    // radial (default) with centre node
    const [centre, ...rest] = nodes;
    const rOuter = 240;
    const cx = 480, cy = 260;
    const laid = [{ ...centre, position: centre.position || { x: cx - 60, y: cy - 25 } }];
    rest.forEach((node, idx) => {
        const angle = (idx / Math.max(rest.length, 1)) * Math.PI * 2;
        laid.push({
            ...node,
            position: node.position || {
                x: cx + rOuter * Math.cos(angle) - 60,
                y: cy + rOuter * Math.sin(angle) - 25,
            },
        });
    });
    return laid;
}

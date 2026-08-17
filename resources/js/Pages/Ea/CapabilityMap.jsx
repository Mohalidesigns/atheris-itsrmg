import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import EaIndexToolbar, { exportCsv } from '@/Components/Ea/EaIndexToolbar';
import EaFormModal from '@/Components/Ea/EaFormModal';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import QualitySealBadge from '@/Components/Ea/QualitySealBadge';
import StewardshipDrawer from '@/Components/Ea/StewardshipDrawer';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link } from '@inertiajs/react';
import {
    PencilSquareIcon,
    PlusIcon,
    RectangleGroupIcon,
    ShieldCheckIcon,
    TrashIcon,
} from '@heroicons/react/24/outline';

/**
 * ATH-EAR-002 §2.1 names this page as "the clearest illustration" of the
 * orphaned write surface: it rendered a recursive capability tree with four KPI
 * cards and had no add, no inline edit, no delete — despite
 * `ea.capabilities.store/update/destroy` all existing and being validated by
 * CapabilityRequest.
 *
 * Drag-to-reparent (Appendix A #5) is deliberately not attempted here: it needs
 * the materialised closure table §10 calls for before it can be correct at
 * 5,000 nodes. Reparenting is available as a field on the edit form instead,
 * which reaches the same endpoint.
 */

const FIELDS = (options, parentLocked) => [
    { name: 'code', label: 'Code', type: 'text', required: true },
    { name: 'name', label: 'Name', type: 'text', required: true },
    { name: 'description', label: 'Description', type: 'textarea', width: 'full' },
    {
        name: 'parent_id',
        label: 'Parent capability',
        type: 'select',
        options: options.parents || [],
        help: parentLocked
            ? 'Pre-filled from the node you clicked. Change it here to reparent.'
            : 'Leave empty for a top-level domain.',
    },
    { name: 'level', label: 'Level (1–5)', type: 'number', min: 1, max: 5 },
    { name: 'criticality', label: 'Criticality', type: 'select', options: ['critical', 'high', 'medium', 'low'] },
    { name: 'maturity', label: 'Maturity (1–5)', type: 'number', min: 1, max: 5 },
    { name: 'owner_role', label: 'Owner role', type: 'text' },
    { name: 'source', label: 'Source', type: 'select', options: [{ value: 'bian', label: 'BIAN reference' }, { value: 'custom', label: 'Tenant-specific' }] },
    { name: 'plateau_id', label: 'Plateau', type: 'select', options: options.plateaux || [] },
    { name: 'last_verified_at', label: 'Last verified', type: 'date' },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'level', label: 'Level' },
    { key: 'parent_id', label: 'Parent ID' },
    { key: 'criticality', label: 'Criticality' },
    { key: 'maturity', label: 'Maturity' },
    { key: 'owner_role', label: 'Owner role' },
    { key: 'source', label: 'Source' },
];

function Node({ capabilities, byParent, perms, onAddChild, onEdit, onDelete, onSteward, overlayMap, seals = {} }) {
    return (
        <ul className="space-y-2">
            {capabilities.map((c) => {
                const children = byParent[c.id] || [];
                return (
                    <li key={c.id} className="border-l-2 border-gray-100 pl-3">
                        <div className="group -mx-2 flex items-center justify-between rounded px-2 py-1 hover:bg-gray-50">
                            <Link href={route('ea.capabilities.show', c.id)} className="min-w-0 flex-1">
                                <span className="font-mono text-xs text-[#0A1F44]">{c.code}</span>
                                <span className="ml-2 text-sm text-[#2D3748]">{c.name}</span>
                                {c.source === 'bian' && (
                                    <span className="ml-2 rounded bg-[#C9A86A]/20 px-1.5 py-0.5 text-[10px] text-[#0A1F44]">
                                        BIAN
                                    </span>
                                )}
                                {overlayMap?.[c.id] !== undefined && (
                                    <span className="ml-2 text-[10px] text-[#718096]">
                                        {String(overlayMap[c.id])}
                                    </span>
                                )}
                            </Link>
                            <div className="flex items-center gap-1">
                                <QualitySealBadge seal={seals[c.id]} compact />
                                <StatusBadge
                                    status={
                                        c.criticality === 'critical'
                                            ? 'critical'
                                            : c.criticality === 'high'
                                              ? 'high'
                                              : c.criticality === 'medium'
                                                ? 'moderate'
                                                : 'low'
                                    }
                                    label={`L${c.level}`}
                                />
                                <div className="flex opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                    {perms.canCreate && (
                                        <button
                                            type="button"
                                            onClick={() => onAddChild(c)}
                                            title="Add child capability"
                                            aria-label={`Add child capability under ${c.name}`}
                                            className="rounded p-1 text-[#718096] hover:bg-gray-100 hover:text-[#0A1F44]"
                                        >
                                            <PlusIcon className="h-4 w-4" />
                                        </button>
                                    )}
                                    {perms.canEdit && (
                                        <button
                                            type="button"
                                            onClick={() => onEdit(c)}
                                            title="Edit"
                                            aria-label={`Edit ${c.name}`}
                                            className="rounded p-1 text-[#718096] hover:bg-gray-100 hover:text-[#0A1F44]"
                                        >
                                            <PencilSquareIcon className="h-4 w-4" />
                                        </button>
                                    )}
                                    {perms.canEdit && (
                                        <button
                                            type="button"
                                            onClick={() => onSteward(c)}
                                            title="Ownership & quality seal"
                                            aria-label={`Ownership and quality seal for ${c.name}`}
                                            className="rounded p-1 text-[#718096] hover:bg-gray-100 hover:text-[#0A1F44]"
                                        >
                                            <ShieldCheckIcon className="h-4 w-4" />
                                        </button>
                                    )}
                                    {perms.canDelete && (
                                        <button
                                            type="button"
                                            onClick={() => onDelete(c, children.length)}
                                            title="Delete"
                                            aria-label={`Delete ${c.name}`}
                                            className="rounded p-1 text-[#718096] hover:bg-[#B3261E]/10 hover:text-[#B3261E]"
                                        >
                                            <TrashIcon className="h-4 w-4" />
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>
                        {children.length > 0 && (
                            <div className="ml-3 mt-1">
                                <Node
                                    capabilities={children}
                                    byParent={byParent}
                                    perms={perms}
                                    onAddChild={onAddChild}
                                    onEdit={onEdit}
                                    onDelete={onDelete}
                                    onSteward={onSteward}
                                    overlayMap={overlayMap}
                                    seals={seals}
                                />
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

export default function CapabilityMap({
    tree = [],
    byParent = {},
    counts = {},
    overlay,
    overlayMap = {},
    options = {},
    seals = {},
    stewardship = {},
}) {
    const perms = useEaPermissions();
    const [steward, setSteward] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [query, setQuery] = useState('');

    const roots = tree.filter((c) => !c.parent_id);

    // Searching a tree flattens it: showing an isolated match inside its
    // hierarchy is more confusing than showing the matches as a list.
    const q = query.trim().toLowerCase();
    const matches = q
        ? tree.filter(
              (c) =>
                  c.code?.toLowerCase().includes(q) ||
                  c.name?.toLowerCase().includes(q) ||
                  c.description?.toLowerCase().includes(q),
          )
        : null;

    const openCreate = (parent = null) => {
        setEditing(
            parent
                ? { parent_id: parent.id, level: Math.min(5, (parent.level || 1) + 1), source: 'custom' }
                : null,
        );
        setShowForm(true);
    };

    const openEdit = (c) => {
        setEditing(c);
        setShowForm(true);
    };

    const askDelete = (c, childCount) => {
        setDeleting({ ...c, _childCount: childCount });
    };

    const blockers = deleting?._childCount
        ? [`${deleting._childCount} child capability(ies) would be orphaned — delete or reparent them first.`]
        : [];

    return (
        <AuthenticatedLayout header="Capability Map">
            <Head title="Capability Map" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Business Architecture' },
                    { label: 'Capability Map' },
                ]}
                title="Business Capability Map"
                subtitle="Hierarchical capability tree (up to 5 levels). BIAN Service Landscape v12 imported as the reference model."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.capabilities')} current="ea.capabilities" />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Total capabilities" value={tree.length} tone="navy" />
                <KpiCard label="BIAN reference" value={counts.bian || 0} tone="gold" />
                <KpiCard label="Tenant-specific" value={counts.custom || 0} tone="white" />
                <KpiCard label="Top-level domains" value={roots.length} tone="white" />
            </div>

            <EaIndexToolbar
                search={{ value: query, onChange: setQuery, placeholder: 'Search the capability tree…' }}
                onCreate={() => openCreate(null)}
                createLabel="New top-level capability"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-capabilities.csv', CSV_COLUMNS, matches || tree)}
                canExport={perms.canExport}
                total={tree.length}
                shown={matches ? matches.length : tree.length}
            />

            {tree.length === 0 ? (
                <EaEmptyState
                    icon={RectangleGroupIcon}
                    title="No capabilities defined"
                    description="The capability model is the spine of the repository — applications, processes and initiatives all hang off it, and the CBN ITSB maturity assessment scores against it. Start with your top-level domains, or import the BIAN reference model."
                    actionLabel="Add the first capability"
                    onAction={() => openCreate(null)}
                    canAct={perms.canCreate}
                    secondaryHref={route('ea.data-sources')}
                />
            ) : matches ? (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <p className="border-b border-gray-100 bg-[#F7FAFC] px-4 py-2 text-xs text-[#718096]">
                        {matches.length} match{matches.length === 1 ? '' : 'es'} — shown as a flat list. Clear the
                        search to return to the tree.
                    </p>
                    <Node
                        capabilities={matches}
                        byParent={{}}
                        perms={perms}
                        onAddChild={openCreate}
                        onEdit={openEdit}
                        onDelete={askDelete}
                        onSteward={setSteward}
                        overlayMap={overlayMap}
                        seals={seals}
                    />
                </div>
            ) : (
                <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                    <Node
                        capabilities={roots}
                        byParent={byParent}
                        perms={perms}
                        onAddChild={openCreate}
                        onEdit={openEdit}
                        onDelete={askDelete}
                        onSteward={setSteward}
                        overlayMap={overlayMap}
                        seals={seals}
                    />
                </div>
            )}

            <EaFormModal
                show={showForm}
                onClose={() => setShowForm(false)}
                record={editing?.id ? editing : editing}
                fields={FIELDS(options, Boolean(editing?.parent_id && !editing?.id))}
                title={editing?.id ? `Edit ${editing.code}` : 'New capability'}
                subtitle="Reparent by changing the parent capability. Level should match depth in the tree."
                storeRoute={() => route('ea.capabilities.store')}
                updateRoute={(r) => route('ea.capabilities.update', r.id)}
            />

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.code}?`}
                body="Applications mapped to this capability will lose the link, and it disappears from every heat-map overlay and maturity rollup."
                blockers={blockers}
                url={deleting?.id ? route('ea.capabilities.destroy', deleting.id) : ''}
            />

            <StewardshipDrawer
                show={Boolean(steward)}
                onClose={() => setSteward(null)}
                entityType={stewardship.entity_type}
                entityId={steward?.id}
                users={stewardship.users || []}
                roles={stewardship.roles || []}
            />
        </AuthenticatedLayout>
    );
}

import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import EaFormModal from '@/Components/Ea/EaFormModal';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import RowActions from '@/Components/Ea/RowActions';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import QualitySealBadge from '@/Components/Ea/QualitySealBadge';
import StewardshipDrawer from '@/Components/Ea/StewardshipDrawer';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link } from '@inertiajs/react';
import { CpuChipIcon, ShieldCheckIcon } from '@heroicons/react/24/outline';

const QUADRANTS = [
    { key: 'adopt', label: 'Adopt', color: '#2D7D46', desc: 'Proven and recommended for broad use.' },
    { key: 'trial', label: 'Trial', color: '#1D4ED8', desc: 'Worth pursuing — try on lower-risk workloads.' },
    { key: 'assess', label: 'Assess', color: '#E5A100', desc: 'Worth exploring to understand impact.' },
    { key: 'hold', label: 'Hold', color: '#B3261E', desc: 'Avoid; existing use should be reduced.' },
];

const FIELDS = (options) => [
    { name: 'code', label: 'Code', type: 'text', required: true },
    { name: 'name', label: 'Name', type: 'text', required: true },
    { name: 'category', label: 'Category', type: 'text', help: 'e.g. Database, Runtime, Middleware, OS.' },
    { name: 'vendor', label: 'Vendor', type: 'text' },
    { name: 'version', label: 'Version', type: 'text' },
    {
        name: 'cpe',
        label: 'CPE',
        type: 'text',
        help: 'Common Platform Enumeration string — the CVE feed matches on this. Without it the component is invisible to vulnerability sync.',
    },
    {
        name: 'radar_status',
        label: 'Radar ring',
        type: 'select',
        options: ['adopt', 'trial', 'assess', 'hold'],
    },
    { name: 'eol_date', label: 'End of life', type: 'date' },
    { name: 'eos_date', label: 'End of support', type: 'date' },
    { name: 'plateau_id', label: 'Plateau', type: 'select', options: options.plateaux || [] },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'category', label: 'Category' },
    { key: 'vendor', label: 'Vendor' },
    { key: 'version', label: 'Version' },
    { key: 'cpe', label: 'CPE' },
    { key: 'radar_status', label: 'Radar' },
    { key: 'eol_date', label: 'EOL' },
    { key: 'eos_date', label: 'EOS' },
    { key: 'tech_debt_score', label: 'Tech debt score' },
];

export default function TechnologyRadar({ components = [], byStatus = {}, options = {}, seals = {}, stewardship = {} }) {
    const perms = useEaPermissions();
    const [steward, setSteward] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const f = useEaFilter(components, {
        searchKeys: ['code', 'name', 'category', 'vendor', 'version', 'cpe'],
        filters: {
            radar_status: {},
            obsolescence: {
                predicate: (row, v) => (v === 'flagged' ? Boolean(row.obsolescence_flag) : !row.obsolescence_flag),
            },
        },
    });

    const openCreate = (ring = null) => {
        setEditing(ring ? { radar_status: ring } : null);
        setShowForm(true);
    };

    return (
        <AuthenticatedLayout header="Technology Radar">
            <Head title="Technology Radar" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Portfolio' },
                    { label: 'Technology Radar' },
                ]}
                title="Technology Radar"
                subtitle="Adopt / Trial / Assess / Hold classification for every technology component. EOL / EOS flags surface obsolescence risk."
                actions={
                    <Link
                        href={route('ea.data-sources')}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        Refresh EOL / CVE feeds
                    </Link>
                }
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.technology-radar')} current="ea.technology-radar" />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                {QUADRANTS.map((q) => (
                    <KpiCard
                        key={q.key}
                        label={q.label}
                        value={(byStatus[q.key] || []).length || 0}
                        tone={
                            q.key === 'adopt' ? 'green' : q.key === 'trial' ? 'navy' : q.key === 'assess' ? 'amber' : 'red'
                        }
                        sublabel={q.desc}
                    />
                ))}
            </div>

            {components.length > 0 && (
                <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
                    {QUADRANTS.map((q) => (
                        <div key={q.key} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <span className="h-3 w-3 rounded-full" style={{ background: q.color }} />
                                    <h3 className="text-sm font-semibold text-[#2D3748]">{q.label}</h3>
                                </div>
                                {perms.canCreate && (
                                    <button
                                        type="button"
                                        onClick={() => openCreate(q.key)}
                                        className="text-xs font-medium text-[#0A1F44] hover:underline"
                                    >
                                        + Add to {q.label}
                                    </button>
                                )}
                            </div>
                            <p className="mt-1 text-xs text-[#718096]">{q.desc}</p>
                            <ul className="mt-3 divide-y divide-gray-100 text-xs">
                                {(byStatus[q.key] || []).slice(0, 15).map((c) => (
                                    <li key={c.id} className="flex items-center justify-between py-1.5">
                                        <span className="text-[#2D3748]">{c.name}</span>
                                        {c.obsolescence_flag && <StatusBadge status="critical" label="EOL <12m" />}
                                    </li>
                                ))}
                                {(byStatus[q.key] || []).length === 0 && (
                                    <li className="py-1.5 text-[#718096]">Nothing in this ring yet.</li>
                                )}
                            </ul>
                        </div>
                    ))}
                </div>
            )}

            <EaIndexToolbar
                search={{
                    value: f.query,
                    onChange: f.setQuery,
                    placeholder: 'Search name, vendor, category or CPE…',
                }}
                filters={[
                    {
                        key: 'radar_status',
                        label: 'All rings',
                        value: f.active.radar_status,
                        onChange: (v) => f.setFilter('radar_status', v),
                        options: ['adopt', 'trial', 'assess', 'hold'],
                    },
                    {
                        key: 'obsolescence',
                        label: 'All lifecycle states',
                        value: f.active.obsolescence,
                        onChange: (v) => f.setFilter('obsolescence', v),
                        options: [
                            { value: 'flagged', label: 'Obsolescence flagged' },
                            { value: 'clear', label: 'Not flagged' },
                        ],
                    },
                ]}
                onCreate={() => openCreate(null)}
                createLabel="New component"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-technology-components.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={components.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={CpuChipIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No technology components recorded"
                    description="The technology catalogue drives obsolescence risk, the CVE match and the CBN quarterly vulnerability-assessment clause. Add components manually, or run the asset sync from Data Sources."
                    actionLabel="Add the first component"
                    onAction={() => openCreate(null)}
                    canAct={perms.canCreate}
                    secondaryHref={route('ea.data-sources')}
                    secondaryLabel="Sync from assets / EOL feed"
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Name</th>
                                <th className="px-3 py-2">Category</th>
                                <th className="px-3 py-2">Vendor</th>
                                <th className="px-3 py-2">Version</th>
                                <th className="px-3 py-2">Radar</th>
                                <th className="px-3 py-2">EOL</th>
                                <th className="px-3 py-2">EOS</th>
                                <th className="px-3 py-2">Confidence</th>
                                <th className="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {f.filtered.map((c) => (
                                <tr key={c.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">
                                        {c.name}
                                        {c.obsolescence_flag && (
                                            <span className="ml-2">
                                                <StatusBadge status="critical" label="EOL <12m" />
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.category}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{c.vendor}</td>
                                    <td className="px-3 py-2 text-xs">{c.version}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={
                                                c.radar_status === 'adopt'
                                                    ? 'pass'
                                                    : c.radar_status === 'hold'
                                                      ? 'fail'
                                                      : 'warn'
                                            }
                                            label={c.radar_status}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-xs">{c.eol_date || '—'}</td>
                                    <td className="px-3 py-2 text-xs">{c.eos_date || '—'}</td>
                                    <td className="px-3 py-2">
                                        <QualitySealBadge seal={seals[c.id]} showCompleteness />
                                    </td>
                                    <td className="px-3 py-2">
                                        <RowActions
                                            canEdit={perms.canEdit}
                                            canDelete={perms.canDelete}
                                            onEdit={() => {
                                                setEditing(c);
                                                setShowForm(true);
                                            }}
                                            onDelete={() => setDeleting(c)}
                                            extra={
                                                <button
                                                    type="button"
                                                    onClick={() => setSteward(c)}
                                                    title="Ownership & quality seal"
                                                    aria-label={`Ownership and quality seal for ${c.name}`}
                                                    className="rounded p-1.5 text-[#718096] hover:bg-gray-100 hover:text-[#0A1F44]"
                                                >
                                                    <ShieldCheckIcon className="h-4 w-4" />
                                                </button>
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <EaFormModal
                show={showForm}
                onClose={() => setShowForm(false)}
                record={editing}
                fields={FIELDS(options)}
                title={editing?.id ? `Edit ${editing.code}` : 'New technology component'}
                subtitle="EOL and EOS dates drive the obsolescence flag; the CPE string is what the CVE feed matches on."
                storeRoute={() => route('ea.tech.store')}
                updateRoute={(r) => route('ea.tech.update', r.id)}
            />

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.name}?`}
                body="The component leaves the radar, the obsolescence calculation and the tech-debt score. The server refuses the delete if applications still run on it or open CVEs are recorded against it."
                url={deleting ? route('ea.tech.destroy', deleting.id) : ''}
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

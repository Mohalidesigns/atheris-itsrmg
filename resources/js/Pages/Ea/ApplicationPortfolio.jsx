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
import { Head, Link, router } from '@inertiajs/react';
import { ShieldCheckIcon, Squares2X2Icon } from '@heroicons/react/24/outline';

const timeTone = { Tolerate: 'draft', Invest: 'pass', Migrate: 'warn', Eliminate: 'fail' };

/**
 * ATH-EAR-002 §2.1: "Application Portfolio Management is the flagship EA use
 * case in every competitor product, and a user of Atheris EA cannot add, edit
 * or delete an application." `ea.applications.store/update/destroy` all existed
 * and were validated by ApplicationRequest; nothing called them. This page now
 * does.
 */
const FIELDS = (options) => [
    { name: 'code', label: 'Code', type: 'text', required: true, help: 'Unique within the tenant, e.g. APP-CORE-01.' },
    { name: 'name', label: 'Name', type: 'text', required: true },
    { name: 'description', label: 'Description', type: 'textarea', width: 'full' },
    {
        name: 'criticality',
        label: 'Criticality',
        type: 'select',
        options: ['critical', 'high', 'medium', 'low'],
    },
    {
        name: 'lifecycle',
        label: 'Lifecycle',
        type: 'select',
        options: ['plan', 'build', 'live', 'sunset', 'retired'],
    },
    {
        name: 'time_score',
        label: 'TIME score',
        type: 'select',
        options: ['Tolerate', 'Invest', 'Migrate', 'Eliminate'],
    },
    {
        name: 'six_r_score',
        label: '6R disposition',
        type: 'select',
        options: ['Rehost', 'Replatform', 'Repurchase', 'Refactor', 'Retire', 'Retain'],
    },
    { name: 'business_fit', label: 'Business fit (1–5)', type: 'number', min: 1, max: 5 },
    { name: 'technical_fit', label: 'Technical fit (1–5)', type: 'number', min: 1, max: 5 },
    { name: 'annual_cost_ngn', label: 'Annual cost (₦)', type: 'number', step: '0.01', min: 0 },
    { name: 'tco_annual_ngn', label: 'Annual TCO (₦)', type: 'number', step: '0.01', min: 0 },
    { name: 'user_count', label: 'User count', type: 'number', min: 0 },
    {
        name: 'owner_role',
        label: 'Owner role',
        type: 'text',
        help: 'The job title accountable for this system. Name the actual person via the ownership panel — that is what surveys and seal approval use.',
    },
    {
        name: 'capability_ids',
        label: 'Supported capabilities',
        type: 'multiselect',
        options: options.capabilities || [],
        help: 'Ctrl/⌘-click to select several. Drives the capability map’s application-coverage overlay.',
    },
    { name: 'plateau_id', label: 'Plateau', type: 'select', options: options.plateaux || [] },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'criticality', label: 'Criticality' },
    { key: 'lifecycle', label: 'Lifecycle' },
    { key: 'time_score', label: 'TIME' },
    { key: 'six_r_score', label: '6R' },
    { key: 'business_fit', label: 'Business fit' },
    { key: 'technical_fit', label: 'Technical fit' },
    { key: 'annual_cost_ngn', label: 'Annual cost (NGN)' },
    { key: 'user_count', label: 'Users' },
    { key: 'owner_role', label: 'Owner role' },
];

export default function ApplicationPortfolio({
    apps = [],
    timeCounts = {},
    grid = [],
    filter,
    options = {},
    seals = {},
    stewardship = {},
}) {
    const perms = useEaPermissions();
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [deleting, setDeleting] = useState(null);
    // WS 1.1 / 1.3 — ownership and the quality seal, per row.
    const [steward, setSteward] = useState(null);

    const sealed = Object.values(seals).filter((s) => s?.state === 'approved').length;
    const needsReview = Object.values(seals).filter((s) => s?.state === 'check_needed').length;

    const f = useEaFilter(apps, {
        searchKeys: ['code', 'name', 'description', 'owner_role'],
        filters: {
            criticality: {},
            lifecycle: {},
            time_score: {},
        },
    });

    const openCreate = () => {
        setEditing(null);
        setShowForm(true);
    };
    const openEdit = (app) => {
        setEditing(app);
        setShowForm(true);
    };

    const cellColor = (bf, tf) => {
        if (bf >= 4 && tf >= 4) return 'bg-[#2D7D46]/60';
        if (bf >= 4 && tf <= 2) return 'bg-[#E5A100]/60';
        if (bf <= 2 && tf >= 4) return 'bg-[#1D4ED8]/50';
        if (bf <= 2 && tf <= 2) return 'bg-[#B3261E]/60';
        return 'bg-gray-200';
    };

    return (
        <AuthenticatedLayout header="Application Portfolio">
            <Head title="Application Portfolio" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Portfolio' }, { label: 'Applications' }]}
                title="Application Portfolio (APM)"
                subtitle="TIME scoring · business fit × technical fit · lifecycle · per-app capability linkage."
                actions={
                    <>
                        <button
                            onClick={() => router.get(route('ea.applications'))}
                            className={`rounded px-2 py-1 text-xs ${!filter ? 'bg-[#0A1F44] text-white' : 'border border-gray-200 bg-white'}`}
                        >
                            All
                        </button>
                        <button
                            onClick={() => router.get(route('ea.applications', { filter: 'critical' }))}
                            className={`rounded px-2 py-1 text-xs ${filter === 'critical' ? 'bg-[#B3261E] text-white' : 'border border-gray-200 bg-white'}`}
                        >
                            Critical
                        </button>
                        <button
                            onClick={() => router.get(route('ea.applications', { filter: 'eliminate' }))}
                            className={`rounded px-2 py-1 text-xs ${filter === 'eliminate' ? 'bg-[#B3261E] text-white' : 'border border-gray-200 bg-white'}`}
                        >
                            Eliminate
                        </button>
                    </>
                }
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.applications')} current="ea.applications" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Applications" value={apps.length} tone="navy" />
                <KpiCard label="Invest" value={timeCounts.Invest || 0} tone="green" />
                <KpiCard label="Migrate" value={timeCounts.Migrate || 0} tone="amber" />
                <KpiCard label="Tolerate" value={timeCounts.Tolerate || 0} tone="white" />
                <KpiCard label="Eliminate" value={timeCounts.Eliminate || 0} tone="red" />
            </div>

            {apps.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-gray-100 bg-white px-4 py-3 text-xs shadow-sm">
                    <span className="font-medium text-[#2D3748]">Data confidence</span>
                    <span className="text-[#2D7D46]">{sealed} approved</span>
                    <span className="text-[#8A6400]">{needsReview} need re-validation</span>
                    <span className="text-[#718096]">{apps.length - Object.keys(seals).length} never sealed</span>
                    <Link href={route('ea.surveys')} className="ml-auto text-[#0A1F44] hover:underline">
                        Ask the owners →
                    </Link>
                </div>
            )}

            {apps.length > 0 && (
                <div className="mb-4 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h3 className="mb-3 text-sm font-semibold text-[#2D3748]">
                        Rationalisation heat grid (business fit × technical fit)
                    </h3>
                    <div className="inline-block">
                        <div className="grid gap-1" style={{ gridTemplateColumns: 'repeat(5, 120px)' }}>
                            {grid.map((cell, i) => (
                                <div
                                    key={i}
                                    className={`relative h-16 rounded-md p-1 text-[10px] ${cellColor(cell.bf, cell.tf)}`}
                                    title={`BF ${cell.bf} × TF ${cell.tf}: ${cell.apps.length} app(s)`}
                                >
                                    <span className="absolute right-1 top-1 font-bold text-[#0A1F44]">
                                        {cell.apps.length}
                                    </span>
                                    <div className="absolute bottom-1 left-1 text-[9px] text-[#0A1F44]/70">
                                        bf{cell.bf}/tf{cell.tf}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="mt-2 text-[10px] text-[#718096]">
                            Technical fit → (columns 1 to 5) · Business fit ↓ (rows 5 to 1)
                        </p>
                    </div>
                </div>
            )}

            <EaIndexToolbar
                search={{
                    value: f.query,
                    onChange: f.setQuery,
                    placeholder: 'Search code, name, description or owner…',
                }}
                filters={[
                    {
                        key: 'criticality',
                        label: 'All criticality',
                        value: f.active.criticality,
                        onChange: (v) => f.setFilter('criticality', v),
                        options: ['critical', 'high', 'medium', 'low'],
                    },
                    {
                        key: 'lifecycle',
                        label: 'All lifecycle',
                        value: f.active.lifecycle,
                        onChange: (v) => f.setFilter('lifecycle', v),
                        options: ['plan', 'build', 'live', 'sunset', 'retired'],
                    },
                    {
                        key: 'time_score',
                        label: 'All TIME',
                        value: f.active.time_score,
                        onChange: (v) => f.setFilter('time_score', v),
                        options: ['Tolerate', 'Invest', 'Migrate', 'Eliminate'],
                    },
                ]}
                onCreate={openCreate}
                createLabel="New application"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-applications.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={apps.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={Squares2X2Icon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No applications in the portfolio yet"
                    description="The application catalogue answers the CBN 'Know Your Environment' clause and drives TIME rationalisation, blast radius and vendor concentration. Add your core banking platform first, then its channels."
                    actionLabel="Add the first application"
                    onAction={openCreate}
                    canAct={perms.canCreate}
                    secondaryHref={route('ea.data-sources')}
                    secondaryLabel="Import from CSV or sync assets"
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Code</th>
                                <th className="px-3 py-2">Name</th>
                                <th className="px-3 py-2">Criticality</th>
                                <th className="px-3 py-2">Lifecycle</th>
                                <th className="px-3 py-2">TIME</th>
                                <th className="px-3 py-2">Fit (B/T)</th>
                                <th className="px-3 py-2">Annual cost</th>
                                <th className="px-3 py-2">Confidence</th>
                                <th className="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {f.filtered.map((a) => (
                                <tr key={a.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 font-mono text-xs">
                                        <Link
                                            href={route('ea.applications.show', a.id)}
                                            className="text-[#0A1F44] hover:underline"
                                        >
                                            {a.code}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 text-[#2D3748]">{a.name}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge
                                            status={
                                                a.criticality === 'critical'
                                                    ? 'critical'
                                                    : a.criticality === 'high'
                                                      ? 'high'
                                                      : 'moderate'
                                            }
                                            label={a.criticality}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-xs capitalize text-[#718096]">{a.lifecycle}</td>
                                    <td className="px-3 py-2">
                                        {a.time_score ? (
                                            <StatusBadge status={timeTone[a.time_score]} label={a.time_score} />
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {a.business_fit || '-'} / {a.technical_fit || '-'}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {a.annual_cost_ngn ? '₦' + Number(a.annual_cost_ngn).toLocaleString() : '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <QualitySealBadge seal={seals[a.id]} showCompleteness />
                                    </td>
                                    <td className="px-3 py-2">
                                        <RowActions
                                            canEdit={perms.canEdit}
                                            canDelete={perms.canDelete}
                                            onEdit={() => openEdit(a)}
                                            onDelete={() => setDeleting(a)}
                                            extra={
                                                <button
                                                    type="button"
                                                    onClick={() => setSteward(a)}
                                                    title="Ownership & quality seal"
                                                    aria-label={`Ownership and quality seal for ${a.name}`}
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
                title={editing ? `Edit ${editing.code}` : 'New application'}
                subtitle="Business and technical fit drive the rationalisation grid; capability links drive the capability-map overlays."
                storeRoute={() => route('ea.applications.store')}
                updateRoute={(r) => route('ea.applications.update', r.id)}
            />

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.code}?`}
                body="The application is removed from the portfolio, the capability overlays and every rationalisation view. Interfaces and zone assignments that reference it must be re-pointed first — the server will refuse the delete otherwise."
                url={deleting ? route('ea.applications.destroy', deleting.id) : ''}
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

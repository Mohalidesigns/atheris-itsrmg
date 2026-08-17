import { Fragment, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import EaFormModal from '@/Components/Ea/EaFormModal';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, router } from '@inertiajs/react';
import { ChevronDownIcon, ChevronRightIcon, MapIcon } from '@heroicons/react/24/outline';

const fmt = (n) => '₦' + Number(n || 0).toLocaleString();
const statusTone = {
    proposed: 'draft',
    approved: 'moderate',
    in_flight: 'warn',
    delivered: 'pass',
    on_hold: 'amber',
    cancelled: 'fail',
};

/**
 * Appendix A #21–23. Three orphaned endpoints land here:
 *   ea.initiatives.dependencies.store  — add a predecessor/successor link
 *   ea.initiatives.deliverables.store  — add an ADM deliverable
 *   ea.deliverables.update             — advance a deliverable's status
 *
 * §5.1 folds the standalone ADM Phase Tracker into the initiative it tracks;
 * the deliverables are therefore expanded inline per initiative rather than
 * living on their own page.
 */

const DELIVERABLE_STATUSES = ['not_started', 'in_progress', 'draft', 'approved'];

const DEPENDENCY_FIELDS = (options) => [
    {
        name: 'predecessor_id',
        label: 'Predecessor',
        type: 'select',
        required: true,
        options: options.initiatives || [],
        help: 'The initiative that must move first.',
    },
    {
        name: 'successor_id',
        label: 'Successor',
        type: 'select',
        required: true,
        options: options.initiatives || [],
    },
    {
        name: 'type',
        label: 'Dependency type',
        type: 'select',
        options: [
            { value: 'finish_to_start', label: 'Finish to start' },
            { value: 'start_to_start', label: 'Start to start' },
            { value: 'finish_to_finish', label: 'Finish to finish' },
            { value: 'start_to_finish', label: 'Start to finish' },
        ],
    },
    { name: 'lag_days', label: 'Lag (days)', type: 'number', min: 0 },
    { name: 'notes', label: 'Notes', type: 'textarea', width: 'full' },
];

const DELIVERABLE_FIELDS = (options) => [
    { name: 'phase', label: 'ADM phase', type: 'select', required: true, options: options.phases || [] },
    { name: 'code', label: 'Code', type: 'text', required: true },
    { name: 'name', label: 'Deliverable', type: 'text', required: true, width: 'full' },
    { name: 'status', label: 'Status', type: 'select', options: DELIVERABLE_STATUSES },
    { name: 'due_date', label: 'Due date', type: 'date' },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'plateau.name', label: 'Plateau' },
    { key: 'status', label: 'Status' },
    { key: 'adm_phase', label: 'ADM phase' },
    { key: 'start_date', label: 'Start' },
    { key: 'target_end_date', label: 'Target end' },
    { key: 'budget_ngn', label: 'Budget (NGN)' },
    { key: 'progress_percent', label: 'Progress %' },
];

export default function Initiatives({ initiatives = [], byStatus = {}, dependencies = [], options = {} }) {
    const perms = useEaPermissions();
    const [expanded, setExpanded] = useState(null);
    const [showDependency, setShowDependency] = useState(false);
    const [deliverableFor, setDeliverableFor] = useState(null);

    const f = useEaFilter(initiatives, {
        searchKeys: ['code', 'name', 'description'],
        filters: { status: {}, adm_phase: {} },
    });

    const advance = (deliverable, status) => {
        router.put(
            route('ea.deliverables.update', deliverable.id),
            { status, due_date: deliverable.due_date },
            { preserveScroll: true },
        );
    };

    const depsFor = (id) => dependencies.filter((d) => d.predecessor_id === id || d.successor_id === id);

    return (
        <AuthenticatedLayout header="Initiatives">
            <Head title="Transformation Initiatives" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Transformation' },
                    { label: 'Initiatives' },
                ]}
                title="Transformation Initiatives"
                subtitle="Portfolio of changes landing within each plateau, with ADM-phase deliverables and sequencing dependencies."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.initiatives')} current="ea.initiatives" />

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-6">
                {['proposed', 'approved', 'in_flight', 'delivered', 'on_hold', 'cancelled'].map((s) => (
                    <KpiCard
                        key={s}
                        label={s.replace('_', ' ')}
                        value={byStatus[s] || 0}
                        tone={
                            s === 'delivered' ? 'green' : s === 'in_flight' ? 'amber' : s === 'cancelled' ? 'red' : 'white'
                        }
                    />
                ))}
            </div>

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search initiatives…' }}
                filters={[
                    {
                        key: 'status',
                        label: 'All statuses',
                        value: f.active.status,
                        onChange: (v) => f.setFilter('status', v),
                        options: ['proposed', 'approved', 'in_flight', 'delivered', 'on_hold', 'cancelled'],
                    },
                    {
                        key: 'adm_phase',
                        label: 'All ADM phases',
                        value: f.active.adm_phase,
                        onChange: (v) => f.setFilter('adm_phase', v),
                        options: options.phases || [],
                    },
                ]}
                onCreate={() => setShowDependency(true)}
                createLabel="Add dependency"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-initiatives.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={initiatives.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={MapIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No transformation initiatives"
                    description="Initiatives are what move the estate from the baseline plateau to the target one, and they are the roadmap half of the CBN RBCF §2.4 current-profile → target-profile → roadmap pattern."
                    canAct={false}
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="w-8 px-3 py-2"></th>
                                <th className="px-3 py-2">Code</th>
                                <th className="px-3 py-2">Name</th>
                                <th className="px-3 py-2">Plateau</th>
                                <th className="px-3 py-2">Start / End</th>
                                <th className="px-3 py-2">Budget</th>
                                <th className="px-3 py-2">Progress</th>
                                <th className="px-3 py-2">ADM</th>
                                <th className="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {f.filtered.map((i) => {
                                const open = expanded === i.id;
                                const deliverables = i.deliverables || [];
                                const deps = depsFor(i.id);
                                return (
                                    <Fragment key={i.id}>
                                        <tr className="hover:bg-gray-50/60">
                                            <td className="px-3 py-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setExpanded(open ? null : i.id)}
                                                    aria-label={open ? 'Collapse' : 'Expand'}
                                                    className="rounded p-1 text-[#718096] hover:bg-gray-100"
                                                >
                                                    {open ? (
                                                        <ChevronDownIcon className="h-4 w-4" />
                                                    ) : (
                                                        <ChevronRightIcon className="h-4 w-4" />
                                                    )}
                                                </button>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{i.code}</td>
                                            <td className="px-3 py-2 text-[#2D3748]">
                                                {i.name}
                                                <span className="ml-2 text-[10px] text-[#718096]">
                                                    {deliverables.length} deliverable
                                                    {deliverables.length === 1 ? '' : 's'}
                                                    {deps.length > 0 && ` · ${deps.length} dependency`}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">{i.plateau?.name || '—'}</td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">
                                                {i.start_date} → {i.target_end_date}
                                            </td>
                                            <td className="px-3 py-2 text-xs">{fmt(i.budget_ngn)}</td>
                                            <td className="px-3 py-2">
                                                <div className="h-2 w-20 overflow-hidden rounded bg-gray-100">
                                                    <div
                                                        className="h-2 rounded bg-[#0A1F44]"
                                                        style={{ width: `${i.progress_percent}%` }}
                                                    />
                                                </div>
                                                <p className="text-[10px] text-[#718096]">{i.progress_percent}%</p>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs">{i.adm_phase}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge
                                                    status={statusTone[i.status] || 'draft'}
                                                    label={i.status.replace('_', ' ')}
                                                />
                                            </td>
                                        </tr>

                                        {open && (
                                            <tr className="bg-[#F7FAFC]">
                                                <td colSpan={9} className="px-6 py-4">
                                                    <div className="mb-2 flex items-center justify-between">
                                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-[#718096]">
                                                            TOGAF ADM deliverables
                                                        </h4>
                                                        {perms.canCreate && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setDeliverableFor(i)}
                                                                className="text-xs font-medium text-[#0A1F44] hover:underline"
                                                            >
                                                                + Add deliverable
                                                            </button>
                                                        )}
                                                    </div>

                                                    {deliverables.length === 0 ? (
                                                        <p className="text-xs text-[#718096]">
                                                            No deliverables recorded for this initiative yet.
                                                        </p>
                                                    ) : (
                                                        <table className="min-w-full text-xs">
                                                            <thead>
                                                                <tr className="text-left uppercase text-[#718096]">
                                                                    <th className="py-1 pr-4">Phase</th>
                                                                    <th className="py-1 pr-4">Code</th>
                                                                    <th className="py-1 pr-4">Deliverable</th>
                                                                    <th className="py-1 pr-4">Due</th>
                                                                    <th className="py-1 pr-4">Status</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                {deliverables.map((d) => (
                                                                    <tr key={d.id} className="border-t border-gray-200">
                                                                        <td className="py-1.5 pr-4 font-mono">{d.phase}</td>
                                                                        <td className="py-1.5 pr-4 font-mono text-[#0A1F44]">
                                                                            {d.code}
                                                                        </td>
                                                                        <td className="py-1.5 pr-4 text-[#2D3748]">{d.name}</td>
                                                                        <td className="py-1.5 pr-4 text-[#718096]">
                                                                            {d.due_date || '—'}
                                                                        </td>
                                                                        <td className="py-1.5 pr-4">
                                                                            {perms.canEdit ? (
                                                                                <select
                                                                                    value={d.status}
                                                                                    onChange={(e) => advance(d, e.target.value)}
                                                                                    className="rounded border-gray-300 py-0.5 text-xs focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                                                                                >
                                                                                    {DELIVERABLE_STATUSES.map((s) => (
                                                                                        <option key={s} value={s}>
                                                                                            {s.replace('_', ' ')}
                                                                                        </option>
                                                                                    ))}
                                                                                </select>
                                                                            ) : (
                                                                                <StatusBadge
                                                                                    status={
                                                                                        d.status === 'approved'
                                                                                            ? 'approved'
                                                                                            : d.status === 'in_progress'
                                                                                              ? 'in_progress'
                                                                                              : 'draft'
                                                                                    }
                                                                                    label={d.status.replace('_', ' ')}
                                                                                />
                                                                            )}
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    )}

                                                    {deps.length > 0 && (
                                                        <>
                                                            <h4 className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-[#718096]">
                                                                Sequencing dependencies
                                                            </h4>
                                                            <ul className="space-y-1 text-xs text-[#2D3748]">
                                                                {deps.map((d) => (
                                                                    <li key={d.id}>
                                                                        <span className="font-mono text-[#0A1F44]">
                                                                            {d.predecessor?.code || d.predecessor_id}
                                                                        </span>{' '}
                                                                        →{' '}
                                                                        <span className="font-mono text-[#0A1F44]">
                                                                            {d.successor?.code || d.successor_id}
                                                                        </span>{' '}
                                                                        <span className="text-[#718096]">
                                                                            ({(d.type || 'finish_to_start').replace(/_/g, ' ')}
                                                                            {d.lag_days ? `, ${d.lag_days}d lag` : ''})
                                                                        </span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            <EaFormModal
                show={showDependency}
                onClose={() => setShowDependency(false)}
                record={null}
                fields={DEPENDENCY_FIELDS(options)}
                title="Add an initiative dependency"
                subtitle="Dependencies drive the roadmap’s sequencing view. Predecessor and successor must differ."
                submitLabel="Add dependency"
                storeRoute={() => route('ea.initiatives.dependencies.store')}
                updateRoute={() => ''}
            />

            <EaFormModal
                show={Boolean(deliverableFor)}
                onClose={() => setDeliverableFor(null)}
                record={null}
                fields={DELIVERABLE_FIELDS(options)}
                title={`Add an ADM deliverable to ${deliverableFor?.code || ''}`}
                subtitle="TOGAF ADM artefacts tracked against the initiative that produces them."
                submitLabel="Add deliverable"
                storeRoute={() =>
                    deliverableFor ? route('ea.initiatives.deliverables.store', deliverableFor.id) : ''
                }
                updateRoute={() => ''}
            />
        </AuthenticatedLayout>
    );
}

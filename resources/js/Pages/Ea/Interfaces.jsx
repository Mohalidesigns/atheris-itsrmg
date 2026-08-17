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
import { Head } from '@inertiajs/react';
import { ArrowsRightLeftIcon, ShieldCheckIcon } from '@heroicons/react/24/outline';

/**
 * The CBN connection catalogue.
 *
 * ATH-EAR-002 Appendix B rates RBCF App. II §1.1(i)–(k) as "the single most
 * EA-tool-shaped clause in Nigerian regulation": a catalogue of all network
 * connections to regulatory authorities, switches and third parties, *with the
 * objective of each connection documented and regularly reviewed*. §5.3 makes
 * the objective field a Phase 0 deliverable on this surface.
 */

const COUNTERPARTY = [
    { value: 'internal', label: 'Internal' },
    { value: 'regulator', label: 'Regulatory authority' },
    { value: 'switch', label: 'Switch / rail' },
    { value: 'third_party', label: 'Third party' },
];

const CADENCE = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'semi_annual', label: 'Semi-annual' },
    { value: 'annual', label: 'Annual' },
];

const FIELDS = (options) => [
    { name: 'code', label: 'Code', type: 'text', required: true },
    { name: 'name', label: 'Name', type: 'text', required: true },
    {
        name: 'objective',
        label: 'Objective of this connection',
        type: 'textarea',
        width: 'full',
        rows: 3,
        help: 'CBN RBCF App. II §1.1(j) requires the objective of every connection to be documented. State what the connection is for, not how it works.',
    },
    { name: 'source_app_id', label: 'Source application', type: 'select', options: options.applications || [] },
    { name: 'target_app_id', label: 'Target application', type: 'select', options: options.applications || [] },
    { name: 'counterparty_type', label: 'Counterparty type', type: 'select', options: COUNTERPARTY },
    { name: 'protocol', label: 'Protocol', type: 'text', help: 'e.g. ISO 8583, REST, SFTP, MQ.' },
    {
        name: 'pattern',
        label: 'Pattern',
        type: 'select',
        options: ['sync', 'async', 'batch', 'file', 'event'],
    },
    { name: 'classification', label: 'Data classification', type: 'text' },
    { name: 'pii_carrying', label: 'Carries personal data', type: 'checkbox', checkboxLabel: 'Yes — NDPA in scope' },
    { name: 'review_cadence', label: 'Review cadence', type: 'select', options: CADENCE, help: 'App. II §1.1(k) — connections must be "regularly reviewed".' },
    { name: 'last_reviewed_at', label: 'Last reviewed', type: 'date' },
    {
        name: 'status',
        label: 'Status',
        type: 'select',
        options: ['proposed', 'active', 'deprecated', 'retired'],
    },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'objective', label: 'Objective (CBN App. II §1.1(j))' },
    { key: 'source_app.name', label: 'Source' },
    { key: 'target_app.name', label: 'Target' },
    { key: 'counterparty_type', label: 'Counterparty type' },
    { key: 'protocol', label: 'Protocol' },
    { key: 'pattern', label: 'Pattern' },
    { key: 'pii_carrying', label: 'PII' },
    { key: 'review_cadence', label: 'Review cadence' },
    { key: 'last_reviewed_at', label: 'Last reviewed' },
    { key: 'status', label: 'Status' },
];

export default function Interfaces({ interfaces = [], cbn = {}, options = {}, seals = {}, stewardship = {} }) {
    const perms = useEaPermissions();
    const [steward, setSteward] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const f = useEaFilter(interfaces, {
        searchKeys: ['code', 'name', 'objective', 'protocol', 'source_app.name', 'target_app.name'],
        filters: {
            counterparty_type: {},
            pattern: {},
            status: {},
            objective_state: {
                predicate: (row, v) =>
                    v === 'missing' ? !row.objective : Boolean(row.objective),
            },
        },
    });

    const objectiveGap = (cbn.total || 0) - (cbn.with_objective || 0);

    return (
        <AuthenticatedLayout header="Interfaces">
            <Head title="Interface Catalogue" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Integration Architecture' },
                    { label: 'Interfaces' },
                ]}
                title="Connection Catalogue"
                subtitle="Every application-to-application and external connection, with the documented objective and review cadence CBN RBCF App. II §1.1(i)–(k) requires."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.interfaces')} current="ea.interfaces" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Connections" value={interfaces.length} tone="navy" />
                <KpiCard
                    label="Objective documented"
                    value={`${cbn.with_objective || 0}/${cbn.total || 0}`}
                    tone={objectiveGap > 0 ? 'amber' : 'green'}
                    sublabel="CBN App. II §1.1(j)"
                />
                <KpiCard label="External counterparties" value={cbn.external || 0} tone="gold" sublabel="Regulator · switch · third party" />
                <KpiCard label="Review overdue" value={cbn.review_overdue || 0} tone={cbn.review_overdue ? 'red' : 'white'} />
                <KpiCard label="PII carrying" value={interfaces.filter((i) => i.pii_carrying).length} tone="white" />
            </div>

            {objectiveGap > 0 && (
                <div className="mb-4 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4">
                    <p className="text-sm font-semibold text-[#8A6400]">
                        {objectiveGap} connection{objectiveGap === 1 ? '' : 's'} have no documented objective
                    </p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        The CBN Risk-Based Cybersecurity Framework requires a catalogue of all network connections to
                        regulatory authorities, switches and third parties with the objective of each connection
                        documented and regularly reviewed. Until every row carries an objective, this register cannot
                        be cited as evidence in a CSAT return.
                    </p>
                    <button
                        type="button"
                        onClick={() => f.setFilter('objective_state', 'missing')}
                        className="mt-3 rounded-lg border border-[#E5A100]/40 bg-white px-3 py-1.5 text-xs font-medium text-[#8A6400] hover:bg-[#E5A100]/10"
                    >
                        Show only the gaps
                    </button>
                </div>
            )}

            <EaIndexToolbar
                search={{
                    value: f.query,
                    onChange: f.setQuery,
                    placeholder: 'Search code, name, objective, protocol or endpoint…',
                }}
                filters={[
                    {
                        key: 'counterparty_type',
                        label: 'All counterparties',
                        value: f.active.counterparty_type,
                        onChange: (v) => f.setFilter('counterparty_type', v),
                        options: COUNTERPARTY,
                    },
                    {
                        key: 'pattern',
                        label: 'All patterns',
                        value: f.active.pattern,
                        onChange: (v) => f.setFilter('pattern', v),
                        options: ['sync', 'async', 'batch', 'file', 'event'],
                    },
                    {
                        key: 'objective_state',
                        label: 'Objective: any',
                        value: f.active.objective_state,
                        onChange: (v) => f.setFilter('objective_state', v),
                        options: [
                            { value: 'documented', label: 'Objective documented' },
                            { value: 'missing', label: 'Objective missing' },
                        ],
                    },
                    {
                        key: 'status',
                        label: 'All statuses',
                        value: f.active.status,
                        onChange: (v) => f.setFilter('status', v),
                        options: ['proposed', 'active', 'deprecated', 'retired'],
                    },
                ]}
                onCreate={() => {
                    setEditing(null);
                    setShowForm(true);
                }}
                createLabel="New connection"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-connection-catalogue.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={interfaces.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={ArrowsRightLeftIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No connections catalogued"
                    description="This register answers a named CBN clause. Start with the connections a supervisor will ask about first: core banking to NIBSS, to your switch, to the BVN service, and to the regulator’s reporting portal."
                    actionLabel="Catalogue the first connection"
                    onAction={() => {
                        setEditing(null);
                        setShowForm(true);
                    }}
                    canAct={perms.canCreate}
                    secondaryHref={route('ea.data-sources')}
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Code</th>
                                    <th className="px-3 py-2">Name</th>
                                    <th className="px-3 py-2">Source → Target</th>
                                    <th className="px-3 py-2">Objective</th>
                                    <th className="px-3 py-2">Counterparty</th>
                                    <th className="px-3 py-2">Reviewed</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2">Confidence</th>
                                    <th className="px-3 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {f.filtered.map((i) => (
                                    <tr key={i.id} className="hover:bg-gray-50/60">
                                        <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{i.code}</td>
                                        <td className="px-3 py-2 text-[#2D3748]">
                                            {i.name}
                                            {i.pii_carrying && (
                                                <span className="ml-2">
                                                    <StatusBadge status="critical" label="PII" />
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-xs">
                                            {i.source_app?.name || '—'} → {i.target_app?.name || '—'}
                                        </td>
                                        <td className="max-w-xs px-3 py-2 text-xs">
                                            {i.objective ? (
                                                <span className="text-[#2D3748]">{i.objective}</span>
                                            ) : (
                                                <StatusBadge status="warn" label="Not documented" />
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-xs capitalize text-[#718096]">
                                            {i.counterparty_type ? i.counterparty_type.replace('_', ' ') : '—'}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">
                                            {i.last_reviewed_at || '—'}
                                            {i.review_cadence && (
                                                <span className="block text-[10px] capitalize">
                                                    {i.review_cadence.replace('_', ' ')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <StatusBadge
                                                status={
                                                    i.status === 'active'
                                                        ? 'active'
                                                        : i.status === 'retired'
                                                          ? 'draft'
                                                          : 'warn'
                                                }
                                                label={i.status}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <QualitySealBadge seal={seals[i.id]} showCompleteness />
                                        </td>
                                        <td className="px-3 py-2">
                                            <RowActions
                                                canEdit={perms.canEdit}
                                                canDelete={perms.canDelete}
                                                onEdit={() => {
                                                    setEditing(i);
                                                    setShowForm(true);
                                                }}
                                                onDelete={() => setDeleting(i)}
                                                extra={
                                                    <button
                                                        type="button"
                                                        onClick={() => setSteward(i)}
                                                        title="Ownership & quality seal"
                                                        aria-label={`Ownership and quality seal for ${i.name}`}
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
                </div>
            )}

            <EaFormModal
                show={showForm}
                onClose={() => setShowForm(false)}
                record={editing}
                fields={FIELDS(options)}
                title={editing?.id ? `Edit ${editing.code}` : 'New connection'}
                subtitle="The objective and review cadence are regulatory fields, not documentation niceties."
                storeRoute={() => route('ea.interfaces.store')}
                updateRoute={(r) => route('ea.interfaces.update', r.id)}
            />

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.code}?`}
                body="The connection leaves the CBN catalogue and the blast-radius graph. If APIs are published over it the server will refuse the delete."
                url={deleting ? route('ea.interfaces.destroy', deleting.id) : ''}
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

import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import EaFormModal from '@/Components/Ea/EaFormModal';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import RowActions from '@/Components/Ea/RowActions';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head } from '@inertiajs/react';
import { ScaleIcon } from '@heroicons/react/24/outline';

/**
 * ATH-EAR-002 §2.1: "Standards.jsx and Principles.jsx are catalogues of
 * governance objects that cannot be governed." `ea.principles.store/update/
 * destroy` existed with no caller. Deletion is a soft retire when exceptions
 * still cite the principle — see EaController::principleDestroy.
 */

const FIELDS = [
    { name: 'code', label: 'Code', type: 'text', required: true, help: 'e.g. PR-07.' },
    { name: 'name', label: 'Name', type: 'text', required: true },
    {
        name: 'statement',
        label: 'Statement',
        type: 'textarea',
        width: 'full',
        help: 'The principle itself, stated as a rule. TOGAF form: a short imperative.',
    },
    { name: 'rationale', label: 'Rationale', type: 'textarea', width: 'full' },
    {
        name: 'implications',
        label: 'Implications',
        type: 'textarea',
        width: 'full',
        help: 'What accepting this principle obliges the organisation to do. The ARB impact engine reads this.',
    },
    {
        name: 'status',
        label: 'Status',
        type: 'select',
        options: [
            { value: 'draft', label: 'Draft' },
            { value: 'active', label: 'Active — published' },
            { value: 'retired', label: 'Retired' },
        ],
    },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'statement', label: 'Statement' },
    { key: 'rationale', label: 'Rationale' },
    { key: 'implications', label: 'Implications' },
    { key: 'status', label: 'Status' },
];

export default function Principles({ principles = [] }) {
    const perms = useEaPermissions();
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const f = useEaFilter(principles, {
        searchKeys: ['code', 'name', 'statement', 'rationale', 'implications'],
        filters: { status: {} },
    });

    const openCreate = () => {
        setEditing(null);
        setShowForm(true);
    };

    return (
        <AuthenticatedLayout header="Architecture Principles">
            <Head title="Architecture Principles" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Architecture Governance' },
                    { label: 'Principles' },
                ]}
                title="Architecture Principles"
                subtitle="TOGAF-21 subset curated for Nigerian banking. Principles are non-negotiable guardrails for ARB decisions."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.principles')} current="ea.principles" />

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search principles…' }}
                filters={[
                    {
                        key: 'status',
                        label: 'All statuses',
                        value: f.active.status,
                        onChange: (v) => f.setFilter('status', v),
                        options: ['draft', 'active', 'retired'],
                    },
                ]}
                onCreate={openCreate}
                createLabel="New principle"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-principles.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={principles.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={ScaleIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No architecture principles published"
                    description="Principles are what the ARB decides against and what an exception is a waiver from — without them the governance loop has no first step. The CBN IT Standards Blueprint and the Risk-Based Cybersecurity Framework are the natural source for a starter set."
                    actionLabel="Draft the first principle"
                    onAction={openCreate}
                    canAct={perms.canCreate}
                    secondaryHref={route('ea.data-sources')}
                />
            ) : (
                <div className="space-y-3">
                    {f.filtered.map((p) => (
                        <div key={p.id} className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-mono text-xs text-[#0A1F44]">{p.code}</p>
                                    <h3 className="text-sm font-semibold text-[#2D3748]">{p.name}</h3>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <StatusBadge
                                        status={p.status === 'active' ? 'active' : p.status === 'retired' ? 'closed' : 'draft'}
                                        label={p.status}
                                    />
                                    <RowActions
                                        canEdit={perms.canEdit}
                                        canDelete={perms.canDelete}
                                        onEdit={() => {
                                            setEditing(p);
                                            setShowForm(true);
                                        }}
                                        onDelete={() => setDeleting(p)}
                                    />
                                </div>
                            </div>
                            <div className="mt-3 grid grid-cols-1 gap-3 text-xs md:grid-cols-3">
                                <div>
                                    <p className="font-semibold uppercase text-[#718096]">Statement</p>
                                    <p className="mt-1 text-[#2D3748]">{p.statement || '—'}</p>
                                </div>
                                <div>
                                    <p className="font-semibold uppercase text-[#718096]">Rationale</p>
                                    <p className="mt-1 text-[#2D3748]">{p.rationale || '—'}</p>
                                </div>
                                <div>
                                    <p className="font-semibold uppercase text-[#718096]">Implications</p>
                                    <p className="mt-1 text-[#2D3748]">{p.implications || '—'}</p>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <EaFormModal
                show={showForm}
                onClose={() => setShowForm(false)}
                record={editing}
                fields={FIELDS}
                title={editing?.id ? `Edit ${editing.code}` : 'New architecture principle'}
                subtitle="Publish by setting the status to Active — draft principles are not enforced by the ARB impact engine."
                storeRoute={() => route('ea.principles.store')}
                updateRoute={(r) => route('ea.principles.update', r.id)}
            />

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.code}?`}
                body="If any exception still cites this principle it is retired rather than deleted, so the governance audit trail stays resolvable."
                confirmLabel="Delete or retire"
                url={deleting ? route('ea.principles.destroy', deleting.id) : ''}
            />
        </AuthenticatedLayout>
    );
}

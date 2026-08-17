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
import { BookOpenIcon } from '@heroicons/react/24/outline';

const FIELDS = [
    { name: 'code', label: 'Code', type: 'text', required: true },
    { name: 'name', label: 'Name', type: 'text', required: true },
    {
        name: 'category',
        label: 'Category',
        type: 'text',
        help: 'Groups the catalogue — e.g. Security, Integration, Data, Infrastructure.',
    },
    { name: 'description', label: 'Description', type: 'textarea', width: 'full' },
    {
        name: 'radar_status',
        label: 'Radar ring',
        type: 'select',
        options: ['adopt', 'trial', 'assess', 'hold'],
    },
    {
        name: 'status',
        label: 'Lifecycle',
        type: 'select',
        options: [
            { value: 'draft', label: 'Draft' },
            { value: 'active', label: 'Active — enforced by the ARB' },
            { value: 'retired', label: 'Retired' },
        ],
    },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'name', label: 'Name' },
    { key: 'category', label: 'Category' },
    { key: 'description', label: 'Description' },
    { key: 'radar_status', label: 'Radar' },
    { key: 'status', label: 'Status' },
];

export default function Standards({ standards = [] }) {
    const perms = useEaPermissions();
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const f = useEaFilter(standards, {
        searchKeys: ['code', 'name', 'category', 'description'],
        filters: { category: {}, radar_status: {}, status: {} },
    });

    const categories = [...new Set(standards.map((s) => s.category).filter(Boolean))].sort();
    const byCategory = f.filtered.reduce((a, s) => {
        (a[s.category || 'General'] ||= []).push(s);
        return a;
    }, {});

    const openCreate = (category = null) => {
        setEditing(category ? { category } : null);
        setShowForm(true);
    };

    return (
        <AuthenticatedLayout header="Standards">
            <Head title="Technology Standards" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Architecture Governance' },
                    { label: 'Standards' },
                ]}
                title="Technology Standards"
                subtitle="Standards catalogue grouped by category — tracked on the radar and enforced by the ARB."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.standards')} current="ea.standards" />

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search standards…' }}
                filters={[
                    {
                        key: 'category',
                        label: 'All categories',
                        value: f.active.category,
                        onChange: (v) => f.setFilter('category', v),
                        options: categories,
                    },
                    {
                        key: 'radar_status',
                        label: 'All rings',
                        value: f.active.radar_status,
                        onChange: (v) => f.setFilter('radar_status', v),
                        options: ['adopt', 'trial', 'assess', 'hold'],
                    },
                    {
                        key: 'status',
                        label: 'All statuses',
                        value: f.active.status,
                        onChange: (v) => f.setFilter('status', v),
                        options: ['draft', 'active', 'retired'],
                    },
                ]}
                onCreate={() => openCreate(null)}
                createLabel="New standard"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-standards.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={standards.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={BookOpenIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No technology standards defined"
                    description="Standards are what an exception waives and what the ARB checks a submission against. The CBN IT Standards Blueprint names COBIT, ISO 38500, ITIL/ISO 20000, ISO 27001/27002, PCI DSS, ISO 22301 and TIA-942 — a reasonable starting catalogue."
                    actionLabel="Add the first standard"
                    onAction={() => openCreate(null)}
                    canAct={perms.canCreate}
                    secondaryHref={route('ea.data-sources')}
                />
            ) : (
                <div className="space-y-3">
                    {Object.entries(byCategory).map(([cat, items]) => (
                        <div key={cat} className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                            <div className="flex items-center justify-between bg-[#0A1F44]/5 px-4 py-3">
                                <h3 className="text-sm font-semibold text-[#0A1F44]">
                                    {cat} · {items.length} standard{items.length === 1 ? '' : 's'}
                                </h3>
                                {perms.canCreate && (
                                    <button
                                        type="button"
                                        onClick={() => openCreate(cat === 'General' ? null : cat)}
                                        className="text-xs font-medium text-[#0A1F44] hover:underline"
                                    >
                                        + Add to {cat}
                                    </button>
                                )}
                            </div>
                            <table className="min-w-full divide-y divide-gray-100 text-sm">
                                <thead className="bg-[#F7FAFC]">
                                    <tr className="text-left text-xs uppercase text-[#718096]">
                                        <th className="px-3 py-2">Code</th>
                                        <th className="px-3 py-2">Name</th>
                                        <th className="px-3 py-2">Radar</th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {items.map((s) => (
                                        <tr key={s.id} className="hover:bg-gray-50/60">
                                            <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{s.code}</td>
                                            <td className="px-3 py-2 text-[#2D3748]">{s.name}</td>
                                            <td className="px-3 py-2">
                                                <StatusBadge
                                                    status={
                                                        s.radar_status === 'adopt'
                                                            ? 'pass'
                                                            : s.radar_status === 'hold'
                                                              ? 'fail'
                                                              : 'warn'
                                                    }
                                                    label={s.radar_status}
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={s.status} />
                                            </td>
                                            <td className="px-3 py-2">
                                                <RowActions
                                                    canEdit={perms.canEdit}
                                                    canDelete={perms.canDelete}
                                                    onEdit={() => {
                                                        setEditing(s);
                                                        setShowForm(true);
                                                    }}
                                                    onDelete={() => setDeleting(s)}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>
            )}

            <EaFormModal
                show={showForm}
                onClose={() => setShowForm(false)}
                record={editing}
                fields={FIELDS}
                title={editing?.id ? `Edit ${editing.code}` : 'New technology standard'}
                subtitle="Moving a standard to Hold does not retire it — retirement is a lifecycle transition, and open exceptions keep the record alive."
                storeRoute={() => route('ea.standards.store')}
                updateRoute={(r) => route('ea.standards.update', r.id)}
            />

            <ConfirmDialog
                show={Boolean(deleting)}
                onClose={() => setDeleting(null)}
                title={`Delete ${deleting?.code}?`}
                body="If any exception still waives this standard it is retired rather than deleted — a waiver is meaningless if the standard it waives disappears."
                confirmLabel="Delete or retire"
                url={deleting ? route('ea.standards.destroy', deleting.id) : ''}
            />
        </AuthenticatedLayout>
    );
}

import { useState } from 'react';
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
import { Head, Link } from '@inertiajs/react';
import { DocumentTextIcon } from '@heroicons/react/24/outline';

/**
 * Architecture Decision Records — WS 1.4 (B4).
 *
 * §4 row 32 scores Atheris 0 and **Orbus 0** on ADRs, against a capability
 * Forrester names as defining a modern EA suite. §5.4: "Cheap to build,
 * immediately demoable, and it is the artefact CBN's governance section asks
 * for."
 */

const FIELDS = (options) => [
    { name: 'title', label: 'Title', type: 'text', required: true, width: 'full' },
    {
        name: 'context',
        label: 'Context',
        type: 'textarea',
        width: 'full',
        rows: 3,
        help: 'What forces were at play? Written so someone joining in two years understands why this was even a question.',
    },
    { name: 'decision', label: 'Decision', type: 'textarea', width: 'full', rows: 3, help: 'State it in the active voice: "We will…".' },
    { name: 'alternatives', label: 'Alternatives considered', type: 'textarea', width: 'full', rows: 2 },
    {
        name: 'consequences',
        label: 'Consequences',
        type: 'textarea',
        width: 'full',
        rows: 3,
        help: 'What becomes easier, and what becomes harder. Both halves — an ADR with only benefits is marketing.',
    },
    { name: 'status', label: 'Status', type: 'select', options: options.statuses || [] },
    { name: 'driver', label: 'Driver', type: 'select', options: options.drivers || [] },
    { name: 'decided_on', label: 'Decided on', type: 'date' },
    { name: 'decided_by', label: 'Decided by', type: 'text' },
    { name: 'arb_submission_id', label: 'ARB submission', type: 'select', options: options.submissions || [] },
    { name: 'initiative_id', label: 'Initiative', type: 'select', options: options.initiatives || [] },
    {
        name: 'supersedes_id',
        label: 'Supersedes',
        type: 'select',
        options: options.records || [],
        help: 'Accepting this record will mark the one it supersedes as superseded.',
    },
    { name: 'impacted_principles', label: 'Impacted principles', type: 'multiselect', options: options.principles || [] },
    { name: 'impacted_standards', label: 'Impacted standards', type: 'multiselect', options: options.standards || [] },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'title', label: 'Title' },
    { key: 'status', label: 'Status' },
    { key: 'driver', label: 'Driver' },
    { key: 'decided_on', label: 'Decided on' },
    { key: 'decided_by', label: 'Decided by' },
    { key: 'context', label: 'Context' },
    { key: 'decision', label: 'Decision' },
    { key: 'consequences', label: 'Consequences' },
];

export default function DecisionRecords({ records = [], byStatus = {}, options = {} }) {
    const perms = useEaPermissions();
    const [showForm, setShowForm] = useState(false);

    const f = useEaFilter(records, {
        searchKeys: ['code', 'title', 'context', 'decision', 'consequences', 'decided_by'],
        filters: { status: {}, driver: {} },
    });

    return (
        <AuthenticatedLayout header="Decision Records">
            <Head title="Architecture Decision Records" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Architecture Governance' },
                    { label: 'Decision Records' },
                ]}
                title="Architecture Decision Records"
                subtitle="Why the architecture is the way it is. A decision is superseded, never rewritten — the reasoning survives."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.decisions')} current="ea.decisions" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Total" value={records.length} tone="navy" />
                <KpiCard label="Accepted" value={byStatus.accepted || 0} tone="green" />
                <KpiCard label="Proposed" value={byStatus.proposed || 0} tone="gold" />
                <KpiCard label="Superseded" value={byStatus.superseded || 0} tone="white" />
                <KpiCard label="Rejected" value={byStatus.rejected || 0} tone="white" />
            </div>

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search title, context or decision…' }}
                filters={[
                    {
                        key: 'status',
                        label: 'All statuses',
                        value: f.active.status,
                        onChange: (v) => f.setFilter('status', v),
                        options: options.statuses || [],
                    },
                    {
                        key: 'driver',
                        label: 'All drivers',
                        value: f.active.driver,
                        onChange: (v) => f.setFilter('driver', v),
                        options: options.drivers || [],
                    },
                ]}
                onCreate={() => setShowForm(true)}
                createLabel="New decision record"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-decision-records.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={records.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={DocumentTextIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No decision records"
                    description="Every ARB verdict so far stops at 'approved' with the reasoning lost in the minutes. An ADR captures the context, the decision and the consequences so the next architect does not re-litigate it — and so an assessor can see the governance actually functioned."
                    actionLabel="Record the first decision"
                    onAction={() => setShowForm(true)}
                    canAct={perms.canCreate}
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Code</th>
                                <th className="px-3 py-2">Title</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Driver</th>
                                <th className="px-3 py-2">Decided</th>
                                <th className="px-3 py-2">Chain</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {f.filtered.map((r) => (
                                <tr key={r.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 font-mono text-xs">
                                        <Link
                                            href={route('ea.decisions.show', r.id)}
                                            className="text-[#0A1F44] hover:underline"
                                        >
                                            {r.code}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 text-[#2D3748]">{r.title}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={r.tone} label={r.status} />
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{r.driver || '—'}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {r.decided_on || '—'}
                                        {r.decided_by && <span className="block text-[10px]">{r.decided_by}</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">
                                        {r.supersedes && <span className="block">supersedes {r.supersedes.code}</span>}
                                        {r.superseded_by && (
                                            <span className="block">superseded by {r.superseded_by.code}</span>
                                        )}
                                        {!r.supersedes && !r.superseded_by && '—'}
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
                record={null}
                fields={FIELDS(options)}
                title="New architecture decision record"
                subtitle="A code is generated automatically. Accepted records become immutable — supersede them rather than editing."
                storeRoute={() => route('ea.decisions.store')}
                updateRoute={() => ''}
            />
        </AuthenticatedLayout>
    );
}

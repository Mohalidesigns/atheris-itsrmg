import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import EaFormModal from '@/Components/Ea/EaFormModal';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head } from '@inertiajs/react';
import { ShieldExclamationIcon } from '@heroicons/react/24/outline';

/**
 * Appendix A #19–20: raise and renew were both orphaned endpoints.
 *
 * `ea.exceptions.store` is a create-only endpoint — there is no update route —
 * so the form is raise-only and renewal is a separate approve-gated action.
 * That split is deliberate in the backend (renewal extends expiry by a year and
 * flips status to `renewed`), and the UI mirrors it rather than pretending a
 * waiver is freely editable.
 */

const FIELDS = (options) => [
    { name: 'subject', label: 'Subject', type: 'text', required: true, width: 'full', help: 'What is being waived, in the reader’s words.' },
    { name: 'standard_id', label: 'Standard waived', type: 'select', options: options.standards || [] },
    { name: 'principle_id', label: 'Principle waived', type: 'select', options: options.principles || [] },
    { name: 'justification', label: 'Justification', type: 'textarea', width: 'full' },
    {
        name: 'compensating_controls',
        label: 'Compensating controls',
        type: 'textarea',
        width: 'full',
        help: 'A waiver without compensating controls is an accepted risk, not an exception — state what mitigates the gap.',
    },
    { name: 'effective_from', label: 'Effective from', type: 'date' },
    { name: 'expires_at', label: 'Expires', type: 'date', help: 'Must be after the effective date. Expiry is enforced by the ExpireExceptions job.' },
    { name: 'risk_band', label: 'Risk band', type: 'select', options: ['low', 'medium', 'high', 'critical'] },
];

const CSV_COLUMNS = [
    { key: 'code', label: 'Code' },
    { key: 'subject', label: 'Subject' },
    { key: 'standard.code', label: 'Standard' },
    { key: 'principle.code', label: 'Principle' },
    { key: 'justification', label: 'Justification' },
    { key: 'compensating_controls', label: 'Compensating controls' },
    { key: 'effective_from', label: 'Effective from' },
    { key: 'expires_at', label: 'Expires' },
    { key: 'risk_band', label: 'Risk band' },
    { key: 'status', label: 'Status' },
];

export default function Exceptions({ exceptions = [], expiringSoon = 0, expired = 0, options = {} }) {
    const perms = useEaPermissions();
    const [showForm, setShowForm] = useState(false);
    const [renewing, setRenewing] = useState(null);

    const f = useEaFilter(exceptions, {
        searchKeys: ['code', 'subject', 'justification', 'compensating_controls'],
        filters: { status: {}, risk_band: {} },
    });

    const active = exceptions.filter((e) => e.status === 'active').length;

    const daysLeft = (e) => {
        if (!e.expires_at) return null;
        const ms = new Date(e.expires_at).getTime() - Date.now();
        return Math.ceil(ms / 86400000);
    };

    return (
        <AuthenticatedLayout header="Exceptions">
            <Head title="EA Exceptions" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Architecture Governance' },
                    { label: 'Exceptions' },
                ]}
                title="Exceptions & Waivers"
                subtitle="Standards waivers with compensating controls, owners and enforced expiry."
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.exceptions')} current="ea.exceptions" />

            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Total" value={exceptions.length} tone="navy" />
                <KpiCard label="Active" value={active} tone="green" />
                <KpiCard label="Expiring ≤90d" value={expiringSoon} tone="amber" />
                <KpiCard label="Expired but still active" value={expired} tone={expired ? 'red' : 'white'} />
            </div>

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search subject, justification or controls…' }}
                filters={[
                    {
                        key: 'status',
                        label: 'All statuses',
                        value: f.active.status,
                        onChange: (v) => f.setFilter('status', v),
                        options: ['active', 'expired', 'revoked', 'renewed'],
                    },
                    {
                        key: 'risk_band',
                        label: 'All risk bands',
                        value: f.active.risk_band,
                        onChange: (v) => f.setFilter('risk_band', v),
                        options: ['low', 'medium', 'high', 'critical'],
                    },
                ]}
                onCreate={() => setShowForm(true)}
                createLabel="Raise exception"
                canCreate={perms.canCreate}
                onExport={() => exportCsv('ea-exceptions.csv', CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={exceptions.length}
                shown={f.filtered.length}
            />

            {f.filtered.length === 0 ? (
                <EaEmptyState
                    icon={ShieldExclamationIcon}
                    filtered={f.isFiltered}
                    onClearFilter={f.reset}
                    title="No exceptions on record"
                    description="An empty exception register usually means waivers are being granted somewhere else — in email, or not at all. Raising them here puts an expiry date and a compensating control against each one."
                    actionLabel="Raise the first exception"
                    onAction={() => setShowForm(true)}
                    canAct={perms.canCreate}
                />
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-100 text-sm">
                            <thead className="bg-[#F7FAFC]">
                                <tr className="text-left text-xs uppercase text-[#718096]">
                                    <th className="px-3 py-2">Code</th>
                                    <th className="px-3 py-2">Subject</th>
                                    <th className="px-3 py-2">Waives</th>
                                    <th className="px-3 py-2">Compensating controls</th>
                                    <th className="px-3 py-2">Expires</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {f.filtered.map((e) => {
                                    const d = daysLeft(e);
                                    return (
                                        <tr key={e.id} className="hover:bg-gray-50/60">
                                            <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{e.code}</td>
                                            <td className="px-3 py-2 text-[#2D3748]">{e.subject}</td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">
                                                {e.standard?.code || e.principle?.code || '—'}
                                            </td>
                                            <td className="max-w-md px-3 py-2 text-xs text-[#718096]">
                                                {e.compensating_controls || (
                                                    <StatusBadge status="warn" label="None stated" />
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <span className={d !== null && d < 0 ? 'text-[#B3261E]' : 'text-[#2D3748]'}>
                                                    {e.expires_at || '—'}
                                                </span>
                                                {d !== null && (
                                                    <span className="block text-[10px] text-[#718096]">
                                                        {d < 0 ? `${Math.abs(d)}d overdue` : `${d}d left`}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge status={e.status} />
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {perms.canApprove && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setRenewing(e)}
                                                        className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50"
                                                    >
                                                        Renew
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <EaFormModal
                show={showForm}
                onClose={() => setShowForm(false)}
                record={null}
                fields={FIELDS(options)}
                title="Raise an exception"
                subtitle="A code is generated automatically. Exceptions are created active and expire on the date you set."
                submitLabel="Raise exception"
                storeRoute={() => route('ea.exceptions.store')}
                updateRoute={() => ''}
            />

            <ConfirmDialog
                show={Boolean(renewing)}
                onClose={() => setRenewing(null)}
                title={`Renew ${renewing?.code}?`}
                body="Renewal extends the expiry by twelve months and marks the exception renewed. This is an approval action — it is recorded in the EA audit log against your name."
                method="post"
                tone="primary"
                confirmLabel="Renew for 12 months"
                url={renewing ? route('ea.exceptions.renew', renewing.id) : ''}
            />
        </AuthenticatedLayout>
    );
}

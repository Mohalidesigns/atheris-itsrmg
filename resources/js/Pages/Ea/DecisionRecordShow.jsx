import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EaFormModal from '@/Components/Ea/EaFormModal';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link } from '@inertiajs/react';

/**
 * A single ADR. The body of an accepted record is immutable server-side —
 * DecisionRecordController::update strips the four narrative fields once the
 * status is accepted — so this page offers supersession rather than editing.
 */

const SECTIONS = [
    ['context', 'Context', 'The forces at play when this was decided.'],
    ['decision', 'Decision', null],
    ['alternatives', 'Alternatives considered', null],
    ['consequences', 'Consequences', 'What became easier, and what became harder.'],
];

export default function DecisionRecordShow({ record = {}, options = {} }) {
    const perms = useEaPermissions();
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const isAccepted = record.status === 'accepted';

    // An accepted record only exposes its status and links for editing; the
    // narrative is fixed. Mirrors the server rule so the UI does not promise
    // something the controller will silently drop.
    const fields = isAccepted
        ? [
              { name: 'title', label: 'Title', type: 'text', required: true, width: 'full' },
              { name: 'status', label: 'Status', type: 'select', options: options.statuses || [] },
              { name: 'driver', label: 'Driver', type: 'select', options: options.drivers || [] },
              { name: 'decided_on', label: 'Decided on', type: 'date' },
              { name: 'decided_by', label: 'Decided by', type: 'text' },
              { name: 'initiative_id', label: 'Initiative', type: 'select', options: options.initiatives || [] },
              { name: 'impacted_principles', label: 'Impacted principles', type: 'multiselect', options: options.principles || [] },
              { name: 'impacted_standards', label: 'Impacted standards', type: 'multiselect', options: options.standards || [] },
          ]
        : [
              { name: 'title', label: 'Title', type: 'text', required: true, width: 'full' },
              { name: 'context', label: 'Context', type: 'textarea', width: 'full', rows: 3 },
              { name: 'decision', label: 'Decision', type: 'textarea', width: 'full', rows: 3 },
              { name: 'alternatives', label: 'Alternatives considered', type: 'textarea', width: 'full', rows: 2 },
              { name: 'consequences', label: 'Consequences', type: 'textarea', width: 'full', rows: 3 },
              { name: 'status', label: 'Status', type: 'select', options: options.statuses || [] },
              { name: 'driver', label: 'Driver', type: 'select', options: options.drivers || [] },
              { name: 'decided_on', label: 'Decided on', type: 'date' },
              { name: 'decided_by', label: 'Decided by', type: 'text' },
              { name: 'supersedes_id', label: 'Supersedes', type: 'select', options: options.records || [] },
              { name: 'arb_submission_id', label: 'ARB submission', type: 'select', options: options.submissions || [] },
              { name: 'initiative_id', label: 'Initiative', type: 'select', options: options.initiatives || [] },
              { name: 'impacted_principles', label: 'Impacted principles', type: 'multiselect', options: options.principles || [] },
              { name: 'impacted_standards', label: 'Impacted standards', type: 'multiselect', options: options.standards || [] },
          ];

    return (
        <AuthenticatedLayout header={record.code}>
            <Head title={`${record.code} — ${record.title}`} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Decision Records', href: route('ea.decisions') },
                    { label: record.code },
                ]}
                title={`${record.code} — ${record.title}`}
                subtitle={[
                    record.driver,
                    record.decided_on ? `decided ${record.decided_on}` : null,
                    record.decided_by,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    <>
                        <StatusBadge status={record.tone} label={record.status} />
                        {perms.canEdit && (
                            <button
                                type="button"
                                onClick={() => setEditing(true)}
                                className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                            >
                                {isAccepted ? 'Edit status & links' : 'Edit'}
                            </button>
                        )}
                        {perms.canDelete && !isAccepted && (
                            <button
                                type="button"
                                onClick={() => setDeleting(true)}
                                className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#B3261E] hover:bg-[#B3261E]/5"
                            >
                                Delete
                            </button>
                        )}
                    </>
                }
            />

            {isAccepted && (
                <p className="mb-4 rounded-xl border border-gray-200 bg-white p-3 text-xs text-[#718096]">
                    This decision has been accepted, so its body is immutable. To change the decision itself, record a
                    new ADR that supersedes this one — an edited decision record is worthless as evidence because a
                    reader cannot tell what was originally decided.
                </p>
            )}

            {record.superseded_by && (
                <div className="mb-4 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4">
                    <p className="text-sm text-[#8A6400]">
                        Superseded by{' '}
                        <Link
                            href={route('ea.decisions.show', record.superseded_by.id)}
                            className="font-semibold underline"
                        >
                            {record.superseded_by.code} — {record.superseded_by.title}
                        </Link>
                    </p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {SECTIONS.map(([key, label, hint]) => (
                        <div key={key} className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="text-sm font-semibold text-[#0A1F44]">{label}</h3>
                            {hint && <p className="mt-0.5 text-[11px] text-[#718096]">{hint}</p>}
                            <p className="mt-2 whitespace-pre-wrap text-sm text-[#2D3748]">
                                {record[key] || <span className="text-[#718096]">Not recorded.</span>}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="space-y-4">
                    {record.lineage?.length > 0 && (
                        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <h3 className="text-sm font-semibold text-[#0A1F44]">Supersession chain</h3>
                            <ol className="mt-3 space-y-2 text-xs">
                                {record.lineage.map((l) => (
                                    <li key={l.id}>
                                        <Link
                                            href={route('ea.decisions.show', l.id)}
                                            className="text-[#0A1F44] hover:underline"
                                        >
                                            {l.code} — {l.title}
                                        </Link>
                                        <span className="ml-2 text-[#718096]">{l.status}</span>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    )}

                    <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">Governance links</h3>
                        <dl className="mt-3 space-y-3 text-xs">
                            <div>
                                <dt className="uppercase text-[#718096]">ARB submission</dt>
                                <dd className="mt-0.5 text-[#2D3748]">
                                    {record.arb ? (
                                        <Link
                                            href={route('ea.arb.show', record.arb.id)}
                                            className="text-[#0A1F44] hover:underline"
                                        >
                                            {record.arb.code} — {record.arb.title}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="uppercase text-[#718096]">Initiative</dt>
                                <dd className="mt-0.5 text-[#2D3748]">
                                    {record.initiative ? `${record.initiative.code} — ${record.initiative.name}` : '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="uppercase text-[#718096]">Principles</dt>
                                <dd className="mt-0.5 text-[#2D3748]">
                                    {record.principles?.length
                                        ? record.principles.map((p) => p.code).join(', ')
                                        : '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="uppercase text-[#718096]">Standards</dt>
                                <dd className="mt-0.5 text-[#2D3748]">
                                    {record.standards?.length ? record.standards.map((s) => s.code).join(', ') : '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="uppercase text-[#718096]">Affects</dt>
                                <dd className="mt-0.5 text-[#2D3748]">
                                    {record.linked_entities?.length
                                        ? record.linked_entities.map((l) => l.label).join(', ')
                                        : '—'}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <EaFormModal
                show={editing}
                onClose={() => setEditing(false)}
                record={record}
                fields={fields}
                title={`Edit ${record.code}`}
                subtitle={isAccepted ? 'The narrative of an accepted decision cannot be changed.' : undefined}
                storeRoute={() => ''}
                updateRoute={(r) => route('ea.decisions.update', r.id)}
            />

            <ConfirmDialog
                show={deleting}
                onClose={() => setDeleting(false)}
                title={`Delete ${record.code}?`}
                body="Only a decision that was never accepted can be deleted. Accepted decisions must be deprecated or superseded so the governance trail stays resolvable."
                url={route('ea.decisions.destroy', record.id)}
            />
        </AuthenticatedLayout>
    );
}

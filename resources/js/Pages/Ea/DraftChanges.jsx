import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * DraftChanges — the human half of WS 4.5's draft-and-approve.
 *
 * An agent or an integration proposes; someone holding `approve ea` decides.
 * The queue shows a field-level diff rather than a payload blob, because an
 * approver who cannot see what changes cannot meaningfully approve it.
 */

const STATUS_TONES = {
    pending: 'bg-[#E5A100]/15 text-[#8a6100]',
    applied: 'bg-[#2D7D46]/10 text-[#2D7D46]',
    rejected: 'bg-gray-100 text-gray-600',
    failed: 'bg-[#B3261E]/10 text-[#B3261E]',
};

const OPERATION_TONES = {
    create: 'text-[#2D7D46]',
    update: 'text-[#1D4ED8]',
    delete: 'text-[#B3261E]',
};

export default function DraftChanges({ queue, can = {} }) {
    const [filter, setFilter] = useState('pending');
    const [expanded, setExpanded] = useState(null);
    const [comment, setComment] = useState('');

    const drafts = useMemo(() => (
        filter === 'all' ? queue.drafts : queue.drafts.filter((d) => d.status === filter)
    ), [queue.drafts, filter]);

    const decide = (draft, action) => {
        router.post(route(`ea.drafts.${action}`, draft.id), {
            comment: action === 'approve' ? comment : undefined,
            reason: action === 'reject' ? comment : undefined,
        }, {
            preserveScroll: true,
            onSuccess: () => { setComment(''); setExpanded(null); },
        });
    };

    return (
        <AuthenticatedLayout header="Change Proposals">
            <Head title="Change Proposals" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Architecture Governance' }, { label: 'Change Proposals' }]}
                title="Change proposals — draft and approve"
                subtitle="Writes arriving through the API or the MCP server land here as proposals. Nothing is applied until someone with approval rights says so, and every draft is revalidated at that moment in case the repository moved."
                actions={
                    <Link href={route('ea.api')} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        API console
                    </Link>
                }
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.decisions')} current="ea.drafts" />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Awaiting decision" value={queue.counts.pending} tone={queue.counts.pending ? 'amber' : 'white'} />
                <KpiCard label="Applied" value={queue.counts.applied} tone="green" />
                <KpiCard label="Rejected" value={queue.counts.rejected} tone="white" />
                <KpiCard
                    label="Failed revalidation"
                    value={queue.counts.failed}
                    tone={queue.counts.failed ? 'red' : 'white'}
                    sublabel="repository moved before approval"
                />
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-1 border-b border-gray-200">
                {['pending', 'applied', 'rejected', 'failed', 'all'].map((key) => (
                    <button
                        key={key}
                        onClick={() => setFilter(key)}
                        className={`-mb-px border-b-2 px-3 py-2 text-xs font-medium capitalize ${
                            filter === key ? 'border-[#C9A86A] text-[#0A1F44]' : 'border-transparent text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {key}
                    </button>
                ))}
            </div>

            <div className="space-y-3">
                {drafts.map((draft) => (
                    <div key={draft.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-xs text-gray-400">{draft.reference}</span>
                                    <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${STATUS_TONES[draft.status]}`}>
                                        {draft.status}
                                    </span>
                                    <span className={`text-xs font-medium uppercase ${OPERATION_TONES[draft.operation]}`}>
                                        {draft.operation}
                                    </span>
                                    <span className="text-sm text-[#0A1F44]">
                                        {draft.entity_label}{draft.entity_name ? ` — ${draft.entity_name}` : ''}
                                    </span>
                                </div>
                                <div className="mt-1 text-[11px] text-gray-500">
                                    proposed by <span className="font-medium">{draft.proposed_by}</span> via {draft.origin} · {draft.created_at}
                                    {draft.decided_at && ` · decided ${draft.decided_at}`}
                                </div>
                                {draft.reason && (
                                    <p className="mt-1 max-w-3xl text-xs text-gray-600">{draft.reason}</p>
                                )}
                            </div>

                            {draft.status === 'pending' && can.approve && (
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={() => setExpanded(expanded === draft.id ? null : draft.id)}
                                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs"
                                    >
                                        {expanded === draft.id ? 'Close' : 'Decide'}
                                    </button>
                                </div>
                            )}
                        </div>

                        {(draft.diff || []).length > 0 && (
                            <div className="mt-3 overflow-hidden rounded-lg border border-gray-100">
                                <table className="w-full text-xs">
                                    <thead className="bg-[#F7FAFC] text-[11px] text-gray-500">
                                        <tr>
                                            <th className="px-2 py-1.5 text-left">Field</th>
                                            <th className="px-2 text-left">Currently</th>
                                            <th className="px-2 text-left">Proposed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {draft.diff.map((row) => (
                                            <tr key={row.field} className="border-t border-gray-50">
                                                <td className="px-2 py-1.5 font-mono text-[11px] text-gray-600">{row.field}</td>
                                                <td className="px-2 text-gray-400">
                                                    {row.before === null || row.before === undefined ? '—' : String(row.before)}
                                                </td>
                                                <td className="px-2 font-medium text-[#0A1F44]">
                                                    {row.after === null || row.after === undefined ? '—' : String(row.after)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {(draft.validation?.problems || []).length > 0 && (
                            <ul className="mt-2 space-y-0.5 text-[11px] text-[#B3261E]">
                                {draft.validation.problems.map((problem, index) => <li key={index}>• {problem}</li>)}
                            </ul>
                        )}
                        {(draft.validation?.notes || []).length > 0 && (
                            <ul className="mt-2 space-y-0.5 text-[11px] text-[#8a6100]">
                                {draft.validation.notes.map((note, index) => <li key={index}>• {note}</li>)}
                            </ul>
                        )}

                        {expanded === draft.id && (
                            <div className="mt-3 rounded-lg border border-gray-100 bg-[#F7FAFC] p-3">
                                <textarea
                                    value={comment}
                                    onChange={(e) => setComment(e.target.value)}
                                    rows={2}
                                    placeholder="Note for the audit trail — why this was approved or rejected."
                                    className="mb-2 w-full rounded-lg border border-gray-200 px-2 py-1.5 text-xs"
                                />
                                <div className="flex items-center justify-end gap-2">
                                    <button
                                        onClick={() => decide(draft, 'reject')}
                                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs"
                                    >
                                        Reject
                                    </button>
                                    <button
                                        onClick={() => decide(draft, 'approve')}
                                        disabled={draft.validation && draft.validation.ok === false}
                                        title={draft.validation?.ok === false ? 'This draft cannot be applied as it stands.' : ''}
                                        className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white disabled:opacity-40"
                                    >
                                        Approve and apply
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                ))}

                {drafts.length === 0 && (
                    <div className="rounded-xl border border-gray-100 bg-white p-8 text-center text-sm text-gray-400 shadow-sm">
                        Nothing {filter === 'all' ? 'in the queue' : `with status “${filter}”`}.
                    </div>
                )}
            </div>

            <div className="mt-5 rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <h3 className="mb-2 text-sm font-bold text-[#0A1F44]">Writable scope</h3>
                <p className="mb-3 text-[11px] text-gray-500">
                    The only attributes an automated caller can propose. Anything outside this list is refused when the
                    proposal is made, not when it is approved.
                </p>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    {Object.entries(queue.scope || {}).map(([type, attributes]) => (
                        <div key={type} className="rounded-lg border border-gray-100 p-3">
                            <h4 className="mb-1 text-xs font-bold text-[#0A1F44]">{type}</h4>
                            <div className="flex flex-wrap gap-1">
                                {attributes.map((attribute) => (
                                    <span key={attribute} className="rounded bg-gray-50 px-1.5 py-0.5 font-mono text-[10px] text-gray-600">
                                        {attribute}
                                    </span>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

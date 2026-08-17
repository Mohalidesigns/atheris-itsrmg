import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaIndexToolbar, { useEaFilter, exportCsv } from '@/Components/Ea/EaIndexToolbar';
import ConfirmDialog from '@/Components/Ea/ConfirmDialog';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Campaign dashboard — the completion tracking WS 1.2 asks for, and R5's
 * mitigation: "Ship completion analytics from day one … make the CISO's return
 * dependency visible ('your return is 62% evidenced')."
 */

const STATE_TONE = {
    pending: 'draft',
    opened: 'in_progress',
    submitted: 'pass',
    declined: 'warn',
    expired: 'closed',
};

const CSV_COLUMNS = [
    { key: 'entity', label: 'Record' },
    { key: 'email', label: 'Recipient' },
    { key: 'name', label: 'Name' },
    { key: 'role', label: 'Role' },
    { key: 'state', label: 'State' },
    { key: 'reminders_sent', label: 'Reminders' },
    { key: 'submitted_at', label: 'Submitted' },
    { key: 'comment', label: 'Comment' },
];

export default function SurveyCampaign({ campaign = {}, survey = {}, stats = {}, responses = [] }) {
    const perms = useEaPermissions();
    const [closing, setClosing] = useState(false);

    const f = useEaFilter(responses, {
        searchKeys: ['entity', 'email', 'name', 'comment'],
        filters: {
            state: {},
            licensed: { predicate: (r, v) => (v === 'yes' ? r.is_licensed : !r.is_licensed) },
        },
    });

    const outstanding = stats.pending + stats.opened;
    const nonLicensed = responses.filter((r) => !r.is_licensed).length;

    return (
        <AuthenticatedLayout header="Campaign">
            <Head title={`Campaign ${campaign.code}`} />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Surveys', href: route('ea.surveys') },
                    { label: campaign.code },
                ]}
                title={survey.name || campaign.code}
                subtitle={`${campaign.entities_count} ${survey.entity_label || 'record'}(s) · launched by ${campaign.launched_by || 'system'} · window ${campaign.opens_at?.slice(0, 10)} → ${campaign.closes_at?.slice(0, 10) || '—'}`}
                actions={
                    <>
                        {perms.canEdit && campaign.state === 'running' && outstanding > 0 && (
                            <button
                                type="button"
                                onClick={() => router.post(route('ea.surveys.remind', campaign.id), {}, { preserveScroll: true })}
                                className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                            >
                                Remind {outstanding} non-respondent(s)
                            </button>
                        )}
                        {perms.canEdit && campaign.state === 'running' && (
                            <button
                                type="button"
                                onClick={() => setClosing(true)}
                                className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                            >
                                Close campaign
                            </button>
                        )}
                    </>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard
                    label="Completion"
                    value={`${stats.completion_rate || 0}%`}
                    tone={stats.completion_rate >= 70 ? 'green' : stats.completion_rate >= 30 ? 'amber' : 'red'}
                    sublabel={`${stats.submitted || 0} of ${stats.total || 0}`}
                />
                <KpiCard label="Not started" value={stats.pending || 0} tone="white" />
                <KpiCard label="Opened, not sent" value={stats.opened || 0} tone="white" />
                <KpiCard label="Declined" value={stats.declined || 0} tone={stats.declined ? 'amber' : 'white'} />
                <KpiCard
                    label={campaign.state === 'running' ? 'Days remaining' : 'Campaign'}
                    value={campaign.state === 'running' ? (stats.days_remaining ?? '—') : 'Closed'}
                    tone={campaign.state === 'running' ? 'gold' : 'white'}
                />
            </div>

            {nonLicensed > 0 && (
                <p className="mb-4 rounded-xl border border-gray-200 bg-white p-3 text-xs text-[#718096]">
                    <span className="font-medium text-[#2D3748]">{nonLicensed}</span> of {responses.length}{' '}
                    recipients hold no account on this platform — they are answering by magic link. LeanIX cannot
                    survey anyone without a licence, which is the ceiling this design is built to clear.
                </p>
            )}

            <EaIndexToolbar
                search={{ value: f.query, onChange: f.setQuery, placeholder: 'Search recipient or record…' }}
                filters={[
                    {
                        key: 'state',
                        label: 'All states',
                        value: f.active.state,
                        onChange: (v) => f.setFilter('state', v),
                        options: ['pending', 'opened', 'submitted', 'declined', 'expired'],
                    },
                    {
                        key: 'licensed',
                        label: 'All recipients',
                        value: f.active.licensed,
                        onChange: (v) => f.setFilter('licensed', v),
                        options: [
                            { value: 'yes', label: 'Platform users' },
                            { value: 'no', label: 'Magic-link only' },
                        ],
                    },
                ]}
                onExport={() => exportCsv(`campaign-${campaign.code}.csv`, CSV_COLUMNS, f.filtered)}
                canExport={perms.canExport}
                total={responses.length}
                shown={f.filtered.length}
            />

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Record</th>
                                <th className="px-3 py-2">Recipient</th>
                                <th className="px-3 py-2">Role</th>
                                <th className="px-3 py-2">State</th>
                                <th className="px-3 py-2">Reminders</th>
                                <th className="px-3 py-2">Answers</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {f.filtered.map((r) => (
                                <tr key={r.id} className="hover:bg-gray-50/60">
                                    <td className="px-3 py-2 text-[#2D3748]">{r.entity}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {r.name || r.email}
                                        {!r.is_licensed && (
                                            <span className="ml-1 text-[10px] text-[#718096]">(magic link)</span>
                                        )}
                                        {r.name && <span className="block text-[10px] text-[#718096]">{r.email}</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs capitalize text-[#718096]">{r.role || '—'}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={STATE_TONE[r.state] || 'draft'} label={r.state} />
                                        {r.submitted_at && (
                                            <span className="block text-[10px] text-[#718096]">
                                                {r.submitted_at.slice(0, 10)}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs">{r.reminders_sent}</td>
                                    <td className="max-w-md px-3 py-2 text-xs text-[#718096]">
                                        {r.answers ? (
                                            <span>
                                                {Object.entries(r.answers)
                                                    .map(([k, v]) => `${k}: ${v}`)
                                                    .join(' · ')}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                        {r.comment && <span className="block italic">“{r.comment}”</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <ConfirmDialog
                show={closing}
                onClose={() => setClosing(false)}
                title={`Close campaign ${campaign.code}?`}
                body="Outstanding magic links stop working immediately. Responses already submitted keep the data they wrote."
                method="post"
                tone="primary"
                confirmLabel="Close campaign"
                url={route('ea.surveys.close', campaign.id)}
            />
        </AuthenticatedLayout>
    );
}

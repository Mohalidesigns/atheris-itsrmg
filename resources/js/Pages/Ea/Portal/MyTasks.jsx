import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import { Head, Link } from '@inertiajs/react';
import { InboxIcon } from '@heroicons/react/24/outline';

/**
 * My Tasks — the in-product queue, WS 1.6.
 *
 * Ardoq delivers broadcasts to email *and* to a My Tasks queue in Discover.
 * A recipient who is already logged in should not have to go hunting through
 * their inbox for a link.
 */

const STATE_TONE = {
    submitted: 'pass',
    declined: 'warn',
    expired: 'closed',
};

export default function MyTasks({ open = [], done = [] }) {
    const overdueSoon = open.filter((t) => t.days_remaining !== null && t.days_remaining <= 3).length;

    return (
        <AuthenticatedLayout header="My Tasks">
            <Head title="My Tasks" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'My Tasks' }]}
                title="My Tasks"
                subtitle="Architecture records you have been asked to confirm."
                actions={
                    <Link
                        href={route('ea.stewardship.my-architecture')}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        What I own
                    </Link>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3">
                <KpiCard label="Open tasks" value={open.length} tone={open.length ? 'gold' : 'white'} />
                <KpiCard label="Closing within 3 days" value={overdueSoon} tone={overdueSoon ? 'red' : 'white'} />
                <KpiCard label="Completed" value={done.filter((d) => d.state === 'submitted').length} tone="green" />
            </div>

            {open.length === 0 ? (
                <EaEmptyState
                    icon={InboxIcon}
                    title="Nothing is waiting on you"
                    description="When an architect launches a survey covering a record you own, it appears here and in your inbox."
                    canAct={false}
                />
            ) : (
                <div className="mb-6 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Waiting on you
                    </h3>
                    <ul className="divide-y divide-gray-100">
                        {open.map((t) => (
                            <li key={t.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="text-sm text-[#2D3748]">{t.entity}</p>
                                    <p className="text-[11px] text-[#718096]">
                                        {t.type_label} · {t.survey}
                                        {t.closes_at ? ` · closes ${t.closes_at}` : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    {t.days_remaining !== null && t.days_remaining !== undefined && (
                                        <span
                                            className={`text-xs ${t.days_remaining <= 3 ? 'font-medium text-[#B3261E]' : 'text-[#718096]'}`}
                                        >
                                            {t.days_remaining > 0 ? `${t.days_remaining}d left` : 'closes today'}
                                        </span>
                                    )}
                                    <a
                                        href={t.href}
                                        className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                                    >
                                        Respond
                                    </a>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {done.length > 0 && (
                <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                        Recently closed
                    </h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Record</th>
                                <th className="px-3 py-2">Survey</th>
                                <th className="px-3 py-2">Outcome</th>
                                <th className="px-3 py-2">When</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {done.map((t) => (
                                <tr key={t.id}>
                                    <td className="px-3 py-2 text-[#2D3748]">{t.entity}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{t.survey}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge status={STATE_TONE[t.state] || 'draft'} label={t.state} />
                                    </td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{t.submitted_at || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

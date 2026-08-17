import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import QualitySealBadge from '@/Components/Ea/QualitySealBadge';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import { Head, Link } from '@inertiajs/react';
import { UserCircleIcon } from '@heroicons/react/24/outline';

/**
 * My Architecture — WS 1.1's "'My architecture' view".
 *
 * The repository only stays true if maintaining it is somebody's visible,
 * bounded job. This is that job, expressed as a list: what you own, which of it
 * is stale, and what has been asked of you.
 */
export default function MyArchitecture({ items = [], tasks = [], needsAttention = [], summary = {} }) {
    const byType = items.reduce((acc, i) => {
        (acc[i.type_label] ||= []).push(i);
        return acc;
    }, {});

    return (
        <AuthenticatedLayout header="My Architecture">
            <Head title="My Architecture" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'My Architecture' }]}
                title="My Architecture"
                subtitle="Everything you are Responsible or Accountable for, and everything waiting on you."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard label="Records you own" value={summary.owned || 0} tone="navy" />
                <KpiCard label="You can approve" value={summary.approver_of || 0} tone="gold" />
                <KpiCard
                    label="Need your attention"
                    value={summary.needs_attention || 0}
                    tone={summary.needs_attention ? 'amber' : 'white'}
                />
                <KpiCard
                    label="Open survey tasks"
                    value={summary.open_tasks || 0}
                    tone={summary.open_tasks ? 'red' : 'white'}
                />
            </div>

            {tasks.length > 0 && (
                <div className="mb-5 rounded-xl border border-[#C9A86A]/40 bg-[#C9A86A]/5 p-4">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Waiting on you</h3>
                    <ul className="mt-3 divide-y divide-[#C9A86A]/20">
                        {tasks.map((t) => (
                            <li key={t.id} className="flex items-center justify-between py-2">
                                <div className="min-w-0">
                                    <p className="text-sm text-[#2D3748]">{t.label}</p>
                                    <p className="text-[11px] text-[#718096]">
                                        {t.survey}
                                        {t.closes_at ? ` · closes ${t.closes_at}` : ''}
                                    </p>
                                </div>
                                <a
                                    href={t.href}
                                    className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                                >
                                    Respond
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {needsAttention.length > 0 && (
                <div className="mb-5 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4">
                    <h3 className="text-sm font-semibold text-[#8A6400]">
                        {needsAttention.length} record{needsAttention.length === 1 ? '' : 's'} you own
                        {needsAttention.length === 1 ? ' needs' : ' need'} re-validating
                    </h3>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        A seal in Check Needed means the record changed after approval, or its renewal interval
                        elapsed. Until it is approved again, anything citing it — including a CBN return — carries a
                        lower evidence confidence.
                    </p>
                    <ul className="mt-3 space-y-1 text-xs">
                        {needsAttention.slice(0, 8).map((i) => (
                            <li key={`${i.entity_type}-${i.entity_id}`} className="flex items-center gap-2">
                                <QualitySealBadge seal={i.seal} compact />
                                {i.href ? (
                                    <Link href={i.href} className="text-[#0A1F44] hover:underline">
                                        {i.label}
                                    </Link>
                                ) : (
                                    <span className="text-[#2D3748]">{i.label}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {items.length === 0 ? (
                <EaEmptyState
                    icon={UserCircleIcon}
                    title="You do not own any architecture records yet"
                    description="Ownership is a relationship, not a text field. An architect assigns it from any catalogue page — open a record’s Ownership panel and add yourself as Responsible or Accountable."
                    canAct={false}
                />
            ) : (
                <div className="space-y-4">
                    {Object.entries(byType).map(([type, rows]) => (
                        <div key={type} className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                            <h3 className="border-b border-gray-100 bg-[#0A1F44]/5 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                                {type} · {rows.length}
                            </h3>
                            <table className="min-w-full divide-y divide-gray-100 text-sm">
                                <thead className="bg-[#F7FAFC]">
                                    <tr className="text-left text-xs uppercase text-[#718096]">
                                        <th className="px-3 py-2">Record</th>
                                        <th className="px-3 py-2">Your role</th>
                                        <th className="px-3 py-2">Quality seal</th>
                                        <th className="px-3 py-2">Completeness</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map((i) => (
                                        <tr key={i.id} className="hover:bg-gray-50/60">
                                            <td className="px-3 py-2">
                                                {i.href ? (
                                                    <Link href={i.href} className="text-[#0A1F44] hover:underline">
                                                        {i.label}
                                                    </Link>
                                                ) : (
                                                    <span className="text-[#2D3748]">{i.label}</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge
                                                    status={
                                                        i.role === 'accountable'
                                                            ? 'approved'
                                                            : i.role === 'responsible'
                                                              ? 'active'
                                                              : 'draft'
                                                    }
                                                    label={i.role_label}
                                                />
                                                {i.business_role && (
                                                    <span className="ml-2 text-[11px] text-[#718096]">
                                                        {i.business_role}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <QualitySealBadge seal={i.seal} />
                                            </td>
                                            <td className="px-3 py-2 text-xs text-[#718096]">
                                                {i.seal ? `${i.seal.completeness}%` : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

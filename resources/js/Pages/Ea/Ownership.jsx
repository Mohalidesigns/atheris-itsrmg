import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StewardshipDrawer from '@/Components/Ea/StewardshipDrawer';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Ownership & freshness dashboard — the "ownership completeness metric" from
 * WS 1.1, sitting next to the seal posture from WS 1.3.
 *
 * Ardoq ships a Foundation Insights agent whose first job is a continuous scan
 * for missing owners; §5.4 B1 makes ownership the prerequisite for surveys,
 * seals and "any accountability claim". This screen is where the gap is worked
 * down.
 */
export default function Ownership({
    completeness = [],
    posture = {},
    focusType,
    unowned = [],
    entityTypes = [],
    users = [],
    roles = [],
}) {
    const perms = useEaPermissions();
    const [drawer, setDrawer] = useState(null);

    const totals = completeness.reduce(
        (a, r) => ({
            total: a.total + r.total,
            accountable: a.accountable + r.accountable,
            unowned: a.unowned + r.unowned,
        }),
        { total: 0, accountable: 0, unowned: 0 },
    );
    const overall = totals.total ? Math.round((totals.accountable / totals.total) * 100) : 0;

    const bar = (pct) => (pct >= 80 ? '#2D7D46' : pct >= 40 ? '#E5A100' : '#B3261E');

    return (
        <AuthenticatedLayout header="Ownership & Freshness">
            <Head title="Ownership & Freshness" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Command Centre' },
                    { label: 'Ownership & Freshness' },
                ]}
                title="Ownership & Freshness"
                subtitle="Who is accountable for each record, and how much of the repository is currently trustworthy."
                actions={
                    <Link
                        href={route('ea.surveys')}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        Run a survey to close the gap
                    </Link>
                }
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard
                    label="Ownership completeness"
                    value={`${overall}%`}
                    tone={overall >= 80 ? 'green' : overall >= 40 ? 'amber' : 'red'}
                    sublabel="Records with an accountable owner"
                />
                <KpiCard label="Unowned records" value={totals.unowned} tone={totals.unowned ? 'red' : 'white'} />
                <KpiCard label="Seals approved" value={posture.approved || 0} tone="green" />
                <KpiCard
                    label="Need re-validation"
                    value={posture.check_needed || 0}
                    tone={posture.check_needed ? 'amber' : 'white'}
                />
                <KpiCard
                    label="Expiring ≤30d"
                    value={posture.expiring_30d || 0}
                    tone={posture.expiring_30d ? 'gold' : 'white'}
                    sublabel={`Avg completeness ${posture.avg_completeness || 0}%`}
                />
            </div>

            {posture.unsealed > 0 && (
                <div className="mb-5 rounded-xl border border-gray-200 bg-white p-4">
                    <p className="text-sm text-[#2D3748]">
                        <span className="font-semibold">{posture.unsealed}</span> of{' '}
                        {posture.total_entities} records have never been through the quality seal. Nothing has
                        confirmed they are accurate, so nothing citing them can claim an evidence quality.
                    </p>
                </div>
            )}

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                    Ownership by object type
                </h3>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Object type</th>
                            <th className="px-3 py-2">Records</th>
                            <th className="px-3 py-2">Any owner</th>
                            <th className="px-3 py-2">Accountable owner</th>
                            <th className="px-3 py-2 w-56">Completeness</th>
                            <th className="px-3 py-2 text-right">Gap</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {completeness.map((r) => (
                            <tr key={r.type} className="hover:bg-gray-50/60">
                                <td className="px-3 py-2 text-[#2D3748]">
                                    {r.route ? (
                                        <Link href={route(r.route)} className="text-[#0A1F44] hover:underline">
                                            {r.label}
                                        </Link>
                                    ) : (
                                        r.label
                                    )}
                                </td>
                                <td className="px-3 py-2 text-xs">{r.total}</td>
                                <td className="px-3 py-2 text-xs">{r.owned}</td>
                                <td className="px-3 py-2 text-xs">{r.accountable}</td>
                                <td className="px-3 py-2">
                                    <div className="flex items-center gap-2">
                                        <span className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100">
                                            <span
                                                className="block h-2 rounded-full"
                                                style={{ width: `${r.percent}%`, background: bar(r.percent) }}
                                            />
                                        </span>
                                        <span className="w-10 text-right text-xs text-[#718096]">{r.percent}%</span>
                                    </div>
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {r.unowned > 0 ? (
                                        <button
                                            type="button"
                                            onClick={() => router.get(route('ea.stewardship.ownership', { type: r.type }))}
                                            className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#B3261E] hover:bg-[#B3261E]/5"
                                        >
                                            {r.unowned} unowned
                                        </button>
                                    ) : (
                                        <span className="text-xs text-[#2D7D46]">Fully owned</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {focusType && (
                <div className="mt-5 overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">
                            Unowned records ·{' '}
                            {entityTypes.find((t) => t.value === focusType)?.label || focusType}
                        </h3>
                        <button
                            type="button"
                            onClick={() => router.get(route('ea.stewardship.ownership'))}
                            className="text-xs text-[#718096] hover:text-[#2D3748]"
                        >
                            Clear
                        </button>
                    </div>
                    {unowned.length === 0 ? (
                        <p className="px-4 py-6 text-center text-xs text-[#718096]">
                            Every record of this type has an owner.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {unowned.map((u) => (
                                <li key={u.id} className="flex items-center justify-between px-4 py-2">
                                    <span className="text-sm text-[#2D3748]">{u.label}</span>
                                    {perms.canEdit && (
                                        <button
                                            type="button"
                                            onClick={() => setDrawer({ entityType: focusType, entityId: u.id })}
                                            className="rounded border border-gray-200 px-2 py-1 text-xs font-medium text-[#0A1F44] hover:bg-gray-50"
                                        >
                                            Assign owner
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <StewardshipDrawer
                show={Boolean(drawer)}
                onClose={() => setDrawer(null)}
                entityType={drawer?.entityType}
                entityId={drawer?.entityId}
                users={users}
                roles={roles}
            />
        </AuthenticatedLayout>
    );
}

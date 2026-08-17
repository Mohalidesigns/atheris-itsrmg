import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import EaEmptyState from '@/Components/Ea/EaEmptyState';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head, router } from '@inertiajs/react';
import { ArrowPathIcon, ChartBarIcon } from '@heroicons/react/24/outline';

/**
 * Appendix A #24: `ea.kri.recompute` existed with no caller — the KRI page
 * showed whatever the last seeder or queued job left behind, with no way to
 * refresh it and no indication of how old the numbers were.
 */
export default function EaKri({ definitions = [] }) {
    const perms = useEaPermissions();
    const [running, setRunning] = useState(false);

    const recompute = () => {
        setRunning(true);
        router.post(
            route('ea.kri.recompute'),
            {},
            { preserveScroll: true, onFinish: () => setRunning(false) },
        );
    };

    // The freshest reading across all definitions is the honest "as at" stamp
    // for the page — §10 requires feed-sourced values to show when they were
    // last computed.
    const lastComputed = definitions
        .flatMap((k) => k.values || [])
        .map((v) => v.recorded_at)
        .filter(Boolean)
        .sort()
        .pop();

    return (
        <AuthenticatedLayout header="EA KRIs">
            <Head title="EA KRIs" />
            <PageHeader
                breadcrumbs={[
                    { label: 'Enterprise Architecture' },
                    { label: 'Command Centre' },
                    { label: 'EA KRIs' },
                ]}
                title="EA Key Risk Indicators"
                subtitle="EA-specific KRIs computed from the repository. Publishing them into the platform KRI module is contract I-8 in Phase 2."
                actions={
                    perms.canEdit && (
                        <button
                            type="button"
                            onClick={recompute}
                            disabled={running}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D] disabled:opacity-50"
                        >
                            <ArrowPathIcon className={`h-4 w-4 ${running ? 'animate-spin' : ''}`} />
                            {running ? 'Recomputing…' : 'Recompute now'}
                        </button>
                    )
                }
            />

            <EaWorkspaceTabs tabs={tabsFor('ea.kri')} current="ea.kri" />

            <p className="mb-4 text-xs text-[#718096]">
                {lastComputed
                    ? `Last computed ${new Date(lastComputed).toLocaleString()}.`
                    : 'These indicators have never been computed — the values below are whatever the repository was seeded with.'}
            </p>

            {definitions.length === 0 ? (
                <EaEmptyState
                    icon={ChartBarIcon}
                    title="No KRI definitions"
                    description="EA KRIs turn the repository into a board-reportable number: obsolescence exposure, exception backlog, vendor concentration, documentation completeness."
                    actionLabel="Compute the KRIs"
                    onAction={recompute}
                    canAct={perms.canEdit}
                />
            ) : (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                    {definitions.map((k) => {
                        const latest = k.values?.[0];
                        const trend = (k.values || []).slice(0, 12).reverse();
                        const max = Math.max(...trend.map((v) => Number(v.value)), 1);
                        return (
                            <div key={k.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <p className="font-mono text-xs text-[#0A1F44]">{k.code}</p>
                                        <h3 className="text-sm font-semibold text-[#2D3748]">{k.name}</h3>
                                    </div>
                                    {latest && <StatusBadge status={latest.status} />}
                                </div>
                                <p className="mt-1 text-xs capitalize text-[#718096]">{k.category}</p>
                                <p className="mt-2 text-2xl font-bold text-[#0A1F44]">
                                    {latest ? Number(latest.value).toFixed(1) : '—'}
                                    <span className="ml-1 text-[10px] font-normal text-[#718096]">{k.unit}</span>
                                </p>
                                <p className="text-[10px] text-[#718096]">
                                    G≤{k.threshold_green} · A≤{k.threshold_amber} · R≤{k.threshold_red}
                                </p>
                                <div className="mt-2 flex h-10 items-end gap-0.5">
                                    {trend.map((v, i) => (
                                        <div
                                            key={i}
                                            className="flex-1 rounded-t"
                                            style={{
                                                height: `${Math.min(100, (Number(v.value) / max) * 100)}%`,
                                                background:
                                                    v.status === 'red'
                                                        ? '#B3261E'
                                                        : v.status === 'amber'
                                                          ? '#E5A100'
                                                          : '#2D7D46',
                                            }}
                                        />
                                    ))}
                                </div>
                                {latest?.recorded_at && (
                                    <p className="mt-2 text-[10px] text-[#718096]">
                                        As at {new Date(latest.recorded_at).toLocaleDateString()}
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

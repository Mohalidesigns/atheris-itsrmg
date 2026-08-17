import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import useEaPermissions from '@/Components/Ea/useEaPermissions';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowPathIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline';

/**
 * EA Data Sources — ATH-EAR-002 WS 0.4.
 *
 * Two defects are fixed on this one screen:
 *
 *   1. §2.4 (RC-4): `ea.sync.assets`, `ea.sync.eol` and `ea.sync.cve` were all
 *      orphaned. The three sync buttons were unreachable, so even the manual
 *      ingestion path into the repository was closed.
 *
 *   2. §10 / R7: both feed clients fall back to bundled fixtures *silently*. An
 *      evaluator pressing "Sync CVE" on an air-gapped UAT box received seven
 *      fixture CVEs presented as live data. Provenance labelling is a Phase 0
 *      release gate, not a nice-to-have — every run is now labelled, and the
 *      last successful live fetch is stated separately from the last run.
 */

const PROVENANCE = {
    live: {
        tone: 'pass',
        label: 'Live feed',
        icon: CheckCircleIcon,
        colour: 'text-[#2D7D46]',
        note: 'Data came from the upstream source.',
    },
    mixed: {
        tone: 'warn',
        label: 'Partially live',
        icon: ExclamationTriangleIcon,
        colour: 'text-[#8A6400]',
        note: 'Some records came from the bundled fixture, not the upstream source.',
    },
    fixture: {
        tone: 'warn',
        label: 'Bundled fixture',
        icon: ExclamationTriangleIcon,
        colour: 'text-[#8A6400]',
        note: 'No outbound connectivity. These values are shipped sample data and must not be shown to a regulator or presented as live.',
    },
    failed: {
        tone: 'fail',
        label: 'Failed',
        icon: XCircleIcon,
        colour: 'text-[#B3261E]',
        note: 'The run returned no data.',
    },
};

function ago(ts) {
    if (!ts) return null;
    const d = new Date(ts);
    const days = Math.floor((Date.now() - d.getTime()) / 86400000);
    if (days === 0) return 'today';
    if (days === 1) return 'yesterday';
    return `${days} days ago`;
}

function FeedCard({ feed, canRun }) {
    const [running, setRunning] = useState(false);
    const last = feed.last;
    const meta = PROVENANCE[last?.provenance] || null;
    const Icon = meta?.icon;

    const run = () => {
        setRunning(true);
        router.post(route(feed.route), {}, { preserveScroll: true, onFinish: () => setRunning(false) });
    };

    return (
        <div className="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">{feed.name}</h3>
                    <p className="mt-1 text-xs leading-relaxed text-[#718096]">{feed.description}</p>
                    <p className="mt-2 break-all font-mono text-[10px] text-[#718096]">{feed.endpoint}</p>
                </div>
                {canRun && (
                    <button
                        type="button"
                        onClick={run}
                        disabled={running}
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D] disabled:opacity-50"
                    >
                        <ArrowPathIcon className={`h-4 w-4 ${running ? 'animate-spin' : ''}`} />
                        {running ? 'Running…' : 'Run now'}
                    </button>
                )}
            </div>

            <div className="mt-4 border-t border-gray-100 pt-3">
                {!last ? (
                    <p className="text-xs text-[#718096]">
                        Never run. Nothing in the repository came from this feed.
                    </p>
                ) : (
                    <>
                        <div className="flex items-center gap-2">
                            {Icon && <Icon className={`h-4 w-4 ${meta.colour}`} />}
                            <StatusBadge status={meta.tone} label={meta.label} />
                            <span className="text-xs text-[#718096]">
                                {last.ran_at ? `${new Date(last.ran_at).toLocaleString()} (${ago(last.ran_at)})` : ''}
                            </span>
                        </div>
                        <p
                            className={`mt-2 text-xs ${
                                last.provenance === 'fixture' ? 'font-medium text-[#8A6400]' : 'text-[#718096]'
                            }`}
                        >
                            {meta.note}
                        </p>
                        <p className="mt-1 text-xs text-[#718096]">
                            {last.records_touched} record{last.records_touched === 1 ? '' : 's'} scanned ·{' '}
                            {last.records_written} written
                            {last.message ? ` · ${last.message}` : ''}
                            {last.triggered_by ? ` · by ${last.triggered_by}` : ''}
                        </p>

                        <p className="mt-2 text-[11px] text-[#718096]">
                            {feed.last_successful ? (
                                <>
                                    Last successful live fetch:{' '}
                                    <span className="font-medium text-[#2D3748]">
                                        {new Date(feed.last_successful.ran_at).toLocaleString()}
                                    </span>
                                </>
                            ) : (
                                <span className="font-medium text-[#8A6400]">
                                    This feed has never successfully reached its upstream source on this installation.
                                </span>
                            )}
                        </p>
                    </>
                )}
            </div>
        </div>
    );
}

export default function DataSources({ feeds = [], history = [], coverage = {}, templates = [] }) {
    const perms = useEaPermissions();
    const anyFixture = feeds.some((f) => f.last?.provenance === 'fixture');

    return (
        <AuthenticatedLayout header="EA Data Sources">
            <Head title="EA Data Sources" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Settings' }, { label: 'Data Sources' }]}
                title="Data Sources & Feed Provenance"
                subtitle="Every inbound path into the architecture repository, with the source and timestamp behind each field."
                actions={
                    <Link
                        href={route('ea.bulk-import')}
                        className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        Bulk import ({templates.length} templates)
                    </Link>
                }
            />

            {anyFixture && (
                <div className="mb-5 rounded-xl border border-[#E5A100]/40 bg-[#E5A100]/5 p-4">
                    <p className="text-sm font-semibold text-[#8A6400]">
                        At least one feed is currently serving bundled fixture data
                    </p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        This host could not reach the upstream source, so the client fell back to sample data shipped
                        with the product. Anything derived from that feed — obsolescence flags, CVE counts, the
                        technology radar’s EOL window — is illustrative, not evidence. Do not cite it in a CSAT return
                        or show it to an assessor.
                    </p>
                </div>
            )}

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Applications" value={coverage.applications || 0} tone="navy" />
                <KpiCard
                    label="Linked to an asset"
                    value={`${coverage.applications_from_assets || 0}/${coverage.applications || 0}`}
                    tone="white"
                    sublabel="Contract I-3"
                />
                <KpiCard label="Tech components" value={coverage.tech_components || 0} tone="gold" />
                <KpiCard
                    label="With EOL date"
                    value={`${coverage.tech_with_eol || 0}/${coverage.tech_components || 0}`}
                    tone="white"
                />
                <KpiCard
                    label="With CPE"
                    value={`${coverage.tech_with_cpe || 0}/${coverage.tech_components || 0}`}
                    tone="white"
                    sublabel="Required for CVE match"
                />
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                {feeds.map((f) => (
                    <FeedCard key={f.key} feed={f} canRun={perms.canAdmin} />
                ))}
            </div>

            {!perms.canAdmin && (
                <p className="mb-6 text-xs text-[#718096]">
                    Running a feed overwrites catalogue records in bulk, so it requires the same grant as deletion.
                    Your role can view provenance but not trigger a sync.
                </p>
            )}

            <div className="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <h3 className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-[#0A1F44]">
                    Sync history
                </h3>
                {history.length === 0 ? (
                    <p className="px-4 py-8 text-center text-xs text-[#718096]">
                        No feed has been run on this installation yet.
                    </p>
                ) : (
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">When</th>
                                <th className="px-3 py-2">Feed</th>
                                <th className="px-3 py-2">Provenance</th>
                                <th className="px-3 py-2">Scanned</th>
                                <th className="px-3 py-2">Written</th>
                                <th className="px-3 py-2">Detail</th>
                                <th className="px-3 py-2">Triggered by</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {history.map((h) => {
                                const meta = PROVENANCE[h.provenance] || PROVENANCE.failed;
                                return (
                                    <tr key={h.id}>
                                        <td className="px-3 py-2 text-xs text-[#718096]">
                                            {h.ran_at ? new Date(h.ran_at).toLocaleString() : '—'}
                                        </td>
                                        <td className="px-3 py-2 text-xs uppercase text-[#2D3748]">{h.feed}</td>
                                        <td className="px-3 py-2">
                                            <StatusBadge status={meta.tone} label={meta.label} />
                                        </td>
                                        <td className="px-3 py-2 text-xs">{h.records_touched}</td>
                                        <td className="px-3 py-2 text-xs">{h.records_written}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{h.message || '—'}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{h.triggered_by || 'system'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>

            <div className="mt-6 rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                <h3 className="text-sm font-semibold text-[#0A1F44]">Inbound paths not yet built</h3>
                <p className="mt-1 text-xs text-[#718096]">
                    Stated explicitly so the gap is visible rather than assumed. Surveys and campaigns (B2) and the
                    quality-seal freshness state machine (B3) are Phase 1; scheduled feeds with drift detection are
                    Phase 2. SaaS/CASB shadow-IT discovery is a formal anti-goal (§5.1) — it needs an integration
                    ecosystem this product does not fund, and the estate here is largely on-premises.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}

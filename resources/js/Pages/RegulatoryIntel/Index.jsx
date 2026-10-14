import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { NewspaperIcon, MagnifyingGlassIcon, LockClosedIcon } from '@/Components/Material/Symbols';

/**
 * Regulatory Intelligence Console (spec §4.4.3): left rail by regulator, the circular feed with each
 * bank's triage chip (Unread / Assessed / Actioned), and the detail page for impacts.
 */
export const TRIAGE = {
    unread: { label: 'Unread', tone: 'open' },
    assessed: { label: 'Assessed', tone: 'in_review' },
    actioned: { label: 'Actioned', tone: 'pass' },
    not_applicable: { label: 'Not applicable', tone: 'closed' },
};

const EDITORIAL = { published: 'Published', superseded: 'Superseded', editorial_review: 'Editorial review', impact_pending: 'Impact pending', summarising: 'Summarising', draft: 'Draft' };

export default function RegIntelIndex({ circulars = [], counts = {}, triage = {}, regulators = [], filters = {} }) {
    const [search, setSearch] = useState(filters.q || '');
    const apply = (params) =>
        router.get(route('reg-intel.index'), { ...filters, ...params }, { preserveState: true, replace: true });
    const total = Object.values(counts).reduce((a, b) => a + b, 0);

    const rail = (active, label, n, params) => (
        <li key={label}>
            <button
                type="button"
                onClick={() => apply(params)}
                className={`flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-sm ${active ? 'bg-[#0A1F44]/5 font-medium text-[#0A1F44]' : 'text-[#2D3748] hover:bg-gray-50'}`}
            >
                {label} <span className="text-xs text-[#718096]">{n}</span>
            </button>
        </li>
    );

    return (
        <AuthenticatedLayout header="Regulatory Intelligence">
            <Head title="Circular Feed" />
            <PageHeader
                breadcrumbs={[{ label: 'Regulatory Intelligence' }, { label: 'Circular Feed' }]}
                title="Circular Feed"
                subtitle="Circulars from CBN, NDPC, SEC, NCC, NAICOM, PENCOM and NDIC. Triage each one, link what it changes in your register, and track it to actioned."
            />

            <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
                <KpiCard label="Unread" value={triage.unread ?? 0} tone="navy" />
                <KpiCard label="Assessed" value={triage.assessed ?? 0} tone="gold" />
                <KpiCard label="Actioned" value={triage.actioned ?? 0} tone="green" />
                <KpiCard label="Not applicable" value={triage.not_applicable ?? 0} tone="white" />
                <KpiCard label="Overdue actions" value={triage.overdue ?? 0} tone={triage.overdue ? 'red' : 'white'} />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-5">
                <aside className="h-fit space-y-4 rounded-xl border border-gray-100 bg-white p-3">
                    <div>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-[#718096]">Regulators</h3>
                        <ul className="space-y-1">
                            {rail(!filters.regulator, 'All', total, { regulator: undefined })}
                            {regulators.map((r) => rail(filters.regulator === r, r, counts[r] || 0, { regulator: r }))}
                        </ul>
                    </div>
                    <div>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-[#718096]">Triage</h3>
                        <ul className="space-y-1">
                            {rail(!filters.status, 'Any status', total, { status: undefined })}
                            {Object.entries(TRIAGE).map(([key, t]) =>
                                rail(filters.status === key, t.label, triage[key] ?? 0, { status: key }),
                            )}
                        </ul>
                    </div>
                </aside>

                <section className="rounded-xl border border-gray-100 bg-white shadow-sm lg:col-span-4">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-4">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                apply({ q: search || undefined });
                            }}
                            className="relative w-full max-w-sm"
                        >
                            <MagnifyingGlassIcon className="absolute left-2.5 top-2.5 h-4 w-4 text-[#718096]" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search title or reference…"
                                aria-label="Search circulars"
                                className="w-full rounded-lg border-gray-200 py-2 pl-8 text-sm focus:border-[#0A1F44] focus:ring-[#0A1F44]/30"
                            />
                        </form>
                        <span className="text-xs text-[#718096]">{circulars.length} circulars</span>
                    </div>

                    {circulars.length === 0 ? (
                        <div className="p-10 text-center">
                            <NewspaperIcon className="mx-auto h-10 w-10 text-gray-300" />
                            <p className="mt-2 text-sm text-[#718096]">No circulars match this filter.</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {circulars.map((c) => {
                                const t = TRIAGE[c.triage] || TRIAGE.unread;
                                return (
                                    <li key={c.id} className="p-4 hover:bg-gray-50">
                                        <Link href={route('reg-intel.show', c.id)} className="block">
                                            <div className="flex items-start justify-between gap-4">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="inline-flex items-center rounded bg-[#0A1F44] px-2 py-0.5 text-[11px] font-medium text-white">
                                                            {c.regulator_code}
                                                        </span>
                                                        <span className="text-xs text-[#718096]">{c.circular_number}</span>
                                                        <span className="text-xs text-[#718096]">Issued {c.issued_at || '—'}</span>
                                                        {c.is_private && (
                                                            <span className="inline-flex items-center gap-1 text-[11px] text-[#8A6400]">
                                                                <LockClosedIcon className="h-3 w-3" /> Uploaded by your bank
                                                            </span>
                                                        )}
                                                        {!['published', 'superseded'].includes(c.status) && !c.is_private && (
                                                            <StatusBadge status="warn" label={EDITORIAL[c.status] || c.status} />
                                                        )}
                                                        {c.status === 'superseded' && <StatusBadge status="closed" label="Superseded" />}
                                                    </div>
                                                    <h4 className={`mt-1 text-sm text-[#2D3748] ${c.triage === 'unread' ? 'font-semibold' : 'font-medium'}`}>
                                                        {c.title}
                                                    </h4>
                                                    {c.llm_summary && (
                                                        <p className="mt-1 line-clamp-2 text-xs text-[#718096]">{c.llm_summary}</p>
                                                    )}
                                                    {(c.owner || c.impacts_count > 0) && (
                                                        <p className="mt-1 text-[11px] text-[#718096]">
                                                            {c.owner && <>Owner {c.owner}</>}
                                                            {c.due_date && (
                                                                <span className={c.is_overdue ? 'font-semibold text-[#B3261E]' : ''}>
                                                                    {' '}· due {c.due_date}{c.is_overdue ? ' (overdue)' : ''}
                                                                </span>
                                                            )}
                                                            {c.impacts_count > 0 && <> · {c.impacts_count} linked record{c.impacts_count === 1 ? '' : 's'}</>}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="flex shrink-0 flex-col items-end gap-1">
                                                    <StatusBadge status={t.tone} label={t.label} />
                                                    {c.severity && <StatusBadge status={String(c.severity).toLowerCase()} label={String(c.severity)} />}
                                                </div>
                                            </div>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import { TierBadge, GradeBadge, Sparkline, fmtDate } from '@/Components/Vendors/VendorBadges';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowPathIcon } from '@/Components/Material/Symbols';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const sevTone = { critical: 'text-[#B3261E]', high: 'text-[#C05621]', medium: 'text-[#8A6400]', low: 'text-[#718096]' };

function TriageRow({ event, canEdit }) {
    const form = useForm({ decision: '', notes: '' });
    const [dismissing, setDismissing] = useState(false);
    const decide = (decision) => {
        form.transform((d) => ({ ...d, decision }));
        form.post(route('security-ratings.triage', event.id), { preserveScroll: true });
    };

    return (
        <li className="p-3 text-sm">
            <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <p className="font-medium text-[#2D3748]">{event.headline}</p>
                    <p className="text-xs text-[#718096]">
                        <Link href={route('vendors.show', event.vendor_id)} className="underline">{event.vendor?.name}</Link>
                        {' '}<TierBadge tier={event.vendor?.tier} /> · {cap(event.event_type)} · <span className={`font-semibold ${sevTone[event.severity]}`}>{event.severity}</span> · {fmtDate(event.discovered_at)} · {event.source === 'demo_feed' ? 'demo feed' : event.source}
                    </p>
                    {event.status !== 'new' && (
                        <p className="text-xs text-[#718096]">
                            {cap(event.status)} by {event.reviewer?.name || '—'} {event.reviewed_at && `on ${fmtDate(event.reviewed_at)}`}{event.review_notes ? ` — ${event.review_notes}` : ''}
                            {event.reassessment && <> · <Link href={route('vendor-assessments.show', event.reassessment.id)} className="underline">reassessment ({event.reassessment.status})</Link></>}
                        </p>
                    )}
                </div>
                {canEdit && event.status === 'new' && (
                    dismissing ? (
                        <div className="flex gap-2">
                            <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Why it does not affect the bank" className="w-56 rounded-lg border-gray-300 text-xs" />
                            <button type="button" onClick={() => decide('dismissed')} disabled={form.processing} className="rounded-lg border border-gray-300 px-2 text-xs">Dismiss</button>
                            <button type="button" onClick={() => setDismissing(false)} className="text-xs text-[#718096]">Cancel</button>
                        </div>
                    ) : (
                        <div className="flex flex-shrink-0 gap-2">
                            <button type="button" onClick={() => decide('reassessment')} disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-2 py-1 text-xs font-semibold text-white">Reassess vendor</button>
                            <button type="button" onClick={() => decide('acknowledged')} disabled={form.processing} className="rounded-lg border border-[#0A1F44] px-2 py-1 text-xs text-[#0A1F44]">Acknowledge</button>
                            <button type="button" onClick={() => setDismissing(true)} className="text-xs text-[#718096] underline">Dismiss…</button>
                        </div>
                    )
                )}
            </div>
            {Object.values(form.errors).length > 0 && <p className="mt-1 text-xs text-[#C53030]">{Object.values(form.errors).join(' ')}</p>}
        </li>
    );
}

export default function TprmRatings({ vendors = [], breaches = [], metrics = {}, filters = {}, dropAlert = 10, can = {} }) {
    const [refreshing, setRefreshing] = useState(false);
    const refresh = () => { setRefreshing(true); router.post(route('security-ratings.refresh'), {}, { preserveScroll: true, onFinish: () => setRefreshing(false) }); };
    const apply = (patch) => router.get(route('security-ratings.index'), { ...filters, ...patch }, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header="Vendor Security Ratings">
            <Head title="Security Ratings" />
            <PageHeader
                breadcrumbs={[{ label: 'Vendor Management' }, { label: 'Security Ratings' }]}
                title="Continuous monitoring"
                subtitle="Outside-in security ratings for every vendor with a domain, and a breach watch to triage. The provider runs in sandbox mode until the bank supplies SecurityScorecard or Bitsight credentials."
                actions={can.edit && (
                    <button type="button" onClick={refresh} disabled={refreshing} className="inline-flex items-center gap-1 rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white disabled:opacity-50">
                        <ArrowPathIcon className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} /> {refreshing ? 'Refreshing…' : 'Refresh ratings'}
                    </button>
                )}
            />

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
                <KpiCard label="Rated vendors" value={metrics.rated ?? 0} sublabel={`of ${metrics.total ?? 0} current`} tone="navy" />
                <KpiCard label="Grade D or F" value={metrics.poor_rating ?? 0} tone={metrics.poor_rating ? 'red' : 'white'} />
                <KpiCard label={`Drops ≥ ${dropAlert} pts (30 d)`} value={metrics.rating_drops ?? 0} tone={metrics.rating_drops ? 'amber' : 'white'} />
                <KpiCard label="Breach events to triage" value={metrics.open_breach_events ?? 0} tone={metrics.open_breach_events ? 'amber' : 'white'} />
                <KpiCard label="Open assessments" value={metrics.assessments_open ?? 0} tone="white" />
            </div>

            <div className="mb-6 bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="flex items-center justify-between border-b border-gray-100 p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Breach watch</h3>
                    <div className="flex gap-2 text-xs">
                        <button type="button" onClick={() => apply({ events: undefined })} className={filters.events !== 'all' ? 'font-semibold text-[#0A1F44] underline' : 'text-[#718096]'}>To triage</button>
                        <button type="button" onClick={() => apply({ events: 'all' })} className={filters.events === 'all' ? 'font-semibold text-[#0A1F44] underline' : 'text-[#718096]'}>All events</button>
                    </div>
                </div>
                {breaches.length === 0 ? <p className="p-6 text-center text-sm text-[#718096]">Nothing to triage.</p> : (
                    <ul className="divide-y divide-gray-100">{breaches.map((b) => <TriageRow key={b.id} event={b} canEdit={can.edit} />)}</ul>
                )}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <div className="flex items-center justify-between p-4">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Ratings by vendor</h3>
                    <select value={filters.tier || ''} onChange={(e) => apply({ tier: e.target.value || undefined })} className="rounded-lg border-gray-200 text-xs">
                        <option value="">All tiers</option>
                        {[1, 2, 3].map((t) => <option key={t} value={t}>Tier {t}</option>)}
                    </select>
                </div>
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Vendor</th><th className="px-3 py-2">Tier</th><th className="px-3 py-2">Grade</th>
                            <th className="px-3 py-2">6-month trend</th><th className="px-3 py-2 text-right">Change</th><th className="px-3 py-2">Captured</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {vendors.map((v) => (
                            <tr key={v.id}>
                                <td className="px-3 py-2">
                                    <Link href={route('vendors.show', v.id)} className="font-medium text-[#0A1F44] hover:underline">{v.name}</Link>
                                    <p className="text-[11px] text-[#718096]">{v.category}{v.domain ? ` · ${v.domain}` : ' · no domain'}</p>
                                </td>
                                <td className="px-3 py-2"><TierBadge tier={v.tier} /></td>
                                <td className="px-3 py-2"><GradeBadge grade={v.grade} value={v.rating} /></td>
                                <td className="px-3 py-2"><Sparkline values={v.trend} /></td>
                                <td className={`px-3 py-2 text-right text-xs font-semibold ${v.delta <= -dropAlert ? 'text-[#B3261E]' : v.delta < 0 ? 'text-[#C05621]' : v.delta > 0 ? 'text-[#2D7D46]' : 'text-[#718096]'}`}>
                                    {v.delta == null ? '—' : v.delta > 0 ? `+${v.delta}` : v.delta}
                                </td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{fmtDate(v.captured_at) || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

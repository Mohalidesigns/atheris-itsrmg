import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import { TierBadge, GradeBadge, Sparkline, fmtDate, isPast } from '@/Components/Vendors/VendorBadges';
import { Head, Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon, PlusIcon, ExclamationTriangleIcon } from '@/Components/Material/Symbols';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

function formatCurrency(value, currency = 'NGN') {
    if (!value) return null;
    try {
        return new Intl.NumberFormat('en-NG', { style: 'currency', currency: currency || 'NGN' }).format(value);
    } catch {
        return `${currency} ${Number(value).toLocaleString()}`;
    }
}

function DetailRow({ label, value }) {
    return (
        <div className="py-2.5 grid grid-cols-3 gap-4 border-b border-gray-50 last:border-0">
            <dt className="text-sm font-medium text-[#718096]">{label}</dt>
            <dd className="text-sm text-[#2D3748] col-span-2">{value || '--'}</dd>
        </div>
    );
}

function Card({ title, right, children, className = '' }) {
    return (
        <div className={`bg-white rounded-xl border border-gray-100 shadow-sm ${className}`}>
            <div className="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-[#2D3748]">{title}</h3>
                {right}
            </div>
            {children}
        </div>
    );
}

export default function ShowVendor({ vendor, tiering, applications = [], reviewMonths = {}, can = {} }) {
    const overdue = isPast(vendor.next_review_date);
    const latest = vendor.ratings?.length ? vendor.ratings[vendor.ratings.length - 1] : null;
    const gaps = [
        vendor.processes_personal_data && !vendor.dpa_signed_at && 'Processes personal data without a signed data-processing agreement (NDPA 2023).',
        vendor.material_outsourcing && !vendor.regulator_notified_at && 'Material outsourcing arrangement not yet notified to the CBN.',
        overdue && `Reassessment overdue since ${fmtDate(vendor.next_review_date)}.`,
        isPast(vendor.contract_end) && vendor.status === 'active' && `Contract ended ${fmtDate(vendor.contract_end)} but the vendor is still active.`,
        !vendor.owner_id && 'No relationship owner.',
    ].filter(Boolean);

    const destroy = () => {
        if (confirm(`Delete ${vendor.name}? Only possible for a vendor with no assessments and no architecture links.`)) {
            router.delete(route('vendors.destroy', vendor.id));
        }
    };

    return (
        <AuthenticatedLayout header={
            <div className="flex items-center gap-3">
                <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">{vendor.vendor_code}</span>
                <span>{vendor.name}</span>
            </div>
        }>
            <Head title={`${vendor.vendor_code} - ${vendor.name}`} />

            <div className="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-4">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Tier</p>
                    <div className="mt-1"><TierBadge tier={vendor.tier} /></div>
                    <p className="mt-1 text-[11px] text-[#718096]">Reassess every {reviewMonths[vendor.tier] ?? '—'} months</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Bank's risk rating</p>
                    <div className="mt-1"><StatusBadge status={vendor.risk_level} label={cap(vendor.risk_level)} /></div>
                    <p className="mt-1 text-[11px] text-[#718096]">{cap(vendor.status)}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Security rating</p>
                    <div className="mt-1 flex items-center gap-2"><GradeBadge grade={latest?.grade} value={latest?.rating_value} /><Sparkline values={(vendor.ratings || []).map((r) => r.rating_value)} /></div>
                    <p className="mt-1 text-[11px] text-[#718096]">{latest ? `${fmtDate(latest.captured_at)} · ${latest.provider}` : vendor.domain ? 'Awaiting first capture' : 'No domain on record'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Next review</p>
                    <p className={`mt-1 text-sm font-semibold ${overdue ? 'text-[#B3261E]' : 'text-[#2D3748]'}`}>{fmtDate(vendor.next_review_date) || '--'}</p>
                    <p className="mt-1 text-[11px] text-[#718096]">Last assessed {fmtDate(vendor.last_assessed) || 'never'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Relationship owner</p>
                    <p className={`mt-1 text-sm font-medium ${vendor.owner ? 'text-[#2D3748]' : 'text-[#B3261E]'}`}>{vendor.owner?.name || 'Unassigned'}</p>
                    {vendor.owner?.job_title && <p className="text-[11px] text-[#718096]">{vendor.owner.job_title}</p>}
                </div>
            </div>

            {gaps.length > 0 && (
                <div className="mb-4 rounded-xl border border-[#E5A100]/40 bg-[#FFF8E6] p-3 text-sm text-[#8A6400]">
                    <p className="flex items-center gap-1 font-semibold"><ExclamationTriangleIcon className="h-4 w-4" /> Needs attention</p>
                    <ul className="mt-1 list-disc pl-6 text-xs">{gaps.map((g) => <li key={g}>{g}</li>)}</ul>
                </div>
            )}

            <div className="flex gap-2 mb-4">
                {can.edit && (
                    <Link href={route('vendors.edit', vendor.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        <PencilIcon className="w-4 h-4" /> Edit
                    </Link>
                )}
                {can.assess && (
                    <Link href={route('vendor-assessments.create', { vendor_id: vendor.id })} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#0A1F44] rounded-lg text-[#0A1F44] hover:bg-[#0A1F44] hover:text-white">
                        <PlusIcon className="w-4 h-4" /> New assessment
                    </Link>
                )}
                {can.delete && (
                    <button type="button" onClick={destroy} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                        <TrashIcon className="w-4 h-4" /> Delete
                    </button>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <Card title="Why this tier" className="lg:col-span-1">
                    <div className="px-5 py-3 text-sm">
                        <p className="text-[#2D3748]"><strong>{tiering.points}</strong> points → Tier {tiering.tier}</p>
                        <ul className="mt-2 space-y-1 text-xs text-[#718096]">
                            {tiering.reasons.length === 0 && <li>No tiering factors recorded.</li>}
                            {tiering.reasons.map((r) => <li key={r}>{r}</li>)}
                        </ul>
                    </div>
                </Card>

                <Card title="Regulatory facts" className="lg:col-span-2">
                    <div className="px-5 py-2">
                        <DetailRow label="Access to bank data" value={cap(vendor.data_access_level) || 'Not recorded'} />
                        <DetailRow label="Personal data (NDPA)" value={vendor.processes_personal_data ? (vendor.dpa_signed_at ? `Processor · DPA signed ${fmtDate(vendor.dpa_signed_at)}` : <span className="text-[#B3261E]">Processor · no DPA on file</span>) : 'Not a processor'} />
                        <DetailRow label="Outsourcing (CBN)" value={vendor.material_outsourcing ? (vendor.regulator_notified_at ? `Material · CBN notified ${fmtDate(vendor.regulator_notified_at)}` : <span className="text-[#B3261E]">Material · CBN not notified</span>) : 'Not material'} />
                        <DetailRow label="Shared Directory" value={vendor.directory_entry ? `${vendor.directory_entry.legal_name} · baseline DDQ ${vendor.directory_entry.baseline_ddq_version}` : 'Not linked'} />
                    </div>
                </Card>

                <Card title="Contract & contact" className="lg:col-span-2">
                    <div className="px-5 py-2">
                        <DetailRow label="Description" value={vendor.description} />
                        <DetailRow label="Services" value={vendor.services_provided} />
                        <DetailRow label="Contract" value={[fmtDate(vendor.contract_start), fmtDate(vendor.contract_end)].filter(Boolean).join(' → ')} />
                        <DetailRow label="Value" value={formatCurrency(vendor.contract_value, vendor.contract_currency)} />
                        <DetailRow label="SLA" value={vendor.sla_details} />
                        <DetailRow label="Contact" value={[vendor.contact_name, vendor.contact_email, vendor.contact_phone].filter(Boolean).join(' · ')} />
                        <DetailRow label="Website / domain" value={[vendor.website, vendor.domain].filter(Boolean).join(' · ')} />
                    </div>
                </Card>

                <Card title="Applications supplied" right={<span className="text-xs text-[#718096]">{applications.length}</span>}>
                    {applications.length === 0 ? <p className="px-5 py-6 text-center text-xs text-[#718096]">No application in the architecture repository names this vendor.</p> : (
                        <ul className="divide-y divide-gray-50">
                            {applications.map((a) => (
                                <li key={a.id} className="px-5 py-2 flex items-center justify-between text-sm">
                                    <Link href={route('ea.applications.show', a.id)} className="text-[#2D3748] hover:underline">{a.name}</Link>
                                    <StatusBadge status={a.criticality} label={cap(a.criticality)} />
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Assessments" className="lg:col-span-2" right={<span className="text-xs text-[#718096]">{(vendor.assessments || []).length}</span>}>
                    {(vendor.assessments || []).length === 0 ? <p className="px-5 py-6 text-center text-xs text-[#718096]">No assessment on record.</p> : (
                        <ul className="divide-y divide-gray-50">
                            {vendor.assessments.map((a) => (
                                <li key={a.id} className="px-5 py-2 flex items-center justify-between text-sm">
                                    <div>
                                        <Link href={route('vendor-assessments.show', a.id)} className="text-[#2D3748] hover:underline">{a.title}</Link>
                                        <p className="text-[11px] text-[#718096]">{cap(a.assessment_type)} · {fmtDate(a.assessment_date)} · {a.assessor?.name || '—'}</p>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs">
                                        {a.overall_score != null && <span className="font-mono-data">{Number(a.overall_score).toFixed(0)}</span>}
                                        {a.residual_rating && <span className="text-[#718096]">residual {a.residual_rating}</span>}
                                        <StatusBadge status={a.status} label={cap(a.status)} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Breach watch" right={<Link href={route('security-ratings.index', { events: 'all' })} className="text-xs text-[#0A1F44] underline">All events →</Link>}>
                    {(vendor.breach_events || []).length === 0 ? <p className="px-5 py-6 text-center text-xs text-[#718096]">No breach-watch events for this vendor.</p> : (
                        <ul className="divide-y divide-gray-50">
                            {vendor.breach_events.map((b) => (
                                <li key={b.id} className="px-5 py-2 text-xs">
                                    <p className="text-sm text-[#2D3748]">{b.headline}</p>
                                    <p className="text-[#718096]">{cap(b.event_type)} · {b.severity} · {fmtDate(b.discovered_at)} · <span className={b.status === 'new' ? 'font-semibold text-[#B3261E]' : ''}>{cap(b.status)}</span>
                                        {b.reassessment && <> · <Link href={route('vendor-assessments.show', b.reassessment.id)} className="underline">reassessment</Link></>}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

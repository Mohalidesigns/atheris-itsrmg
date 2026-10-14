import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { fmtDate, fmtDateTime, Pill, severityTone } from '@/Components/Monitoring/format';

function Action({ drift, action, label, fields = [], tone = 'navy', children }) {
    const initial = Object.fromEntries(fields.map((f) => [f, '']));
    const form = useForm({ action, ...initial });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(route('drift.transition', drift.id), { preserveScroll: true, onSuccess: () => form.reset() }); }} className="space-y-2 border-t border-gray-100 pt-3">
            {children && children(form, err)}
            <button type="submit" disabled={form.processing} className={`w-full rounded-lg px-3 py-2 text-xs font-semibold disabled:opacity-50 ${tone === 'navy' ? 'bg-[#0A1F44] text-white' : 'border border-[#0A1F44] text-[#0A1F44]'}`}>{label}</button>
        </form>
    );
}

export default function DriftShow({ drift, sourceLabel, changes = [], users = [], can = {} }) {
    const errors = usePage().props.errors || {};
    const open = ['open', 'investigating'].includes(drift.status);
    const me = usePage().props.auth?.user?.id;
    return (
        <AuthenticatedLayout header="Drift Detection">
            <Head title={drift.drift_code} />
            <PageHeader
                breadcrumbs={[{ label: 'Continuous Monitoring' }, { label: 'Drift Detection', href: route('monitoring.drift') }, { label: drift.drift_code }]}
                title={`${drift.drift_code} · ${drift.title}`}
                subtitle={`${drift.asset?.name || 'Unknown asset'} · detected ${fmtDateTime(drift.detected_at)} by ${sourceLabel}`}
            />
            {errors.drift && <p className="mb-3 rounded-lg bg-[#FEE2E2] px-3 py-2 text-xs text-[#C53030]">{errors.drift}</p>}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 space-y-4">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <div className="flex flex-wrap items-center gap-2"><Pill value={drift.severity} tones={severityTone} /><Pill value={drift.status} /></div>
                        <p className="mt-3 text-sm text-[#2D3748]">{drift.description}</p>
                        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="rounded-lg bg-[#F0FDF4] p-3"><p className="text-[11px] uppercase text-[#2D7D46]">Baseline</p><p className="font-mono text-sm text-[#2D3748]">{drift.baseline_value || '—'}</p></div>
                            <div className="rounded-lg bg-[#FEF2F2] p-3"><p className="text-[11px] uppercase text-[#C53030]">Observed</p><p className="font-mono text-sm text-[#2D3748]">{drift.observed_value || '—'}</p></div>
                        </div>
                    </div>
                    {!open && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 text-sm">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Resolution</h3>
                            <p className="mt-1 text-[#4A5568]">{drift.resolution_note}</p>
                            <p className="mt-1 text-xs text-[#718096]">{drift.status === 'authorised' && drift.change && <>Change <Link href={route('changes.show', drift.change.id)} className="text-[#0A1F44] underline">{drift.change.change_code}</Link> ({drift.change.status}) · </>}{drift.resolver ? `${drift.resolver.name}, ` : ''}{fmtDateTime(drift.resolved_at)}{drift.accepted_until && ` · accepted until ${fmtDate(drift.accepted_until)}`}</p>
                        </div>
                    )}
                </div>
                <aside className="space-y-4">
                    {['open', 'investigating', 'accepted'].includes(drift.status) && <ApprovalTrail process="drift.acceptance" id={drift.id} />}
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 text-xs space-y-2">
                        <p><span className="text-[#718096]">Asset:</span> {drift.asset ? <Link href={route('assets.show', drift.asset.id)} className="text-[#0A1F44] underline">{drift.asset.asset_id_code} {drift.asset.name}</Link> : '—'}</p>
                        <p><span className="text-[#718096]">Owner:</span> {drift.owner?.name || 'unassigned'}</p>
                        <p><span className="text-[#718096]">Issue:</span> {drift.issue ? <Link href={route('issues.show', drift.issue.id)} className="text-[#0A1F44] underline">{drift.issue.issue_code} ({drift.issue.status.replace(/_/g, ' ')})</Link> : 'none'}</p>
                    </div>
                    {can.edit && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 space-y-3">
                            <h3 className="text-sm font-semibold text-[#2D3748]">Work this drift</h3>
                            {drift.status === 'open' && (
                                <Action drift={drift} action="investigate" label="Start investigation" fields={['owner_id']} tone="outline">
                                    {(form) => <select value={form.data.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)} className="w-full rounded-lg border-gray-300 text-xs"><option value="">Owner: {drift.owner?.name || 'me'}</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>}
                                </Action>
                            )}
                            {open && changes.length === 0 && <p className="border-t border-gray-100 pt-3 text-[11px] text-[#718096]">No approved change lists this asset, so this drift cannot be authorised by a change.</p>}
                            {open && changes.length > 0 && (
                                <Action drift={drift} action="authorise" label="Link to an approved change" fields={['change_request_id']} tone="outline">
                                    {(form, err) => <><select value={form.data.change_request_id} onChange={(e) => form.setData('change_request_id', e.target.value)} className="w-full rounded-lg border-gray-300 text-xs"><option value="">Choose a change…</option>{changes.map((c) => <option key={c.id} value={c.id}>{c.change_code} · {c.title} ({c.status})</option>)}</select>{err('change_request_id')}</>}
                                </Action>
                            )}
                            {open && (
                                <Action drift={drift} action="remediate" label="Mark remediated" fields={['note']}>
                                    {(form, err) => <><textarea rows={2} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="What was restored to baseline, and how it was verified *" className="w-full rounded-lg border-gray-300 text-xs" />{err('note')}</>}
                                </Action>
                            )}
                            {open && can.approve && (
                                <Action drift={drift} action="accept" label="Accept the risk" fields={['note', 'accepted_until']} tone="outline">
                                    {(form, err) => (drift.owner_id === me
                                        ? <p className="text-[11px] text-[#718096]">You own this drift, so another approver must accept its risk.</p>
                                        : <><textarea rows={2} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="Why the deviation is tolerable, and compensating controls *" className="w-full rounded-lg border-gray-300 text-xs" />{err('note')}<label className="block text-[11px] text-[#718096]">Accepted until (max 12 months)<input type="date" value={form.data.accepted_until} onChange={(e) => form.setData('accepted_until', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" /></label>{err('accepted_until')}</>)}
                                </Action>
                            )}
                            {open && !(drift.issue && !['remediated', 'verified', 'closed'].includes(drift.issue.status)) && <Action drift={drift} action="raise_issue" label="Raise an issue" tone="outline" />}
                            {['remediated', 'accepted'].includes(drift.status) && <Action drift={drift} action="reopen" label="Reopen" tone="outline" />}
                        </div>
                    )}
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

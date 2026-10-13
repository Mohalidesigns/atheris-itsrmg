import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import InputError from '@/Components/InputError';
import { formatDate, humanize } from '@/Utils/risk';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const ROLES = ['owner', 'CISO', 'CRO', 'CCO', 'CIO'];
const ROLE_LABEL = { owner: 'Issue owner', CISO: 'CISO', CRO: 'Chief Risk Officer', CCO: 'Head of Compliance', CIO: 'Head of IT' };

function windowLabel(hours) {
    return hours >= 48 && hours % 24 === 0 ? `${hours / 24} days` : `${hours} h`;
}

function PolicyRow({ policy: p, canEdit }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ hours_to_remediate: p.hours_to_remediate, chain: p.chain });
    const toggle = (role) => form.setData('chain', form.data.chain.includes(role) ? form.data.chain.filter((r) => r !== role) : [...form.data.chain, role]);

    return (
        <tr className="align-top">
            <td className="px-4 py-3">
                <StatusBadge status={p.severity} label={humanize(p.severity)} />
                {p.issue_severity !== p.severity && <p className="mt-1 text-[10px] text-[#718096]">Issues rated "{p.issue_severity}"</p>}
            </td>
            <td className="px-4 py-3">
                {editing ? (
                    <div>
                        <input type="number" min="1" value={form.data.hours_to_remediate} onChange={(e) => form.setData('hours_to_remediate', e.target.value)}
                            className="w-28 rounded-lg border border-gray-200 px-2 py-1 text-sm" /> <span className="text-xs text-[#718096]">hours</span>
                        <InputError message={form.errors.hours_to_remediate} className="mt-1" />
                    </div>
                ) : (
                    <p className="font-semibold text-[#0A1F44]">{windowLabel(p.hours_to_remediate)}{p.is_default && <span className="ml-1 text-[10px] font-normal text-[#718096]">(default)</span>}</p>
                )}
            </td>
            <td className="px-4 py-3">
                {editing ? (
                    <div className="flex flex-wrap gap-1">
                        {ROLES.map((r) => (
                            <button key={r} type="button" disabled={r === 'owner'} onClick={() => toggle(r)}
                                className={`rounded-full border px-2 py-0.5 text-[11px] ${form.data.chain.includes(r) ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#4A5568]'}`}>
                                {ROLE_LABEL[r]}
                            </button>
                        ))}
                        <InputError message={form.errors.chain} className="mt-1 w-full" />
                    </div>
                ) : (
                    <p className="text-xs text-[#2D3748]">
                        {p.chain.map((r, idx) => (
                            <span key={r}>{idx > 0 && <span className="mx-1 text-gray-300">→</span>}<span className={idx === 0 ? '' : 'font-medium'}>{ROLE_LABEL[r] || r}</span>{idx > 0 && <span className="text-[10px] text-[#718096]"> (L{idx})</span>}</span>
                        ))}
                    </p>
                )}
            </td>
            <td className="px-4 py-3 text-xs">
                <Link href={route('issues.index', { status: 'active', severity: p.issue_severity })} className="text-[#0A1F44] underline">{p.open} open</Link>
                {p.overdue > 0 && <Link href={route('issues.index', { status: 'overdue', severity: p.issue_severity })} className="ml-2 font-semibold text-[#B3261E] underline">{p.overdue} past SLA</Link>}
            </td>
            <td className="px-4 py-3 text-right">
                {canEdit && (editing ? (
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => { form.reset(); setEditing(false); }} className="text-xs text-[#718096]">Cancel</button>
                        <button type="button" disabled={form.processing}
                            onClick={() => form.put(route('issues.sla-policies.update', p.severity), { preserveScroll: true, onSuccess: () => setEditing(false) })}
                            className="rounded-lg bg-[#0A1F44] px-3 py-1 text-xs text-white">Save</button>
                    </div>
                ) : (
                    <button type="button" onClick={() => setEditing(true)} className="text-xs text-[#0A1F44] underline">Edit</button>
                ))}
                {!editing && p.updated_at && <p className="mt-1 text-[10px] text-[#A0AEC0]">Updated {formatDate(p.updated_at)}</p>}
            </td>
        </tr>
    );
}

export default function SlaPolicies({ policies = [] }) {
    const { auth } = usePage().props;
    const canEdit = (auth?.user?.permissions || []).includes('approve issues') || (auth?.user?.roles || []).includes('Super Admin');

    return (
        <AuthenticatedLayout header="SLA Policies">
            <Head title="SLA Policies" />
            <PageHeader
                breadcrumbs={[{ label: 'Issues & Remediation', href: route('issues.index') }, { label: 'SLA Policies' }]}
                title="Remediation SLA Policies"
                subtitle="One policy per severity for this bank, shared by Issues and the vulnerability prioritiser. Recommended defaults: critical 72 h, high 14 days, medium 30 days, low 90 days."
            />
            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-4 py-2">Severity</th>
                            <th className="px-4 py-2">Remediate within</th>
                            <th className="px-4 py-2">Escalation chain</th>
                            <th className="px-4 py-2">Open issues</th>
                            <th className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {policies.map((p) => <PolicyRow key={p.severity} policy={p} canEdit={canEdit} />)}
                    </tbody>
                </table>
            </div>
            <div className="mt-4 rounded-xl border border-gray-100 bg-white p-4 text-xs text-[#4A5568] shadow-sm">
                <h3 className="mb-1 text-sm font-semibold text-[#2D3748]">How the clock works</h3>
                <ul className="list-disc space-y-1 pl-5">
                    <li>The clock starts when the issue is raised. Time spent blocked is added back when the issue is unblocked.</li>
                    <li>When the SLA is breached the issue escalates to level 1. At 150% of the window it escalates to level 2. Each level notifies the people with that job title in the bank, in-app and by email.</li>
                    <li>A policy change applies to issues raised afterwards. Changing an issue's severity re-bases its clock on the new policy.</li>
                    <li>SLA compliance on the dashboard is the share of issues remediated in the last 180 days that were fixed before their due time.</li>
                </ul>
            </div>
        </AuthenticatedLayout>
    );
}

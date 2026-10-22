import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import ContractsNav from '@/Components/Finance/Contracts/ContractsNav';
import DaysChip from '@/Components/Finance/Contracts/DaysChip';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, DECISION_LABELS, FIELD, LABEL, RENEWAL_TONES, formatPct, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ filters, renewals, summary, pendingWindow, options, baseCurrency, can }) {
    const [f, setF] = useState({ ...filters, q: filters.q ?? '' });
    const money = can.viewAmounts !== false;
    const apply = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '' && v !== false));
        router.get(route('finance.renewals.index'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };
    const set = (k) => (e) => apply({ ...f, [k]: e.target.value });
    const open = (contractId) => router.post(route('finance.renewals.open', contractId), {}, { preserveScroll: true });
    const bc = baseCurrency ?? 'NGN';

    return (
        <AuthenticatedLayout header="Renewals">
            <Head title="Renewals" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Renewals' }]} title="Renewals" subtitle="Renewal recommendations, approvals and outcomes" />
            <ContractsNav active="renewals" />

            <div className="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <StatCard label="Open" value={summary.open} sublabel="Being prepared" icon="edit_note" tint="bg-[#EAF0FB] text-[#1D4ED8]" onClick={() => apply({ ...f, status: 'open' })} />
                <StatCard label="Submitted" value={summary.submitted} sublabel="Awaiting approval" icon="hourglass_top" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => apply({ ...f, status: 'submitted' })} />
                <StatCard label="Approved" value={summary.approved} sublabel="Ready to execute" icon="task_alt" tint="bg-[#E7F4EC] text-[#2D7D46]" onClick={() => apply({ ...f, status: 'approved' })} />
                <StatCard label="Executed, 90 days" value={summary.executed_90d} sublabel="Completed recently" icon="autorenew" tint="bg-[#F7F1E6] text-[#8A6A2E]" onClick={() => apply({ ...f, status: 'executed' })} />
                <StatCard label="Rejected" value={summary.rejected} sublabel="Sent back" icon="block" tint="bg-[#F9DEDC] text-[#8C1D18]" onClick={() => apply({ ...f, status: 'rejected' })} />
            </div>

            {pendingWindow.length > 0 && (
                <Card title="Window open, no renewal yet" className="mb-4">
                    <Table head={['Contract', 'Title', 'Vendor', 'Owner', 'Notice deadline', '']}>
                        {pendingWindow.map((c) => (
                            <tr key={c.id}>
                                <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] underline" href={route('finance.contracts.show', c.id)}>{c.contract_code}</Link></td>
                                <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{c.title}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{c.vendor?.name ?? '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{c.owner?.name ?? '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(c.notice_deadline_on)} <DaysChip days={c.days_to_notice} /></td>
                                <td className="px-4 py-2 text-right">{can.edit !== false && <button type="button" onClick={() => open(c.id)} className="rounded-lg bg-[#0A1F44] px-3 py-1 text-xs text-white">Open renewal</button>}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}

            <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-5">
                <div className="col-span-2 lg:col-span-1"><label className={LABEL} htmlFor="r-q">Search</label><input id="r-q" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && apply(f)} onBlur={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="Renewal or contract" className={FIELD} /></div>
                <div><label className={LABEL} htmlFor="r-st">Status</label><select id="r-st" value={f.status ?? ''} onChange={set('status')} className={FIELD}><option value="">All</option>{options.statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></div>
                <div><label className={LABEL} htmlFor="r-rec">Recommendation</label><select id="r-rec" value={f.recommendation ?? ''} onChange={set('recommendation')} className={FIELD}><option value="">All</option>{options.decisions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></div>
                <label className="flex items-end gap-2 pb-2 text-sm text-[#2D3748]"><input type="checkbox" checked={!!f.mine} onChange={(e) => apply({ ...f, mine: e.target.checked })} /> Mine</label>
            </div>

            <Card>
                <Table head={['Renewal', 'Contract', 'Vendor', 'Recommendation', 'Proposed value', 'Uplift', 'Band', 'Status', 'Requester', 'Submitted']}
                    empty={renewals.length === 0 && <Empty>No renewals match these filters.</Empty>}>
                    {renewals.map((r) => (
                        <tr key={r.id} className="cursor-pointer hover:bg-gray-50" onClick={() => router.visit(route('finance.renewals.show', r.id))}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link href={route('finance.renewals.show', r.id)} onClick={(e) => e.stopPropagation()} className="text-[#0A1F44] underline">{r.renewal_code}</Link></td>
                            <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]"><span className="font-mono text-xs text-[#718096]">{r.contract.contract_code}</span> {r.contract.title}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{r.contract.vendor?.name ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{r.recommendation ? DECISION_LABELS[r.recommendation] ?? humanize(r.recommendation) : '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right text-xs tabular-nums" title={money && r.proposed_currency && r.proposed_currency !== bc ? `${moneyOrMask(r.proposed_base_value, bc, money)} base` : undefined}>{r.proposed_value === null && money ? '—' : moneyOrMask(r.proposed_value, r.proposed_currency ?? bc, money, { decimals: 0 })}</td>
                            <td className="px-4 py-2 text-right text-xs">{money ? formatPct(r.uplift_pct, 1) : '•••'}</td>
                            <td className="px-4 py-2">{money && r.amount_band_label && <Chip size="xs" tone="tertiary">{r.amount_band_label}</Chip>}</td>
                            <td className="px-4 py-2"><Chip size="xs" tone={RENEWAL_TONES[r.status] || 'neutral'}>{humanize(r.status)}</Chip></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{r.requested_by?.name ?? '—'}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{formatDate(r.submitted_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AuthenticatedLayout>
    );
}

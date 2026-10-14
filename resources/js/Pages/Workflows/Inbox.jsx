import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import Chip from '@/Components/Material/Chip';
import { Head, Link, router } from '@inertiajs/react';
import { fmtDateTime } from '@/Components/Monitoring/format';

export default function Inbox({ items = [], summary = {}, filters = {} }) {
    const go = (mine) => router.get(route('workflows.inbox'), mine ? { mine: 1 } : {}, { preserveState: true, replace: true });
    return (
        <AuthenticatedLayout header="Workflow Studio">
            <Head title="Approval Inbox" />
            <PageHeader
                breadcrumbs={[{ label: 'Workflow Studio' }, { label: 'Approval Inbox' }]}
                title="Approval Inbox"
                subtitle="Everything awaiting an approval decision across the platform, at its current stage. Open the record to decide."
            />
            <div className="mb-4 grid grid-cols-3 gap-3">
                <KpiCard label="Awaiting a decision" value={summary.pending ?? 0} tone="navy" />
                <KpiCard label="Awaiting me" value={summary.mine ?? 0} tone={summary.mine ? 'gold' : 'white'} />
                <KpiCard label="Past their SLA" value={summary.overdue ?? 0} tone={summary.overdue ? 'red' : 'white'} />
            </div>
            <div className="mb-3 flex gap-2 text-xs">
                {[['Everything', false], ['Awaiting me', true]].map(([label, mine]) => (
                    <button key={label} onClick={() => go(mine)} className={`rounded-lg border px-3 py-1.5 ${Boolean(filters.mine) === mine ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>{label}</button>
                ))}
            </div>
            <div className="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]"><tr className="text-left text-xs uppercase text-[#718096]"><th className="px-3 py-2">Record</th><th className="px-3 py-2">Process</th><th className="px-3 py-2">Current stage</th><th className="px-3 py-2">Due</th><th className="px-3 py-2"></th></tr></thead>
                    <tbody className="divide-y divide-gray-100">
                        {items.length === 0 && <tr><td colSpan={5} className="px-3 py-8 text-center text-xs text-[#718096]">Nothing is awaiting {filters.mine ? 'you' : 'a decision'}.</td></tr>}
                        {items.map((r) => (
                            <tr key={r.id} className={r.mine ? 'bg-[#FFFBEB]' : ''}>
                                <td className="px-3 py-2">{r.url ? <Link href={r.url} className="text-[#0A1F44] hover:underline">{r.subject}</Link> : r.subject}<p className="text-xs text-[#718096]">{r.initiator ? `started by ${r.initiator}, ` : ''}{fmtDateTime(r.started)}</p></td>
                                <td className="px-3 py-2 text-xs"><p className="text-[#2D3748]">{r.process_label}</p><p className="text-[#718096]">{r.module}</p></td>
                                <td className="px-3 py-2 text-xs"><p className="font-medium text-[#2D3748]">{r.step}{r.progress ? <span className="ml-1 font-normal text-[#A0AEC0]">({r.progress} done)</span> : null}</p><p className="text-[#718096]">{r.who}</p></td>
                                <td className="px-3 py-2 text-xs">{r.overdue ? <Chip tone="error" icon="schedule" size="xs">{fmtDateTime(r.due_at)}</Chip> : <span className="text-[#4A5568]">{fmtDateTime(r.due_at)}</span>}</td>
                                <td className="px-3 py-2">{r.mine && <Chip tone="warning" icon="how_to_reg" size="xs">Your decision</Chip>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

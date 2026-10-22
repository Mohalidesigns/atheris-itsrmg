import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ActualsTable from '@/Components/Finance/Expenses/ActualsTable';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import MatchActualModal from '@/Components/Finance/Expenses/MatchActualModal';
import { ReportBody } from '@/Components/Finance/Expenses/ReportView';
import StatusChip from '@/Components/Finance/Expenses/StatusChip';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { finMoney, humanize, localDate } from '@/Utils/finance';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

export default function Show(props) {
    const { batch, actuals, cloudSummary, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can.viewAmounts !== false;
    const [matching, setMatching] = useState(null);
    const report = batch.report ? { mode: 'apply', type: batch.type, file_name: batch.file_name, ...batch.report } : null;
    return (
        <AuthenticatedLayout header={`Import #${batch.id}`}>
            <Head title={`Import #${batch.id}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Imports', href: route('finance.imports.index') }, { label: `#${batch.id}` }]} title={`Import #${batch.id}`} subtitle={`${humanize(batch.type)} · ${batch.file_name}`} />
            <ExpensesNav active="imports" />
            <div className="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-lg border border-gray-200 bg-white p-4 text-sm">
                <StatusChip kind="batch" status={batch.status} size="sm" />
                <span>{batch.row_count} rows, {batch.accepted_count} accepted, {batch.rejected_count} rejected, {batch.duplicate_count} duplicates</span>
                {batch.type === 'actuals' && <span>{batch.matched_count} matched, {batch.unmatched_count} unmatched</span>}
                <span className="text-[#718096]">{batch.imported_by?.name} · {localDate(batch.created_at)}</span>
                {batch.type === 'actuals' && batch.unmatched_count > 0 && <Link className="text-[#0A1F44] underline" href={route('finance.actuals.index', { tab: 'queue', batch_id: batch.id })}>Open unmatched queue</Link>}
            </div>
            {report && <div className="mb-4"><ReportBody report={report} /></div>}
            {batch.type === 'cloud' && cloudSummary && (
                <Card title="Cloud cost lines from this batch" right={<Link className="text-xs text-[#0A1F44] underline" href={route('finance.cloud.costs')}>Open Cloud & FinOps</Link>}>
                    <dl className="grid grid-cols-2 gap-3 px-5 py-4 text-sm sm:grid-cols-4">
                        <div><dt className="text-xs text-[#718096]">Lines</dt><dd className="font-semibold tabular-nums">{cloudSummary.lines}</dd></div>
                        <div><dt className="text-xs text-[#718096]">Untagged lines</dt><dd className="font-semibold tabular-nums">{cloudSummary.untagged}</dd></div>
                        <div><dt className="text-xs text-[#718096]">From</dt><dd>{cloudSummary.dates?.from ? localDate(cloudSummary.dates.from) : '—'}</dd></div>
                        <div><dt className="text-xs text-[#718096]">To</dt><dd>{cloudSummary.dates?.to ? localDate(cloudSummary.dates.to) : '—'}</dd></div>
                    </dl>
                    <Table head={['Service', 'Cost']} empty={(cloudSummary.by_service ?? []).length === 0 && <Empty>No lines.</Empty>}>
                        {(cloudSummary.by_service ?? []).map((r) => <tr key={r.service}><td className="px-4 py-2 text-[#2D3748]">{r.service}</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{finMoney(r.cost, baseCurrency, money, { decimals: 0 })}</td></tr>)}
                    </Table>
                </Card>
            )}
            {batch.type === 'actuals' && <Card title="Actuals from this batch"><ActualsTable actuals={actuals} baseCurrency={baseCurrency} money={money} canMatch={can.match} onAction={(a, k) => k === 'match' && setMatching(a)} /></Card>}
            {matching && <MatchActualModal actual={matching} budgetLines={[]} baseCurrency={baseCurrency} money={money} errors={errors ?? {}} onClose={() => setMatching(null)} />}
        </AuthenticatedLayout>
    );
}

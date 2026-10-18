import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ReportCard from '@/Components/Finance/Monitor/ReportCard';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import Chip from '@/Components/Material/Chip';
import { humanize, localDate } from '@/Utils/finance';
import { Head, Link } from '@inertiajs/react';

const GROUPS = [['budget', 'Budget'], ['spend', 'Spend'], ['contracts', 'Contracts'], ['vendors', 'Vendors']];

export default function Index(props) {
    const { reports, filters, fiscalYears, periods, costCentres, schedules = [], preview, can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const known = new Set(GROUPS.map(([k]) => k));
    const groups = [...GROUPS, ...[...new Set(reports.map((r) => r.group).filter((g) => g && !known.has(String(g).toLowerCase())))].map((g) => [g, humanize(g)])];

    return (
        <AuthenticatedLayout header="IT Finance Reports">
            <Head title="IT Finance Reports" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Reports' }]} title="IT Finance Reports"
                subtitle={`Preview a report, then download it as Excel, CSV or PDF. Figures are in ${baseCurrency}.`} />
            {!can?.export && <p className="mb-4 rounded-lg bg-[#FFF6E0] px-3 py-2 text-xs text-[#8A6400]">You can preview reports but downloading needs the export permission.</p>}
            {!money && <p className="mb-4 rounded-lg bg-[#F1F2F4] px-3 py-2 text-xs text-[#43474E]">Amounts are blank in previews and downloads for your role.</p>}

            {groups.map(([key, label]) => {
                const items = reports.filter((r) => String(r.group).toLowerCase() === String(key).toLowerCase());
                if (items.length === 0) return null;
                return (
                    <div key={key} className="mb-6">
                        <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-[#718096]">{label}</h3>
                        <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                            {items.map((r) => (
                                <ReportCard key={r.key} report={r} active={filters.report === r.key} initial={filters} fiscalYears={fiscalYears} periods={periods} costCentres={costCentres}
                                    preview={preview?.key === r.key ? preview : null} baseCurrency={baseCurrency} money={money} canExport={can?.export} />
                            ))}
                        </div>
                    </div>
                );
            })}

            <Card title="Scheduled reports" right={<Link href={route('reports.scheduled')} className="text-xs text-[#0A1F44] underline">Manage schedules</Link>}>
                {schedules.length === 0 ? <Empty>No finance reports are scheduled.{can?.schedule ? ' Create one under Scheduled reports.' : ''}</Empty> : (
                    <ul className="divide-y divide-gray-50">
                        {schedules.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center gap-3 px-5 py-2.5 text-sm">
                                <span className="font-medium text-[#2D3748]">{s.name}</span>
                                <span className="text-xs text-[#718096]">{humanize(s.report_type)} · {humanize(s.frequency)}{s.next_run_at ? ` · next ${localDate(s.next_run_at)}` : ''}</span>
                                <span className="ml-auto"><Chip size="xs" tone={s.active ? 'success' : 'muted'}>{s.active ? 'Active' : 'Paused'}</Chip></span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}

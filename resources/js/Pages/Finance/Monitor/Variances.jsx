import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import ExplainModal from '@/Components/Finance/Monitor/ExplainModal';
import FiltersBar, { ChipFilter } from '@/Components/Finance/Monitor/FiltersBar';
import KpiTile from '@/Components/Finance/Monitor/KpiTile';
import MonitorNav from '@/Components/Finance/Monitor/MonitorNav';
import RaiseIssueModal from '@/Components/Finance/Monitor/RaiseIssueModal';
import VarianceCard from '@/Components/Finance/Monitor/VarianceCard';
import { SelectFilter, Toggle } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { humanize } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Variances(props) {
    const { filters, fiscalYears, periods, costCentres, statuses = [], summary, notes, can } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [f, setF] = useState({ ...filters });
    const [explaining, setExplaining] = useState(null);
    const [raising, setRaising] = useState(null);
    const go = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== false));
        router.get(route('finance.monitor.variances'), clean, { preserveScroll: true, preserveState: true, replace: true });
    };
    const set = (k) => (e) => go({ ...f, [k]: e.target.value, ...(k === 'fiscal_year_id' ? { period_id: '' } : {}) });
    const rows = notes?.data ?? [];
    const links = Array.isArray(notes?.meta?.links) ? notes.meta.links : Array.isArray(notes?.links) ? notes.links : null;

    return (
        <AuthenticatedLayout header="Variances">
            <Head title="Variances" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budget Monitor', href: route('finance.monitor.bva') }, { label: 'Variances' }]} title="Variances"
                subtitle="Year-to-date overspends and underspends past the thresholds, with the explanation owed before a period closes" />
            <MonitorNav active="variances" params={{ fiscal_year_id: filters.fiscal_year_id }} />

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
                <KpiTile label="Red" value={summary.red} icon="error" tint="bg-[#F9DEDC] text-[#8C1D18]" alert={summary.red > 0} />
                <KpiTile label="Amber" value={summary.amber} icon="warning" tint="bg-[#FFF6E0] text-[#8A6400]" />
                <KpiTile label="Red, unexplained" value={summary.unexplained_red} icon="edit_note" tint="bg-[#F9DEDC] text-[#8C1D18]" alert={summary.unexplained_red > 0} sub="Blocks the close" />
                <KpiTile label="Explained" value={summary.explained} icon="task" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <KpiTile label="Acknowledged" value={summary.acknowledged} icon="verified" tint="bg-[#E7F4EC] text-[#2D7D46]" />
            </div>

            <FiltersBar cols="lg:grid-cols-4">
                <SelectFilter id="v-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="v-pe" label="Period" value={f.period_id} onChange={set('period_id')} all="All periods">{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>
                <SelectFilter id="v-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
                <SelectFilter id="v-st" label="Status" value={f.status} onChange={set('status')}>{statuses.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</SelectFilter>
                <div className="col-span-2 flex flex-wrap items-center gap-4 lg:col-span-4">
                    <ChipFilter label="Severity" value={f.severity} onChange={(v) => go({ ...f, severity: v })} options={[['red', 'Red'], ['amber', 'Amber']]} />
                    <Toggle label="Needs explanation" checked={!!f.needs_explanation} onChange={(v) => go({ ...f, needs_explanation: v ? 1 : '' })} />
                </div>
            </FiltersBar>

            {rows.length === 0 ? (
                <p className="rounded-lg border border-gray-200 bg-white px-5 py-10 text-center text-sm text-[#718096]">No variances match these filters.</p>
            ) : (
                <div className="space-y-3">{rows.map((n) => <VarianceCard key={n.id} note={n} baseCurrency={baseCurrency} money={money} onExplain={setExplaining} onRaiseIssue={setRaising} />)}</div>
            )}
            <Pagination links={links} />
            <ExplainModal note={explaining} onClose={() => setExplaining(null)} />
            <RaiseIssueModal note={raising} onClose={() => setRaising(null)} />
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import BatchesTable from '@/Components/Finance/Expenses/BatchesTable';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import useFilters from '@/Components/Finance/Expenses/useFilters';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY } from '@/Utils/finance';
import { Head, Link } from '@inertiajs/react';

export default function Index({ filters, batches, options, can }) {
    const { f, set } = useFilters('finance.imports.index', filters);
    return (
        <AuthenticatedLayout header="Imports">
            <Head title="Imports" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Imports' }]} title="Imports" subtitle="History of GL, expense and invoice CSV imports with their reports"
                actions={can.import && <Link href={route('finance.actuals.index', { tab: 'posted' })} className={BTN_PRIMARY}>New GL import</Link>} />
            <ExpensesNav active="imports" />
            <div className="mb-4 max-w-xs rounded-lg border border-gray-200 bg-white p-4"><SelectFilter id="im-t" label="Type" value={f.type} onChange={set('type')}>{options.types.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter></div>
            <Card><BatchesTable batches={batches} showType /></Card>
        </AuthenticatedLayout>
    );
}

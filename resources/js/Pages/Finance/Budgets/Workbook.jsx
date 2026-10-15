import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import CsvImportForm from '@/Components/Finance/CsvImportForm';
import ImportReport from '@/Components/Finance/ImportReport';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import CeilingBar from '@/Components/Finance/Budgets/CeilingBar';
import WorkbookGrid from '@/Components/Finance/Budgets/WorkbookGrid';
import { InformationCircleIcon } from '@/Components/Material/Symbols';
import { BTN_PRIMARY, BTN_SECONDARY, BUDGET_TONES, humanize } from '@/Utils/finance';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function Workbook({ budget, lines, periods, ceiling, availableDimensions, lockedReason, can, baseCurrency }) {
    const { errors } = usePage().props;
    const readOnly = !!lockedReason || !can.edit;
    return (
        <AuthenticatedLayout header="Budget workbook">
            <Head title="Budget workbook" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: budget.budget_code, href: route('finance.budgets.show', budget.id) }, { label: 'Workbook' }]}
                title="Budget workbook" subtitle={`${budget.budget_code} ${budget.version_label} · ${budget.title}`}
                actions={<>
                    <Chip tone={BUDGET_TONES[budget.status] || 'neutral'}>{humanize(budget.status)}</Chip>
                    <Link href={route('finance.budgets.show', budget.id)} className={BTN_SECONDARY}>Back to budget</Link>
                    {can.export && <a href={route('finance.budgets.workbook', { budget: budget.id, format: 'csv' })} className={BTN_SECONDARY}>Export CSV</a>}
                </>} />
            <BudgetsNav active="budgets" />
            {lockedReason && (
                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-[#DFE6FB] bg-[#EEF2FD] px-4 py-3 text-sm text-[#1E3A8A]">
                    <InformationCircleIcon className="h-5 w-5 shrink-0" />
                    <span className="flex-1">{lockedReason}</span>
                    {can.revise && <button type="button" className={BTN_PRIMARY} onClick={() => router.post(route('finance.budgets.revise', budget.id))}>Create revision</button>}
                </div>
            )}
            <ImportReport type="budget_workbook" />
            <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4"><CeilingBar ceiling={ceiling} baseCurrency={baseCurrency} /></div>
            <WorkbookGrid budget={budget} lines={lines} periods={periods} dims={availableDimensions} baseCurrency={baseCurrency} readOnly={readOnly} errors={errors} />
            {can.import && !readOnly && (
                <div className="mt-4">
                    <CsvImportForm routeName="finance.budgets.workbook.import" params={budget.id} label="Import workbook (CSV)"
                        hint="Export the workbook CSV for the exact headers: line_no, description, cost_pool, tower, ... and one P01..Pnn column per period. Only description is required." />
                </div>
            )}
        </AuthenticatedLayout>
    );
}

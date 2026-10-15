import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ApprovalTrail from '@/Components/Approvals/ApprovalTrail';
import ActivityList from '@/Components/Finance/Budgets/ActivityList';
import BandSnapshot from '@/Components/Finance/Budgets/BandSnapshot';
import BudgetActions from '@/Components/Finance/Budgets/BudgetActions';
import BudgetHeader from '@/Components/Finance/Budgets/BudgetHeader';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import CommentsThread from '@/Components/Finance/Budgets/CommentsThread';
import LineFormModal from '@/Components/Finance/Budgets/LineFormModal';
import LinesTable from '@/Components/Finance/Budgets/LinesTable';
import NarrativeForm from '@/Components/Finance/Budgets/NarrativeForm';
import PhasingPanel from '@/Components/Finance/Budgets/PhasingPanel';
import ScenariosPanel from '@/Components/Finance/Budgets/ScenariosPanel';
import VersionsDiff from '@/Components/Finance/Budgets/VersionsDiff';
import { AddButton, Card } from '@/Components/Finance/Settings/ui';
import { InformationCircleIcon } from '@/Components/Material/Symbols';
import { humanize } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS = ['lines', 'phasing', 'scenarios', 'approvals', 'versions', 'narrative', 'activity'];

export default function Show(props) {
    const { budget, lines, periods, totals, ceiling, scenarios, versions, approval, comments, activity, availableDimensions, can, baseCurrency, errors } = props;
    const [tab, setTab] = useState(TABS.includes(props.tab) ? props.tab : 'lines');
    const [modal, setModal] = useState(null); // null | 'new' | line
    const select = (key) => {
        setTab(key);
        try { window.history.replaceState(null, '', route('finance.budgets.show', { budget: budget.id, tab: key })); } catch { /* ignore */ }
    };
    const removeLine = (l) => confirm(`Delete line ${l.line_no}?`) && router.delete(route('finance.budgets.lines.destroy', { budget: budget.id, line: l.id }), { preserveScroll: true });

    return (
        <AuthenticatedLayout header={budget.budget_code}>
            <Head title={`Budget ${budget.budget_code}`} />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: budget.budget_code }]}
                title={`${budget.budget_code} ${budget.version_label}`} subtitle={budget.title} />
            <BudgetsNav active="budgets" />
            {budget.is_locked && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-[#DFE6FB] bg-[#EEF2FD] px-4 py-3 text-sm text-[#1E3A8A]">
                    <InformationCircleIcon className="mt-0.5 h-5 w-5 shrink-0" />
                    <span>Version {budget.version_no} is {humanize(budget.status).toLowerCase()} and immutable. Editing creates version {budget.version_no + 1}.</span>
                </div>
            )}
            <BudgetHeader budget={budget} totals={totals} ceiling={ceiling} baseCurrency={baseCurrency} />
            <BudgetActions budget={budget} can={can} lines={lines} errors={errors} />

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200" role="tablist">
                {TABS.map((k) => (
                    <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => select(k)}
                        className={`border-b-2 px-4 py-2 text-sm ${tab === k ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096]'}`}>{humanize(k)}</button>
                ))}
            </div>

            {tab === 'lines' && (
                <Card title="Lines" right={can.edit && <AddButton onClick={() => setModal('new')}>Add line</AddButton>}>
                    <LinesTable lines={lines} totals={totals} baseCurrency={baseCurrency} can={can} isBaseline={budget.status === 'baseline'} onEdit={setModal} onDelete={removeLine} />
                </Card>
            )}
            {tab === 'phasing' && <PhasingPanel budget={budget} lines={lines} periods={periods} methods={availableDimensions.phasingMethods} can={can} errors={errors} />}
            {tab === 'scenarios' && <ScenariosPanel budget={budget} scenarios={scenarios} lines={lines} totals={totals} baseCurrency={baseCurrency} can={can} />}
            {tab === 'approvals' && (
                <div className="space-y-4">
                    <ApprovalTrail process={approval.process} id={budget.id} version={budget.status} />
                    <BandSnapshot context={approval.context} baseCurrency={baseCurrency} />
                    <CommentsThread budget={budget} comments={comments} lines={lines} can={can} />
                </div>
            )}
            {tab === 'versions' && <VersionsDiff budget={budget} versions={versions} periods={periods} baseCurrency={baseCurrency} />}
            {tab === 'narrative' && <NarrativeForm key={budget.id} budget={budget} can={can} />}
            {tab === 'activity' && <ActivityList activity={activity} />}

            <LineFormModal show={modal !== null} onClose={() => setModal(null)} budget={budget} line={modal === 'new' ? null : modal} dims={availableDimensions} baseCurrency={baseCurrency} />
        </AuthenticatedLayout>
    );
}

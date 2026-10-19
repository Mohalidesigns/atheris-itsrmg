import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CategoriesTab from '@/Components/Finance/Settings/CategoriesTab';
import CostCentresTab from '@/Components/Finance/Settings/CostCentresTab';
import CurrenciesTab from '@/Components/Finance/Settings/CurrenciesTab';
import DoaBandsTab from '@/Components/Finance/Settings/DoaBandsTab';
import FinOpsTab from '@/Components/Finance/Settings/FinOpsTab';
import DriversTab from '@/Components/Finance/Settings/DriversTab';
import FiscalCalendarTab from '@/Components/Finance/Settings/FiscalCalendarTab';
import GeneralTab from '@/Components/Finance/Settings/GeneralTab';
import GlAccountsTab from '@/Components/Finance/Settings/GlAccountsTab';
import InvestmentPolicyTab from '@/Components/Finance/Settings/InvestmentPolicyTab';
import RenewalPolicyTab from '@/Components/Finance/Settings/RenewalPolicyTab';
import TaxesTab from '@/Components/Finance/Settings/TaxesTab';
import VarianceTab from '@/Components/Finance/Settings/VarianceTab';
import { InformationCircleIcon } from '@/Components/Material/Symbols';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const TABS = [
    ['general', 'General'],
    ['calendar', 'Fiscal years & periods'],
    ['cost-centres', 'Cost centres'],
    ['categories', 'Categories (TBM)'],
    ['gl-accounts', 'GL accounts'],
    ['currencies', 'Currencies & FX'],
    ['taxes', 'Taxes'],
    ['drivers', 'Drivers'],
    ['doa', 'DoA bands'],
    ['variance', 'Variance thresholds'],
    ['renewal', 'Renewal policy'],
    ['investments', 'Investment policy'],
    ['finops', 'FinOps & showback'],
];

export default function Settings(props) {
    const { tab: initialTab, can } = props;
    const [tab, setTab] = useState(TABS.some(([k]) => k === initialTab) ? initialTab : 'general');

    const select = (key) => {
        setTab(key);
        try { window.history.replaceState(null, '', route('finance.settings', { tab: key })); } catch { /* ignore */ }
    };

    return (
        <AuthenticatedLayout header="IT Financial Management">
            <Head title="Finance Settings" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Settings' }]}
                title="Finance Settings"
                subtitle="Fiscal calendar, cost model, currencies, taxes and approval thresholds (COBIT APO06.01)"
            />

            {!can.manage && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-[#DFE6FB] bg-[#EEF2FD] px-4 py-3 text-sm text-[#1E3A8A]">
                    <InformationCircleIcon className="mt-0.5 h-5 w-5 shrink-0" />
                    <span>Read-only. Changing finance settings needs the manage it-finance-settings permission</span>
                </div>
            )}

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200" role="tablist">
                {TABS.map(([key, label]) => (
                    <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => select(key)}
                        className={`border-b-2 px-4 py-2 text-sm ${tab === key ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096]'}`}>
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'general' && <GeneralTab settings={props.settings} options={props.options} baseCurrency={props.baseCurrency} can={can} />}
            {tab === 'calendar' && <FiscalCalendarTab fiscalYears={props.fiscalYears} options={props.options} can={can} />}
            {tab === 'cost-centres' && <CostCentresTab costCentres={props.costCentres} users={props.users} legalEntities={props.legalEntities} can={can} />}
            {tab === 'categories' && <CategoriesTab categories={props.categories} glAccounts={props.glAccounts} can={can} />}
            {tab === 'gl-accounts' && <GlAccountsTab glAccounts={props.glAccounts} options={props.options} can={can} />}
            {tab === 'currencies' && <CurrenciesTab currencies={props.currencies} fxRates={props.fxRates} latestRates={props.latestRates} options={props.options} baseLockedReason={props.baseLockedReason} can={can} />}
            {tab === 'taxes' && <TaxesTab taxTypes={props.taxTypes} options={props.options} can={can} />}
            {tab === 'drivers' && <DriversTab drivers={props.drivers} fiscalYears={props.fiscalYears} can={can} />}
            {tab === 'doa' && <DoaBandsTab settings={props.settings} doaVersions={props.doaVersions} baseCurrency={props.baseCurrency} can={can} />}
            {tab === 'variance' && <VarianceTab settings={props.settings} baseCurrency={props.baseCurrency} can={can} />}
            {tab === 'investments' && <InvestmentPolicyTab policy={props.investmentPolicy} options={props.options} can={can} />}
            {tab === 'finops' && <FinOpsTab policy={props.finopsPolicy ?? {}} baseCurrency={props.baseCurrency} can={can} />}
            {tab === 'renewal' && <RenewalPolicyTab settings={props.settings} jobTitles={props.jobTitles} can={can} />}
        </AuthenticatedLayout>
    );
}

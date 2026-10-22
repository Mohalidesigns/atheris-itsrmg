import Chip from '@/Components/Material/Chip';
import CeilingBar from './CeilingBar';
import Money from './Money';
import { BUDGET_TONES, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Link } from '@inertiajs/react';

export function Fact({ label, children }) {
    return <div><dt className="text-xs text-[#718096]">{label}</dt><dd className="text-sm text-[#2D3748]">{children || '—'}</dd></div>;
}

export default function BudgetHeader({ budget, totals, ceiling, baseCurrency }) {
    return (
        <div className="mb-4 rounded-lg border border-gray-200 bg-white p-5">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-sm text-[#4A5568]">{budget.budget_code} {budget.version_label}</span>
                <Chip tone={BUDGET_TONES[budget.status] || 'neutral'}>{humanize(budget.status)}</Chip>
                {budget.amount_band_label && <Chip tone="tertiary" title="Delegation of authority band">{budget.amount_band_label}</Chip>}
                {budget.is_locked && <Chip tone="neutral" icon="lock">Locked</Chip>}
            </div>
            <h3 className="mt-1 text-base font-semibold text-[#0A1F44]">{budget.title}</h3>
            <div className="mt-4 grid gap-6 lg:grid-cols-3">
                <dl className="grid grid-cols-2 gap-3 lg:col-span-2 sm:grid-cols-3">
                    <Fact label="Cost centre">{budget.cost_centre && `${budget.cost_centre.code} ${budget.cost_centre.name}`}</Fact>
                    <Fact label="Cycle">{budget.cycle && `${budget.cycle.cycle_code} · ${budget.fiscal_year?.year_label ?? ''}`}</Fact>
                    <Fact label="Owner">{budget.owner?.name}</Fact>
                    <Fact label="CapEx"><Money value={totals.capex} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} /></Fact>
                    <Fact label="OpEx"><Money value={totals.opex} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} /></Fact>
                    <Fact label="Total (base)"><span className="font-semibold"><Money value={totals.base} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} /></span></Fact>
                    <Fact label="Submitted">{budget.submitted_at && formatDate(budget.submitted_at)}</Fact>
                    <Fact label="Approved">{budget.approved_at && formatDate(budget.approved_at)}</Fact>
                    <Fact label="Baselined">{budget.baselined_at && formatDate(budget.baselined_at)}</Fact>
                </dl>
                <div><p className="mb-2 text-xs font-medium text-[#4A5568]">Against ceilings</p><CeilingBar ceiling={ceiling} baseCurrency={baseCurrency} /></div>
            </div>
            {budget.superseded_by && (
                <p className="mt-3 text-xs text-[#718096]">Superseded by <Link className="underline" href={route('finance.budgets.show', budget.superseded_by.id)}>v{budget.superseded_by.version_no}</Link>.</p>
            )}
            {budget.rejection_reason && <p className="mt-3 rounded-lg bg-[#FDF4F3] px-3 py-2 text-sm text-[#8C1D18]">Returned: {budget.rejection_reason}</p>}
        </div>
    );
}

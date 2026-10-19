import { Link } from '@inertiajs/react';

const TABS = [
    ['budgets', 'Budgets', 'finance.budgets.index'],
    ['cycles', 'Cycles', 'finance.cycles.index'],
    ['targets', 'Targets', 'finance.targets.index'],
    ['transfers', 'Transfers', 'finance.transfers.index'],
    ['supplementary', 'Supplementary', 'finance.supplementary.index'],
    ['allocations', 'Allocations', 'finance.allocations.index'],
];

/** Section tab bar shared by every budgeting page. */
export default function BudgetsNav({ active }) {
    return (
        <nav className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200" aria-label="Budgeting sections">
            {TABS.map(([key, label, name]) => (
                <Link key={key} href={route(name)} aria-current={active === key ? 'page' : undefined}
                    className={`border-b-2 px-4 py-2 text-sm ${active === key ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>
                    {label}
                </Link>
            ))}
        </nav>
    );
}

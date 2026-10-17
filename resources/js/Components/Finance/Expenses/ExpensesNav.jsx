import { Link } from '@inertiajs/react';

const TABS = [
    ['expenses', 'Expenses', 'finance.expenses.index'],
    ['purchase-orders', 'Purchase orders', 'finance.purchase-orders.index'],
    ['invoices', 'Invoices', 'finance.invoices.index'],
    ['actuals', 'Actuals', 'finance.actuals.index'],
    ['recurring', 'Recurring', 'finance.recurring.index'],
    ['accruals', 'Accruals', 'finance.accruals.index'],
    ['imports', 'Imports', 'finance.imports.index'],
];

/** Section tab bar shared by the P3 spend pages. */
export default function ExpensesNav({ active }) {
    return (
        <nav className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" aria-label="Spend sections">
            {TABS.map(([key, label, name]) => (
                <Link key={key} href={route(name)} aria-current={active === key ? 'page' : undefined}
                    className={`border-b-2 px-4 py-2 text-sm ${active === key ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>
                    {label}
                </Link>
            ))}
        </nav>
    );
}

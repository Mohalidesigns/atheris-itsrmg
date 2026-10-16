import { Link } from '@inertiajs/react';

const TABS = [
    ['contracts', 'Contracts', 'finance.contracts.index'],
    ['renewals', 'Renewals', 'finance.renewals.index'],
    ['calendar', 'Calendar', 'finance.calendar'],
];

/** Section tab bar shared by the contract, renewal and calendar pages. */
export default function ContractsNav({ active }) {
    return (
        <nav className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" aria-label="Contract sections">
            {TABS.map(([key, label, name]) => (
                <Link key={key} href={route(name)} aria-current={active === key ? 'page' : undefined}
                    className={`border-b-2 px-4 py-2 text-sm ${active === key ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>
                    {label}
                </Link>
            ))}
        </nav>
    );
}

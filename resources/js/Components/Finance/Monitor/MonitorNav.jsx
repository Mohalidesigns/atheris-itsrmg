import { Link } from '@inertiajs/react';

const TABS = [
    ['bva', 'Budget vs actual', 'finance.monitor.bva'],
    ['variances', 'Variances', 'finance.monitor.variances'],
    ['forecast', 'Forecast', 'finance.monitor.forecast'],
    ['period-close', 'Period close', 'finance.monitor.period-close'],
    ['showback', 'Showback', 'finance.monitor.showback'],
    ['tco', 'Application TCO', 'finance.monitor.tco'],
];

/** Section tab bar shared by the P4 monitoring pages. `params` (e.g. fiscal_year_id) carries across tabs. */
export default function MonitorNav({ active, params = {} }) {
    const query = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));
    return (
        <nav className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" aria-label="Budget monitor sections">
            {TABS.map(([key, label, name]) => (
                <Link key={key} href={route(name, query)} aria-current={active === key ? 'page' : undefined}
                    className={`border-b-2 px-4 py-2 text-sm ${active === key ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>
                    {label}
                </Link>
            ))}
        </nav>
    );
}

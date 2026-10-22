import { Link } from '@inertiajs/react';

const TABS = [
    ['costs', 'Costs', () => route('finance.cloud.costs')],
    ['untagged', 'Untagged', () => route('finance.cloud.costs', { tab: 'untagged' })],
    ['anomalies', 'Anomalies', () => route('finance.cloud.anomalies.index')],
    ['accounts', 'Accounts', () => route('finance.cloud.accounts.index')],
];

/** Section tab bar shared by the cloud cost / FinOps pages. */
export default function CloudNav({ active }) {
    return (
        <nav className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200 print:hidden" aria-label="Cloud and FinOps sections">
            {TABS.map(([key, label, href]) => (
                <Link key={key} href={href()} aria-current={active === key ? 'page' : undefined}
                    className={`border-b-2 px-4 py-2 text-sm ${active === key ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>
                    {label}
                </Link>
            ))}
        </nav>
    );
}

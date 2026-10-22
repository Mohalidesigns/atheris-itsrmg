import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { finMoney, localDate } from '@/Utils/finance';
import { Link } from '@inertiajs/react';

/** Capex eligible for capitalisation: pool, already capitalised, remaining, and the contributing actuals. */
export default function EligibilityCard({ eligibility, baseCurrency, money }) {
    if (!eligibility) return null;
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    return (
        <Card title="Eligibility">
            <dl className="grid grid-cols-3 gap-3 px-5 py-4 text-sm">
                <div><dt className="text-[11px] text-[#718096]">Eligible pool</dt><dd className="font-semibold tabular-nums">{fmt(eligibility.pool)}</dd></div>
                <div><dt className="text-[11px] text-[#718096]">Already capitalised</dt><dd className="font-semibold tabular-nums">{fmt(eligibility.already)}</dd></div>
                <div><dt className="text-[11px] text-[#718096]">Remaining</dt><dd className="font-semibold tabular-nums text-[#1B5530]">{fmt(eligibility.remaining)}</dd></div>
            </dl>
            <p className="px-5 pb-2 text-[11px] text-[#718096]">Only capex actuals posted on or before the available-for-use date count (IAS 38.30); later cost is expensed.</p>
            <Table head={['Posted', 'Description', 'Amount']} empty={eligibility.actuals.length === 0 && <Empty>No capex actuals contribute.</Empty>}>
                {eligibility.actuals.map((a) => (
                    <tr key={a.id}>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(a.posted_on)}</td>
                        <td className="px-4 py-2 text-xs text-[#2D3748]">{a.href ? <Link className="hover:underline" href={a.href}>{a.description}</Link> : a.description}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(a.base_amount)}</td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}

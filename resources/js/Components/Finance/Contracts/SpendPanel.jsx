import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { MASK, formatMoney, humanize, localDate, moneyOrMask } from '@/Utils/finance';
import { Link } from '@inertiajs/react';

function Stat({ label, children }) {
    return <div><dt className="text-xs text-[#718096]">{label}</dt><dd className="text-sm font-medium tabular-nums text-[#2D3748]">{children}</dd></div>;
}

export default function SpendPanel({ contract, spend, baseCurrency, can }) {
    const money = can.viewAmounts !== false;
    const m = (v, cur = baseCurrency) => moneyOrMask(v, cur, money);
    return (
        <div className="space-y-4">
            <Card title="Contract value">
                <dl className="grid grid-cols-2 gap-4 px-5 py-4 sm:grid-cols-4">
                    <Stat label={`Total (${contract.currency})`}>{m(spend.contract_value, contract.currency)}</Stat>
                    <Stat label={`Total (${baseCurrency})`}>{m(spend.base_contract_value)}</Stat>
                    <Stat label="Annualised (base)">{m(spend.annualised_base_value)}</Stat>
                    <Stat label="Next-term forecast (base)">{m(spend.next_term_forecast)}{contract.price_escalation_pct && <span className="block text-[11px] font-normal text-[#718096]">after {Number(contract.price_escalation_pct)}% escalation</span>}</Stat>
                </dl>
            </Card>
            <Card title="Billing schedule">
                <Table head={['Due on', `Amount (${contract.currency})`, `Base (${baseCurrency})`]} empty={(!money || spend.billing.length === 0) && <Empty>{money ? `No upcoming billing dates (${humanize(contract.billing_frequency)}).` : 'Amounts are hidden for your role.'}</Empty>}>
                    {spend.billing.map((b) => (
                        <tr key={b.due_on}><td className="px-4 py-2 text-xs">{localDate(b.due_on)}</td><td className="px-4 py-2 text-right tabular-nums">{formatMoney(b.amount, contract.currency)}</td><td className="px-4 py-2 text-right tabular-nums">{formatMoney(b.base_amount, baseCurrency)}</td></tr>
                    ))}
                </Table>
            </Card>
            <Card title="Budget">
                {spend.budget_line ? (
                    <dl className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                        <Stat label="Budget line"><Link className="text-[#0A1F44] underline" href={route('finance.budgets.show', { budget: spend.budget_line.budget_id, tab: 'lines' })}>{spend.budget_line.label}</Link></Stat>
                        <Stat label="Current base amount">{spend.budget_line.current_base_amount === null ? MASK : m(spend.budget_line.current_base_amount)}</Stat>
                    </dl>
                ) : <Empty>This contract is not linked to a budget line. Edit it to attach one.</Empty>}
            </Card>
            <p className="flex items-center gap-2 rounded-lg border border-dashed border-gray-200 px-4 py-3 text-xs text-[#718096]">Posted spend and invoices for this contract appear under Invoices and Actuals.</p>
        </div>
    );
}

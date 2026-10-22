import { Card, Table } from '@/Components/Finance/Settings/ui';
import { invMoney } from '@/Utils/finance';

/** Investment budget by EA capability (hidden when no investment is linked to a capability). */
export default function CapabilitySpend({ capabilities, baseCurrency, money }) {
    if (!capabilities || capabilities.length === 0) return null;
    return (
        <Card title="Spend by capability">
            <Table head={['Capability', 'Investments', 'BAC']}>
                {capabilities.map((c) => (
                    <tr key={c.id}><td className="px-4 py-2 text-[#2D3748]">{c.name}</td><td className="px-4 py-2 text-right tabular-nums">{c.investments}</td><td className="px-4 py-2 text-right tabular-nums">{invMoney(c.bac, baseCurrency, money, { decimals: 0 })}</td></tr>
                ))}
            </Table>
        </Card>
    );
}

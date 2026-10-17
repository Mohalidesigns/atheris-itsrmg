import Amount from './Amount';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { localDate } from '@/Utils/finance';

const q = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 });

/** PO lines with ordered / received / invoiced quantities, plus goods-receipt history. */
export function PoLines({ po, money }) {
    return (
        <Card title="Order lines">
            <Table head={['#', 'Description', 'Ordered', 'Received', 'Invoiced', 'Remaining', 'Unit cost', 'Amount']} empty={po.lines.length === 0 && <Empty>No lines.</Empty>}>
                {po.lines.map((l) => (
                    <tr key={l.id}>
                        <td className="px-4 py-2 text-xs">{l.line_no}</td>
                        <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">{l.description}{l.category && <span className="block text-xs text-[#718096]">{l.category.name ?? l.category}</span>}</td>
                        <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.quantity)}</td>
                        <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.received_quantity)}</td>
                        <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.invoiced_quantity)}</td>
                        <td className="px-4 py-2 text-right text-xs tabular-nums">{q(l.remaining_quantity)}</td>
                        <td className="px-4 py-2 text-right text-xs"><Amount value={l.unit_cost} currency={po.currency} money={money} /></td>
                        <td className="px-4 py-2 text-right text-xs"><Amount value={l.amount} currency={po.currency} money={money} /></td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}

export function Receipts({ po }) {
    return (
        <Card title="Goods receipts">
            {po.receipts.length === 0 ? <Empty>Nothing received yet.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {po.receipts.map((r) => (
                        <li key={r.id} className="px-5 py-3 text-sm">
                            <p className="flex flex-wrap items-baseline justify-between gap-2"><span className="font-mono text-xs font-medium text-[#0A1F44]">{r.grn_code}</span><span className="text-xs text-[#718096]">{localDate(r.received_on)} · {r.received_by?.name}</span></p>
                            {r.notes && <p className="text-xs text-[#4A5568]">{r.notes}</p>}
                            <ul className="mt-1 text-xs text-[#4A5568]">
                                {r.lines.map((l, i) => <li key={i}>#{l.line_no} {l.description}: received {q(l.quantity)}, accepted {q(l.accepted)}{l.rejection_reason ? <span className="text-[#B3261E]"> (rejected: {l.rejection_reason})</span> : null}</li>)}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

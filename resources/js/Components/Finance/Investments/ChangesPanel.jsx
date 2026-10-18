import Chip from '@/Components/Material/Chip';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import ChangeRequestModal from '@/Components/Finance/Investments/ChangeRequestModal';
import { BTN_PRIMARY, humanize, invMoney, localDate } from '@/Utils/finance';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TONES = { submitted: 'warning', applied: 'success', rejected: 'error', withdrawn: 'muted' };

/** Investment changes after approval: cost and schedule deltas, applied at once within tolerance or approved through the gate. */
export default function ChangesPanel({ investment, changes, baseCurrency, can }) {
    const money = can.viewAmounts !== false;
    const [show, setShow] = useState(false);
    const withdraw = (c) => confirm(`Withdraw ${c.change_code}?`) && router.post(route('finance.investment-changes.withdraw', c.id), {}, { preserveScroll: true });
    return (
        <Card title="Changes" right={can.requestChange && <button type="button" onClick={() => setShow(true)} className={BTN_PRIMARY}>Request change</button>}>
            <Table head={['Change', 'Description', 'Cost', 'Schedule', 'Outcome', 'Requested', '']} empty={changes.length === 0 && <Empty>No changes since approval. After approval, cost and schedule change only through this list.</Empty>}>
                {changes.map((c) => (
                    <tr key={c.id}>
                        <td className="whitespace-nowrap px-4 py-2 font-mono text-xs">{c.change_code}</td>
                        <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">{c.description}{c.reason && <span className="block text-xs text-[#718096]">{c.reason}</span>}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{invMoney(c.cost_delta, baseCurrency, money, { decimals: 0 })}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{Number(c.schedule_delta_days) > 0 ? '+' : ''}{c.schedule_delta_days} days</td>
                        <td className="px-4 py-2 text-xs">
                            <Chip size="xs" tone={TONES[c.status] ?? 'neutral'}>{humanize(c.status)}</Chip>
                            <span className="mt-0.5 block text-[#718096]">{c.status === 'applied' && !c.requires_approval ? 'Within tolerance: applied at once' : c.status === 'submitted' ? 'Awaiting approval' : c.requires_approval ? 'Approved beyond tolerance' : ''}</span>
                            {c.gate_id && <Link className="text-[#0A1F44] underline" href={route('finance.stage-gates.show', c.gate_id)}>View gate</Link>}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{localDate(c.requested_at)}{c.requested_by ? ` · ${c.requested_by}` : ''}</td>
                        <td className="px-4 py-2 text-right text-xs">{c.can?.withdraw && <button type="button" onClick={() => withdraw(c)} className="text-[#B3261E] underline">Withdraw</button>}</td>
                    </tr>
                ))}
            </Table>
            <ChangeRequestModal show={show} onClose={() => setShow(false)} investment={investment} baseCurrency={baseCurrency} viewAmounts={money} />
        </Card>
    );
}

import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AddButton, Card, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, invMoney } from '@/Utils/finance';

/** Budget lines tagged with this investment (classification only; money is untouched) and a picker to tag more. */
export default function LinkedLinesPanel({ investment, lines, lineOptions, baseCurrency, can }) {
    const money = can.viewAmounts !== false;
    const [show, setShow] = useState(false);
    const [picked, setPicked] = useState([]);
    const sync = (ids, opts = {}) => router.post(route('finance.investments.lines.sync', investment.id), { line_ids: ids }, { preserveScroll: true, ...opts });
    const untag = (l) => confirm(`Remove ${l.budget_code} line ${l.line_no} from this investment?`) && sync(lines.filter((x) => x.id !== l.id).map((x) => x.id));
    const open = () => { setPicked(lines.map((l) => l.id)); setShow(true); };
    const toggle = (id) => setPicked(picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id]);
    const choices = [...lines.map((l) => ({ id: l.id, label: `${l.budget_code} line ${l.line_no}: ${l.description}`, fiscal_year: l.fiscal_year, cost_centre: l.cost_centre })), ...lineOptions.filter((o) => !lines.some((l) => l.id === o.id))];
    return (
        <Card title="Budget lines" right={can.edit && <AddButton onClick={open}>Tag lines</AddButton>}>
            <Table head={['Budget', 'Line', 'Fiscal year', 'Cost centre', 'Baseline', 'Actual', '']} empty={lines.length === 0 && <Empty>No budget lines are tagged with this investment. Tagged lines count towards allocated funding and actual cost.</Empty>}>
                {lines.map((l) => (
                    <tr key={l.id}>
                        <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] underline" href={l.href}>{l.budget_code}</Link></td>
                        <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">#{l.line_no} {l.description}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{l.fiscal_year}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{l.cost_centre}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{invMoney(l.current_base_amount, baseCurrency, money, { decimals: 0 })}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{invMoney(l.actual, baseCurrency, money, { decimals: 0 })}</td>
                        <td className="px-4 py-2 text-right text-xs">{can.edit && <button type="button" onClick={() => untag(l)} className="text-[#B3261E] underline">Untag</button>}</td>
                    </tr>
                ))}
            </Table>
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={() => { sync(picked, { onSuccess: () => setShow(false) }); }} title="Tag budget lines" submitLabel="Save tags" maxWidth="2xl">
                <label className={LABEL}>Baseline and draft lines in your scope</label>
                <ul className={`${FIELD} max-h-72 divide-y divide-gray-50 overflow-y-auto p-0`}>
                    {choices.length === 0 && <li className="px-3 py-3 text-sm text-[#718096]">No lines available.</li>}
                    {choices.map((o) => (
                        <li key={o.id}><label className="flex items-start gap-2 px-3 py-2 text-sm"><input type="checkbox" className="mt-0.5" checked={picked.includes(o.id)} onChange={() => toggle(o.id)} /><span className="min-w-0 text-[#2D3748]">{o.label}<span className="block text-xs text-[#718096]">{o.fiscal_year} · {o.cost_centre}</span></span></label></li>
                    ))}
                </ul>
            </FormModal>
        </Card>
    );
}

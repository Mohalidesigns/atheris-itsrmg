import InputError from '@/Components/InputError';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, FIELD, formatMoney, humanize, sumDecimal } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

function LineRow({ budget, line, periods, methods, canEdit, errors }) {
    const [method, setMethod] = useState(line.phasing_method);
    const [amounts, setAmounts] = useState(() => Object.fromEntries(periods.map((p) => [p.id, line.amounts[p.id] ?? '0.00'])));
    const [busy, setBusy] = useState(false);
    const sum = sumDecimal(periods.map((p) => amounts[p.id]));
    const off = method === 'custom' && sum !== Number(line.annual_amount).toFixed(2);
    const apply = () => {
        setBusy(true);
        router.post(route('finance.budgets.phase', { budget: budget.id, line: line.id }), { phasing_method: method, amounts: method === 'custom' ? amounts : null },
            { preserveScroll: true, onFinish: () => setBusy(false) });
    };
    return (
        <li className="px-5 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <span className="font-mono text-xs text-[#4A5568]">#{line.line_no}</span>
                <span className="min-w-[10rem] flex-1 text-sm text-[#2D3748]">{line.description}</span>
                <span className="text-xs text-[#718096]">{formatMoney(line.annual_amount, line.currency)}</span>
                <select aria-label={`Phasing for line ${line.line_no}`} disabled={!canEdit} value={method} onChange={(e) => setMethod(e.target.value)} className="rounded-lg border border-gray-200 px-2 py-1 text-sm">
                    {methods.map((m) => <option key={m} value={m}>{humanize(m)}</option>)}
                </select>
                {canEdit && <button type="button" disabled={busy || method === line.phasing_method && method !== 'custom'} onClick={apply} className={`${BTN_PRIMARY} !px-3 !py-1 text-xs`}>Apply</button>}
            </div>
            {method === 'custom' && (
                <div className="mt-3">
                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6 lg:grid-cols-12">
                        {periods.map((p) => (
                            <label key={p.id} className="text-[10px] text-[#718096]">{p.name}
                                <input type="number" step="any" disabled={!canEdit} value={amounts[p.id]} onChange={(e) => setAmounts({ ...amounts, [p.id]: e.target.value })} className={`${FIELD} !mt-0.5 !px-2 !py-1 text-xs`} />
                            </label>
                        ))}
                    </div>
                    <p className={`mt-1 text-xs ${off ? 'text-[#B3261E]' : 'text-[#718096]'}`}>Σ periods {formatMoney(sum, line.currency)}{off ? `; the annual amount will become ${formatMoney(sum, line.currency)}` : ''}</p>
                </div>
            )}
            <InputError message={errors?.phasing_method || errors?.amounts} />
        </li>
    );
}

export default function PhasingPanel({ budget, lines, periods, methods, can, errors }) {
    return (
        <Card title="Phasing by line">
            {lines.length === 0 ? <Empty>Add lines before phasing them.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {lines.map((l) => <LineRow key={`${l.id}-${l.phasing_method}-${l.annual_amount}`} budget={budget} line={l} periods={periods} methods={methods} canEdit={can.edit} errors={errors} />)}
                </ul>
            )}
        </Card>
    );
}

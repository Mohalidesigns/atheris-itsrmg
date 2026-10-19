import Chip from '@/Components/Material/Chip';
import { Empty } from '@/Components/Finance/Settings/ui';
import { finMoney, fmtPct } from '@/Utils/finance';
import { useState } from 'react';

/** Showback statement: direct, allocated, total, budget and variance per target, a retained row and the reconciliation chip. */
export default function ShowbackTable({ statement, baseCurrency, money, onSelect, selected }) {
    const [pools, setPools] = useState(false);
    const { rows = [], totals, pools: poolCols = [] } = statement;
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    return (
        <div className="rounded-lg border border-gray-200 bg-white">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3">
                <h3 className="text-sm font-semibold text-[#0A1F44]">Statement</h3>
                <div className="flex items-center gap-3">
                    {totals && (totals.reconciles
                        ? <Chip size="xs" tone="success" icon="check_circle">Reconciles to IT total</Chip>
                        : <Chip size="xs" tone="error" icon="error">{money ? `Does not reconcile: ${fmt(totals.difference)}` : 'Does not reconcile'}</Chip>)}
                    {poolCols.length > 0 && <button type="button" onClick={() => setPools(!pools)} aria-expanded={pools} className="text-xs text-[#0A1F44] underline">{pools ? 'Hide cost pools' : 'Show cost pools'}</button>}
                </div>
            </div>
            {rows.length === 0 ? <Empty>No showback figures for this lens and range. Add allocation rules and run them, or tag spend directly.</Empty> : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 text-xs uppercase tracking-wide text-[#718096]">
                            <tr>
                                <th className="px-4 py-2 font-medium">Target</th>
                                <th className="px-4 py-2 text-right font-medium">Direct</th>
                                <th className="px-4 py-2 text-right font-medium">Allocated</th>
                                <th className="px-4 py-2 text-right font-medium">Total</th>
                                <th className="px-4 py-2 text-right font-medium">Budget</th>
                                <th className="px-4 py-2 text-right font-medium">Variance</th>
                                {pools && poolCols.map((p) => <th key={p.code} className="whitespace-nowrap px-4 py-2 text-right font-medium">{p.name}</th>)}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {rows.map((r) => (
                                <tr key={r.target_key} className={`cursor-pointer hover:bg-gray-50 ${selected === r.target_key ? 'bg-[#F7F3E8]' : ''}`}>
                                    <td className="min-w-[10rem] px-4 py-2"><button type="button" onClick={() => onSelect(r)} className="text-left font-medium text-[#0A1F44] hover:underline">{r.label}</button></td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(r.direct)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(r.allocated)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{fmt(r.total)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums text-[#4A5568]">{fmt(r.budget_total)}</td>
                                    <td className={`whitespace-nowrap px-4 py-2 text-right tabular-nums ${money && Number(r.variance) > 0 ? 'text-[#B3261E]' : ''}`}>{fmt(r.variance)}{money && r.variance_pct !== null && r.variance_pct !== undefined && <span className="block text-[11px] text-[#718096]">{fmtPct(r.variance_pct, { sign: true })}</span>}</td>
                                    {pools && poolCols.map((p) => <td key={p.code} className="whitespace-nowrap px-4 py-2 text-right tabular-nums text-[#4A5568]">{fmt(r.by_pool?.[p.code])}</td>)}
                                </tr>
                            ))}
                            {totals && (
                                <>
                                    <tr className="bg-gray-50"><td className="px-4 py-2 text-[#4A5568]">Retained by IT (not allocated)</td><td /><td /><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(totals.retained)}</td><td /><td />{pools && poolCols.map((p) => <td key={p.code} />)}</tr>
                                    <tr className="border-t-2 border-gray-200 font-semibold"><td className="px-4 py-2 text-[#0A1F44]">IT total</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(totals.direct)}</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(totals.allocated)}</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(totals.it_total)}</td><td /><td />{pools && poolCols.map((p) => <td key={p.code} />)}</tr>
                                </>
                            )}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

import Chip from '@/Components/Material/Chip';
import { Empty } from '@/Components/Finance/Settings/ui';
import { finMoney, fmtPct, humanize } from '@/Utils/finance';

/** Application / service TCO per row. Cloud is the FinOps figure on the actual basis. */
export default function TcoTable({ rows, baseCurrency, money, onSelect, subject }) {
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    const ea = subject === 'ea_application';
    return (
        <div className="rounded-lg border border-gray-200 bg-white">
            {rows.length === 0 ? <Empty>No cost is attributed to any {ea ? 'application' : 'business service'} for this year yet. Tag budget lines, or add allocation rules to this lens.</Empty> : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 text-xs uppercase tracking-wide text-[#718096]">
                            <tr>
                                <th className="px-4 py-2 font-medium">{ea ? 'Application' : 'Business service'}</th>
                                {ea && <th className="px-4 py-2 font-medium">Profile</th>}
                                <th className="px-4 py-2 text-right font-medium">Direct</th><th className="px-4 py-2 text-right font-medium">Allocated</th><th className="px-4 py-2 text-right font-medium">Cloud</th>
                                <th className="px-4 py-2 text-right font-medium">Total</th><th className="px-4 py-2 text-right font-medium">Labour</th><th className="px-4 py-2 text-right font-medium">Contracts (memo)</th>
                                {ea && <><th className="px-4 py-2 text-right font-medium">EA model</th><th className="px-4 py-2 text-right font-medium">Vs model</th></>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {rows.map((r) => (
                                <tr key={r.id} className="hover:bg-gray-50">
                                    <td className="min-w-[11rem] px-4 py-2"><button type="button" onClick={() => onSelect(r)} className="text-left font-medium text-[#0A1F44] hover:underline">{r.label}</button>{r.code && <span className="block font-mono text-[11px] text-[#718096]">{r.code}</span>}</td>
                                    {ea && <td className="whitespace-nowrap px-4 py-2 text-xs">{r.criticality && <Chip size="xs" tone="warning" className="mr-1">{humanize(r.criticality)}</Chip>}{r.lifecycle && <Chip size="xs" tone="muted">{humanize(r.lifecycle)}</Chip>}</td>}
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(r.direct)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(r.allocated)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(r.cloud)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{fmt(r.total)}<span className="block text-[11px] font-normal text-[#718096]">{fmtPct(r.share_pct)} of IT</span></td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums text-[#4A5568]">{fmt(r.labour)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums text-[#4A5568]">{fmt(r.contracts_memo)}<span className="block text-[11px] text-[#718096]">{r.contracts_count} contracts</span></td>
                                    {ea && <>
                                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums text-[#4A5568]">{r.ea_modelled === null || r.ea_modelled === undefined ? '—' : fmt(r.ea_modelled)}</td>
                                        <td className={`whitespace-nowrap px-4 py-2 text-right tabular-nums ${money && Math.abs(Number(r.delta_vs_model_pct)) >= 25 ? 'text-[#8A6400]' : ''}`}>{money ? fmtPct(r.delta_vs_model_pct, { sign: true }) : 'Hidden'}</td>
                                    </>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

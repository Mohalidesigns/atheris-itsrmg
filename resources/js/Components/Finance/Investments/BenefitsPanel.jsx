import Chip from '@/Components/Material/Chip';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { compactMoney, num } from '@/Components/Finance/Budgets/Charts';
import BenefitFormModal from '@/Components/Finance/Investments/BenefitFormModal';
import ReadingModal from '@/Components/Finance/Investments/ReadingModal';
import { BENEFIT_TONES, BTN_PRIMARY, humanize, invMoney, localDate } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

function fmtValue(b, v, viewAmounts, baseCurrency) {
    if (b.unit === 'currency') return invMoney(v, b.currency || baseCurrency, viewAmounts, { decimals: 0 });
    if (v === null || v === undefined) return '—';
    return `${Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 })} ${b.unit === 'pct' ? '%' : b.unit}`;
}

/** Benefit cards with realised against target, readings and a realised-against-planned chart. */
export default function BenefitsPanel({ investment, benefits, users, periods, options, baseCurrency, can }) {
    const money = can.viewAmounts !== false;
    const [form, setForm] = useState(null); // 'new' | benefit
    const [reading, setReading] = useState(null);
    const cancel = (b) => { const reason = prompt(`Reason for cancelling ${b.benefit_code}?`); if (reason) router.post(route('finance.benefits.cancel', b.id), { reason }, { preserveScroll: true }); };
    const chart = benefits.filter((b) => b.unit !== 'currency' || money).map((b) => ({ name: b.benefit_code, planned: num(b.target_value) - num(b.baseline_value), realised: num(b.realised_value) - (b.aggregation === 'latest' ? num(b.baseline_value) : 0) }));
    return (
        <div className="space-y-4">
            <Card title="Benefits register" right={can.addBenefit && <button type="button" onClick={() => setForm('new')} className={BTN_PRIMARY}>Add benefit</button>}>
                {benefits.length === 0 ? <Empty>No benefits registered. Every investment should name at least one measurable benefit with an owner and a due date.</Empty> : (
                    <ul className="divide-y divide-gray-100">
                        {benefits.map((b) => {
                            const pct = b.realised_pct === null || b.realised_pct === undefined ? null : Number(b.realised_pct);
                            const hidden = b.unit === 'currency' && !money;
                            return (
                                <li key={b.id} className="space-y-2 px-5 py-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0"><p className="text-sm font-medium text-[#2D3748]"><span className="font-mono text-xs text-[#718096]">{b.benefit_code}</span> {b.title}</p>
                                            <p className="text-xs text-[#718096]">{humanize(b.type)} · {b.owner?.name ?? 'No owner'} · due {localDate(b.due_on)}</p></div>
                                        <Chip size="xs" tone={BENEFIT_TONES[b.status] ?? 'neutral'}>{humanize(b.status)}</Chip>
                                    </div>
                                    <div>
                                        <div className="mb-1 flex flex-wrap justify-between gap-2 text-xs text-[#4A5568]">
                                            <span>Realised {hidden ? 'Hidden' : fmtValue(b, b.realised_value, money, baseCurrency)} of {hidden ? 'Hidden' : fmtValue(b, b.target_value, money, baseCurrency)}</span>
                                            <span className="tabular-nums">{hidden ? 'Hidden' : pct === null ? '—' : `${pct.toFixed(0)}%`}</span>
                                        </div>
                                        <div className="h-2 overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow={pct ?? 0} aria-valuemin={0} aria-valuemax={100} aria-label={`${b.title} realised`}>
                                            <div className={`h-full ${pct !== null && pct >= 100 ? 'bg-[#2D7D46]' : 'bg-[#C9A86A]'}`} style={{ width: `${hidden || pct === null ? 0 : Math.max(0, Math.min(100, pct))}%` }} />
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-3 text-xs">
                                        <span className="text-[#718096]">{b.aggregation === 'sum' ? 'Cumulative' : 'Level'} · {b.readings.length} reading{b.readings.length === 1 ? '' : 's'}</span>
                                        {b.can?.record && <button type="button" onClick={() => setReading(b)} className="text-[#0A1F44] underline">Record reading</button>}
                                        {b.can?.edit && <button type="button" onClick={() => setForm(b)} className="text-[#0A1F44] underline">Edit</button>}
                                        {b.can?.cancel && <button type="button" onClick={() => cancel(b)} className="text-[#B3261E] underline">Cancel</button>}
                                    </div>
                                    {b.readings.length > 0 && (
                                        <details className="text-xs"><summary className="cursor-pointer text-[#718096]">Readings</summary>
                                            <ul className="mt-1 divide-y divide-gray-50 rounded border border-gray-100">
                                                {b.readings.map((r) => (
                                                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5">
                                                        <span>{r.period.name}{r.note ? <span className="text-[#718096]"> · {r.note}</span> : null}</span>
                                                        <span className="flex items-center gap-3"><span className="tabular-nums">{hidden ? 'Hidden' : fmtValue(b, r.value, money, baseCurrency)}</span>{r.document && <a className="text-[#0A1F44] underline" href={r.document.url}>Evidence</a>}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </details>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>
            {chart.length > 0 && (
                <Card title="Realised against planned">
                    <div className="h-56 px-2" role="img" aria-label="Realised against planned by benefit">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={chart} margin={{ top: 8, right: 16, left: 8 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" /><XAxis dataKey="name" tick={{ fontSize: 11 }} /><YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, baseCurrency).replace(/[^\d.,KMBT-]/g, '')} width={60} />
                                <Tooltip /><Legend wrapperStyle={{ fontSize: 11 }} />
                                <Bar dataKey="planned" name="Target gain" fill="#C9A86A" /><Bar dataKey="realised" name="Realised" fill="#2D7D46" />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                    <p className="px-5 pb-3 text-[11px] text-[#718096]">Units differ between benefits; compare each pair of bars, not bars across benefits.</p>
                </Card>
            )}
            <BenefitFormModal show={form !== null} onClose={() => setForm(null)} investment={investment} benefit={form === 'new' ? null : form} users={users} options={options} baseCurrency={baseCurrency} />
            <ReadingModal show={reading !== null} onClose={() => setReading(null)} benefit={reading ? benefits.find((x) => x.id === reading.id) ?? reading : null} periods={periods} />
        </div>
    );
}

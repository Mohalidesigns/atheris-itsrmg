import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import CalcResults from '@/Components/Finance/Investments/CalcResults';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, LABEL, localDate } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const BC_TONES = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'error', superseded: 'muted' };

const yearsOf = (h) => Array.from({ length: Number(h) + 1 }, (_, i) => i);
const rowsFor = (bc, h) => yearsOf(h).map((y) => {
    const c = (bc?.costs ?? []).find((x) => Number(x.year) === y) ?? {};
    return { year: y, capex: c.capex ?? '', opex: c.opex ?? '', run: c.run ?? '' };
});
const benefitsFor = (bc, h) => yearsOf(h).map((y) => ({ year: y, amount: (bc?.benefits ?? []).find((x) => Number(x.year) === y)?.amount ?? '' }));
const dec = (v) => (v === '' || v === null || v === undefined ? '0' : String(v));

function toPayload(d) {
    return {
        discount_rate_pct: d.discount_rate_pct,
        horizon_years: Number(d.horizon_years),
        costs: d.costs.map((c) => ({ year: c.year, capex: dec(c.capex), opex: dec(c.opex), run: dec(c.run) })),
        benefits: d.benefits.map((b) => ({ year: b.year, amount: dec(b.amount) })),
    };
}

/**
 * Year grid for one business-case version with live results (debounced POST to the evaluate endpoint).
 * Only drafts are editable; submitted and approved versions show their saved figures.
 */
export default function BusinessCaseEditor({ investment, businessCases, businessCase: bc, baseCurrency, maxHorizon = 15, can, errors, onSubmit }) {
    const money = can.viewAmounts !== false;
    const editable = !!bc?.can?.edit;
    const init = (b) => ({
        horizon_years: b?.horizon_years ?? 5, discount_rate_pct: b?.discount_rate_pct ?? '', option_summary: b?.option_summary ?? '', recommended_option: b?.recommended_option ?? '',
        costs: rowsFor(b, b?.horizon_years ?? 5), benefits: benefitsFor(b, b?.horizon_years ?? 5), non_financial_benefits: b?.non_financial_benefits ?? [],
        assumptions: b?.assumptions ?? '', risks_summary: b?.risks_summary ?? '',
    });
    const form = useForm(init(bc));
    const d = form.data;
    const [calc, setCalc] = useState(bc && bc.npv !== undefined ? bc : null);
    const [calcErr, setCalcErr] = useState(null);
    const [busy, setBusy] = useState(false);
    const seq = useRef(0);

    useEffect(() => { form.setData(init(bc)); setCalc(bc && bc.npv !== undefined ? bc : null); setCalcErr(null); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [bc?.id, bc?.status]);

    const sig = useMemo(() => JSON.stringify(toPayload(d)), [d.horizon_years, d.discount_rate_pct, d.costs, d.benefits]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (!editable || !money || d.discount_rate_pct === '') return undefined;
        const my = ++seq.current;
        const t = setTimeout(() => {
            setBusy(true);
            window.axios.post(route('finance.business-cases.evaluate'), JSON.parse(sig))
                .then((r) => { if (my === seq.current) { setCalc(r.data); setCalcErr(null); } })
                .catch((e) => { if (my === seq.current) { setCalcErr(e.response?.status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' ') || 'Check the figures.' : 'Could not calculate.'); } })
                .finally(() => { if (my === seq.current) setBusy(false); });
        }, 400);
        return () => clearTimeout(t);
    }, [sig, editable, money]); // eslint-disable-line react-hooks/exhaustive-deps

    const setHorizon = (h) => {
        const n = Math.max(1, Math.min(maxHorizon, Number(h) || 1));
        form.setData({ ...d, horizon_years: n, costs: rowsFor({ costs: d.costs }, n), benefits: benefitsFor({ benefits: d.benefits }, n) });
    };
    const setCost = (i, k, v) => form.setData('costs', d.costs.map((c, j) => (j === i ? { ...c, [k]: v } : c)));
    const setBenefit = (i, v) => form.setData('benefits', d.benefits.map((b, j) => (j === i ? { ...b, amount: v } : b)));
    const setNf = (i, k, v) => form.setData('non_financial_benefits', d.non_financial_benefits.map((x, j) => (j === i ? { ...x, [k]: v } : x)));

    const save = () => {
        form.transform((x) => ({ ...x, ...toPayload(x), option_summary: x.option_summary || null, recommended_option: x.recommended_option || null, assumptions: x.assumptions || null, risks_summary: x.risks_summary || null }));
        form.put(route('finance.business-cases.update', bc.id), { preserveScroll: true });
    };
    const newVersion = () => router.post(route('finance.business-cases.store', investment.id), {}, { preserveScroll: true });
    const pick = (v) => router.get(route('finance.investments.show', { investment: investment.id, tab: 'business-case', version: v }), {}, { preserveScroll: true, preserveState: false });
    const canNew = can.edit && ['idea', 'business_case'].includes(investment.stage) && !businessCases.some((b) => ['draft', 'submitted'].includes(b.status));
    const e = form.errors;
    const cell = 'w-full min-w-[6rem] rounded border border-gray-200 px-2 py-1 text-right text-sm tabular-nums disabled:bg-gray-50';

    if (!bc) {
        return (
            <Card title="Business case">
                <Empty>No business case yet.{canNew && <> <button type="button" className="underline" onClick={newVersion}>Start the first version</button>.</>}</Empty>
            </Card>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <label className={LABEL} htmlFor="bc-version">Version</label>
                <select id="bc-version" value={bc.id} onChange={(ev) => pick(ev.target.value)} className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm">
                    {businessCases.map((b) => <option key={b.id} value={b.id}>v{b.version_no} · {b.status}</option>)}
                </select>
                <Chip tone={BC_TONES[bc.status] ?? 'neutral'}>{bc.status}</Chip>
                {bc.status === 'approved' && <span className="text-xs text-[#718096]">Approved {localDate(bc.approved_at)}{bc.approved_by ? ` by ${bc.approved_by}` : ''}</span>}
                {bc.status === 'rejected' && bc.rejection_reason && <span className="text-xs text-[#B3261E]">Rejected: {bc.rejection_reason}</span>}
                <div className="ml-auto flex gap-2">
                    {canNew && <button type="button" onClick={newVersion} className={BTN_SECONDARY}>New version</button>}
                    {editable && <button type="button" onClick={save} disabled={form.processing} className={BTN_SECONDARY}>Save draft</button>}
                    {bc.can?.submit && <button type="button" onClick={() => onSubmit(bc)} className={BTN_PRIMARY}>Submit for approval</button>}
                </div>
            </div>
            {errors?.business_case && <p role="alert" className="text-sm text-[#B3261E]">{errors.business_case}</p>}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-5">
                <div className="space-y-4 xl:col-span-3">
                    <Card title={`Costs and benefits (${baseCurrency})`}>
                        <fieldset disabled={!editable} className="space-y-4 px-5 py-4">
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div><label className={LABEL} htmlFor="bc-h">Horizon (years)</label><input id="bc-h" type="number" min="1" max={maxHorizon} value={d.horizon_years} onChange={(ev) => setHorizon(ev.target.value)} className={FIELD} /><InputError message={e.horizon_years} /></div>
                                <div><label className={LABEL} htmlFor="bc-r">Discount rate (%)</label><input id="bc-r" type="number" step="0.001" min="0" max="100" value={d.discount_rate_pct} onChange={(ev) => form.setData('discount_rate_pct', ev.target.value)} className={FIELD} /><InputError message={e.discount_rate_pct} /></div>
                            </div>
                            {!money ? <p className="text-sm text-[#718096]">Hidden: costs and benefits are not visible to your role.</p> : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-gray-50 text-xs uppercase tracking-wide text-[#718096]">
                                            <tr><th className="px-2 py-2 text-left font-medium">Year</th><th className="px-2 py-2 text-right font-medium">Capex</th><th className="px-2 py-2 text-right font-medium">Opex</th><th className="px-2 py-2 text-right font-medium">Run cost</th><th className="px-2 py-2 text-right font-medium">Benefit</th></tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-50">
                                            {d.costs.map((c, i) => (
                                                <tr key={c.year}>
                                                    <td className="px-2 py-1 text-[#4A5568]">{c.year === 0 ? '0 (today)' : c.year}</td>
                                                    {['capex', 'opex', 'run'].map((k) => <td key={k} className="px-1 py-1"><input aria-label={`Year ${c.year} ${k}`} type="number" step="any" min="0" value={c[k]} onChange={(ev) => setCost(i, k, ev.target.value)} className={cell} /></td>)}
                                                    <td className="px-1 py-1"><input aria-label={`Year ${c.year} benefit`} type="number" step="any" min="0" value={d.benefits[i]?.amount ?? ''} onChange={(ev) => setBenefit(i, ev.target.value)} className={cell} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    <InputError message={e.costs || e.benefits} />
                                </div>
                            )}
                        </fieldset>
                    </Card>

                    <Card title="Narrative" right={editable && <button type="button" className="text-xs text-[#0A1F44] underline" onClick={() => form.setData('non_financial_benefits', [...d.non_financial_benefits, { label: '', score: 3, note: '' }])}>Add non-financial benefit</button>}>
                        <fieldset disabled={!editable} className="space-y-3 px-5 py-4">
                            <div><label className={LABEL} htmlFor="bc-o">Options considered</label><textarea id="bc-o" rows={3} value={d.option_summary} onChange={(ev) => form.setData('option_summary', ev.target.value)} className={FIELD} /><InputError message={e.option_summary} /></div>
                            <div><label className={LABEL} htmlFor="bc-rec">Recommended option</label><input id="bc-rec" maxLength={200} value={d.recommended_option} onChange={(ev) => form.setData('recommended_option', ev.target.value)} className={FIELD} /><InputError message={e.recommended_option} /></div>
                            {d.non_financial_benefits.length > 0 && (
                                <div>
                                    <p className={LABEL}>Non-financial benefits (score 1 to 5)</p>
                                    {d.non_financial_benefits.map((x, i) => (
                                        <div key={i} className="mt-2 grid grid-cols-6 gap-2">
                                            <input aria-label="Benefit" placeholder="Benefit" value={x.label} onChange={(ev) => setNf(i, 'label', ev.target.value)} className="col-span-3 rounded-lg border border-gray-200 px-2 py-1 text-sm" />
                                            <select aria-label="Score" value={x.score} onChange={(ev) => setNf(i, 'score', Number(ev.target.value))} className="rounded-lg border border-gray-200 px-2 py-1 text-sm">{[1, 2, 3, 4, 5].map((n) => <option key={n}>{n}</option>)}</select>
                                            <input aria-label="Note" placeholder="Note" value={x.note ?? ''} onChange={(ev) => setNf(i, 'note', ev.target.value)} className="rounded-lg border border-gray-200 px-2 py-1 text-sm" />
                                            {editable && <button type="button" className="text-xs text-[#B3261E] underline" onClick={() => form.setData('non_financial_benefits', d.non_financial_benefits.filter((_, j) => j !== i))}>Remove</button>}
                                        </div>
                                    ))}
                                    <InputError message={e.non_financial_benefits} />
                                </div>
                            )}
                            <div><label className={LABEL} htmlFor="bc-a">Assumptions</label><textarea id="bc-a" rows={3} value={d.assumptions} onChange={(ev) => form.setData('assumptions', ev.target.value)} className={FIELD} /><InputError message={e.assumptions} /></div>
                            <div><label className={LABEL} htmlFor="bc-k">Risks</label><textarea id="bc-k" rows={3} value={d.risks_summary} onChange={(ev) => form.setData('risks_summary', ev.target.value)} className={FIELD} /><InputError message={e.risks_summary} /></div>
                        </fieldset>
                    </Card>
                </div>
                <div className="xl:col-span-2"><CalcResults r={calc} currency={baseCurrency} viewAmounts={money} busy={busy} error={calcErr} /></div>
            </div>
        </div>
    );
}

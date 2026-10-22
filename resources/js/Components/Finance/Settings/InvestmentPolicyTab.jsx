import InputError from '@/Components/InputError';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { Card, SaveBar } from './ui';

/** Investment scoring weights (must total 100), hurdle discount rate, cost tolerance and value-review lead time. */
export default function InvestmentPolicyTab({ policy, options, can }) {
    const keys = options.criteria.map((c) => c.value);
    const form = useForm({
        section: 'investments',
        investment_score_weights: Object.fromEntries(keys.map((k) => [k, policy.weights?.[k] ?? 0])),
        investment_discount_rate_pct: policy.discount_rate_pct ?? '',
        investment_cost_tolerance_pct: policy.cost_tolerance_pct ?? '',
        value_review_lead_days: policy.value_review_lead_days ?? '',
    });
    const d = form.data;
    const sum = keys.reduce((a, k) => a + (Number(d.investment_score_weights[k]) || 0), 0);
    const bad = sum !== 100;
    const e = form.errors;
    const setW = (k) => (ev) => form.setData('investment_score_weights', { ...d.investment_score_weights, [k]: ev.target.value === '' ? '' : Number(ev.target.value) });
    const submit = (ev) => {
        ev.preventDefault();
        if (bad) return;
        form.transform((x) => ({ ...x, investment_discount_rate_pct: x.investment_discount_rate_pct === '' ? null : x.investment_discount_rate_pct, investment_cost_tolerance_pct: x.investment_cost_tolerance_pct === '' ? null : x.investment_cost_tolerance_pct, value_review_lead_days: x.value_review_lead_days === '' ? null : x.value_review_lead_days }));
        form.put(route('finance.settings.update'), { preserveScroll: true });
    };
    const def = policy.defaults ?? {};
    return (
        <Card title="Investment policy">
            <form onSubmit={submit}>
                <fieldset disabled={!can.manage} className="space-y-5 px-5 py-4">
                    <div>
                        <p className="text-sm font-medium text-[#2D3748]">Prioritisation weights</p>
                        <p className="text-xs text-[#718096]">Whole numbers that must total 100. Changing them rescores every open investment.</p>
                        <div className="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-5">
                            {options.criteria.map((c) => (
                                <div key={c.value}><label className={LABEL} htmlFor={`w-${c.value}`}>{c.label} (%)</label>
                                    <input id={`w-${c.value}`} type="number" min="0" max="100" step="1" value={d.investment_score_weights[c.value]} onChange={setW(c.value)} className={FIELD} />
                                    <InputError message={e[`investment_score_weights.${c.value}`]} /></div>
                            ))}
                        </div>
                        <p className={`mt-2 text-xs ${bad ? 'text-[#B3261E]' : 'text-[#1B5530]'}`} role="status">Total {sum}%{bad ? `, ${sum > 100 ? 'reduce' : 'add'} ${Math.abs(100 - sum)} to reach 100` : ', ready to save'}</p>
                        <InputError message={e.investment_score_weights} />
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div><label className={LABEL} htmlFor="ip-rate">Discount rate (%)</label><input id="ip-rate" type="number" step="0.001" min="0" max="100" value={d.investment_discount_rate_pct} onChange={(ev) => form.setData('investment_discount_rate_pct', ev.target.value)} placeholder={def.discount_rate_pct ?? ''} className={FIELD} /><InputError message={e.investment_discount_rate_pct} /><p className="mt-1 text-[11px] text-[#718096]">Default for new business cases. Blank uses {def.discount_rate_pct ?? 'the system default'}%.</p></div>
                        <div><label className={LABEL} htmlFor="ip-tol">Cost tolerance (%)</label><input id="ip-tol" type="number" step="0.01" min="0" max="100" value={d.investment_cost_tolerance_pct} onChange={(ev) => form.setData('investment_cost_tolerance_pct', ev.target.value)} placeholder={def.cost_tolerance_pct ?? ''} className={FIELD} /><InputError message={e.investment_cost_tolerance_pct} /><p className="mt-1 text-[11px] text-[#718096]">Cumulative changes beyond this share of the budget need approval.</p></div>
                        <div><label className={LABEL} htmlFor="ip-lead">Value review lead time (days)</label><input id="ip-lead" type="number" step="1" min="0" max="365" value={d.value_review_lead_days} onChange={(ev) => form.setData('value_review_lead_days', ev.target.value)} placeholder={def.value_review_lead_days ?? ''} className={FIELD} /><InputError message={e.value_review_lead_days} /></div>
                    </div>
                </fieldset>
                {can.manage && <SaveBar processing={form.processing} dirty={!bad} />}
            </form>
        </Card>
    );
}

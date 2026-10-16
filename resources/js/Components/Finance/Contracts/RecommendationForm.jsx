import InputError from '@/Components/InputError';
import Icon from '@/Components/Material/Icon';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, DECISION_ICONS, FIELD, LABEL, formatPct, moneyOrMask } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

/** Recommend / edit the renewal decision (PUT finance.renewals.recommend). Shown while the renewal is `open`. */
export default function RecommendationForm({ renewal, contract, options, currencies, baseCurrency, can }) {
    const money = can.viewAmounts !== false;
    const form = useForm({
        recommendation: renewal.recommendation ?? '', proposed_term_months: renewal.proposed_term_months ?? contract.term_months ?? '',
        proposed_commences_on: renewal.proposed_commences_on ?? '', proposed_value: renewal.proposed_value ?? '', proposed_currency: renewal.proposed_currency ?? contract.currency ?? baseCurrency,
        justification: renewal.justification ?? '', benchmark_notes: renewal.benchmark_notes ?? '', alternatives_considered: renewal.alternatives_considered ?? '',
    });
    const d = form.data; const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const terminate = d.recommendation === 'terminate';
    const submit = (ev) => {
        ev.preventDefault();
        form.transform((x) => Object.fromEntries(Object.entries(x).map(([k, v]) => [k, v === '' ? null : v]).filter(([k, v]) => !(terminate && ['proposed_term_months', 'proposed_commences_on', 'proposed_value', 'proposed_currency'].includes(k) && v === null))));
        form.put(route('finance.renewals.recommend', renewal.id), { preserveScroll: true });
    };
    return (
        <Card title="Recommendation">
            <form onSubmit={submit} className="space-y-4 px-5 py-4">
                <fieldset>
                    <legend className={LABEL}>Decision</legend>
                    <div className="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5" role="radiogroup" aria-label="Decision">
                        {options.decisions.map((o) => (
                            <label key={o.value} className={`flex cursor-pointer flex-col items-center gap-1 rounded-lg border px-3 py-3 text-center text-sm ${d.recommendation === o.value ? 'border-[#0A1F44] bg-[#EEF1F8] font-semibold text-[#0A1F44]' : 'border-gray-200 text-[#2D3748] hover:bg-gray-50'}`}>
                                <input type="radio" name="recommendation" className="sr-only" checked={d.recommendation === o.value} onChange={() => form.setData('recommendation', o.value)} />
                                <Icon name={DECISION_ICONS[o.value] ?? 'help'} size={22} />{o.label}
                            </label>
                        ))}
                    </div>
                    <InputError message={e.recommendation} />
                </fieldset>
                {!terminate && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div><label className={LABEL} htmlFor="r-term">Term (months)</label><input id="r-term" type="number" min="1" max="120" value={d.proposed_term_months} onChange={set('proposed_term_months')} className={FIELD} /><InputError message={e.proposed_term_months} /></div>
                        <div><label className={LABEL} htmlFor="r-from">Commences on</label><input id="r-from" type="date" value={d.proposed_commences_on} onChange={set('proposed_commences_on')} className={FIELD} /><InputError message={e.proposed_commences_on} /></div>
                        <div><label className={LABEL} htmlFor="r-val">Proposed value</label><input id="r-val" type="number" min="0" step="0.01" value={d.proposed_value} onChange={set('proposed_value')} className={FIELD} /><InputError message={e.proposed_value} /></div>
                        <div><label className={LABEL} htmlFor="r-cur">Currency</label><select id="r-cur" value={d.proposed_currency} onChange={set('proposed_currency')} className={FIELD}>{currencies.map((x) => <option key={x.code} value={x.code}>{x.code}</option>)}</select><InputError message={e.proposed_currency} /></div>
                        <p className="text-xs text-[#718096] sm:col-span-2 lg:col-span-4">
                            Converted to {baseCurrency} at today's rate when saved and again on submit.
                            {renewal.proposed_base_value !== null && renewal.proposed_base_value !== undefined || !money ? <> Saved: <b>{moneyOrMask(renewal.proposed_base_value, baseCurrency, money)}</b>{renewal.uplift_pct !== null && money ? <>, uplift <b>{formatPct(renewal.uplift_pct, 1)}</b></> : null}.</> : ' Uplift shows after you save.'}
                        </p>
                    </div>
                )}
                <div><label className={LABEL} htmlFor="r-just">Justification (required to submit)</label><textarea id="r-just" rows={3} value={d.justification} onChange={set('justification')} className={FIELD} /><InputError message={e.justification} /></div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><label className={LABEL} htmlFor="r-bench">Benchmark notes</label><textarea id="r-bench" rows={3} value={d.benchmark_notes} onChange={set('benchmark_notes')} className={FIELD} /><InputError message={e.benchmark_notes} /></div>
                    <div><label className={LABEL} htmlFor="r-alt">Alternatives considered</label><textarea id="r-alt" rows={3} value={d.alternatives_considered} onChange={set('alternatives_considered')} className={FIELD} /><InputError message={e.alternatives_considered} /></div>
                </div>
                <InputError message={e.workflow} />
                <div className="flex justify-end"><button disabled={form.processing || !d.recommendation} className={BTN_PRIMARY}>Save recommendation</button></div>
            </form>
        </Card>
    );
}

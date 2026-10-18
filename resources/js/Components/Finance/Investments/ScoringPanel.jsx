import InputError from '@/Components/InputError';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, FIELD, localDate } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

const effective = (criterion, s) => (criterion === 'delivery_risk' ? 6 - s : s);

/** Five criteria scored 1 to 5 against the bank's weights; delivery risk is entered as risk (5 = high) and inverted. */
export default function ScoringPanel({ investment, scores, weights, criteria, can }) {
    const byKey = Object.fromEntries(scores.map((s) => [s.criterion, s]));
    const form = useForm({
        scores: Object.fromEntries(criteria.map((c) => [c.value, byKey[c.value]?.score ?? 3])),
        notes: Object.fromEntries(criteria.map((c) => [c.value, byKey[c.value]?.note ?? ''])),
    });
    const total = criteria.reduce((sum, c) => sum + (effective(c.value, Number(form.data.scores[c.value])) / 5) * Number(weights[c.value] ?? 0), 0);
    const submit = (ev) => {
        ev.preventDefault();
        form.transform((d) => ({ ...d, notes: Object.fromEntries(Object.entries(d.notes).map(([k, v]) => [k, v || null])) }));
        form.post(route('finance.investments.score', investment.id), { preserveScroll: true });
    };
    return (
        <Card title="Prioritisation score" right={<span className="text-sm text-[#4A5568]">Score <b className="tabular-nums text-[#0A1F44]">{investment.priority_score ?? 'unscored'}</b>{investment.priority_rank ? ` · rank ${investment.priority_rank}` : ''}</span>}>
            <form onSubmit={submit}>
                <fieldset disabled={!can.score} className="divide-y divide-gray-50">
                    {criteria.map((c) => {
                        const v = Number(form.data.scores[c.value]);
                        const risk = c.value === 'delivery_risk';
                        const contribution = (effective(c.value, v) / 5) * Number(weights[c.value] ?? 0);
                        return (
                            <div key={c.value} className="grid grid-cols-1 gap-2 px-5 py-3 sm:grid-cols-12 sm:items-center">
                                <div className="sm:col-span-4">
                                    <label htmlFor={`sc-${c.value}`} className="text-sm font-medium text-[#2D3748]">{c.label}</label>
                                    <p className="text-xs text-[#718096]">Weight {weights[c.value] ?? 0}%{risk ? ' · 5 = high risk, scored inverted' : ' · 5 = most favourable'}</p>
                                </div>
                                <div className="flex items-center gap-3 sm:col-span-4">
                                    <input id={`sc-${c.value}`} type="range" min="1" max="5" step="1" value={v} onChange={(ev) => form.setData('scores', { ...form.data.scores, [c.value]: Number(ev.target.value) })} className="w-full accent-[#0A1F44]" />
                                    <span className="w-6 text-center text-sm font-semibold tabular-nums">{v}</span>
                                </div>
                                <div className="text-sm tabular-nums text-[#4A5568] sm:col-span-1 sm:text-right">{contribution.toFixed(1)}</div>
                                <input aria-label={`${c.label} note`} placeholder="Note" maxLength={500} value={form.data.notes[c.value]} onChange={(ev) => form.setData('notes', { ...form.data.notes, [c.value]: ev.target.value })} className={`${FIELD} mt-0 sm:col-span-3`} />
                                <InputError message={form.errors[`scores.${c.value}`]} className="sm:col-span-12" />
                            </div>
                        );
                    })}
                </fieldset>
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-5 py-3">
                    <p className="text-sm text-[#4A5568]">Weighted total <b className="tabular-nums text-[#0A1F44]">{total.toFixed(2)}</b> of 100{scores[0]?.scored_by ? <span className="ml-2 text-xs text-[#718096]">Last scored by {scores[0].scored_by}, {localDate(scores[0].scored_at)}</span> : null}</p>
                    {can.score && <button disabled={form.processing} className={BTN_PRIMARY}>Save scores</button>}
                </div>
                <InputError message={form.errors.scores} className="px-5 pb-3" />
            </form>
        </Card>
    );
}

import InputError from '@/Components/InputError';
import { FIELD, LABEL, formatMoney } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { Card, SaveBar } from './ui';

export default function DoaBandsTab({ settings, baseCurrency, can }) {
    const form = useForm({
        section: 'doa',
        doa_bands: settings.doa_bands.map((b) => ({ label: b.label, min: b.min, max: b.max })),
    });
    const bands = form.data.doa_bands;
    const last = bands.length - 1;
    // Keep min == previous max, first min 0, last max null.
    const normalise = (list) => list.map((b, i) => ({ ...b, min: i === 0 ? 0 : list[i - 1].max, max: i === list.length - 1 ? null : b.max }));
    const setBands = (list) => form.setData('doa_bands', normalise(list));
    const update = (i, patch) => setBands(bands.map((b, j) => (j === i ? { ...b, ...patch } : b)));
    const add = () => {
        const prev = bands[last];
        const split = Number(prev.min) > 0 ? Number(prev.min) * 2 : 1000000;
        const next = bands.map((b, j) => (j === last ? { ...b, max: split } : b));
        setBands([...next, { label: 'New band', min: split, max: null }]);
    };
    const remove = (i) => setBands(bands.filter((_, j) => j !== i));

    let clientError = null;
    bands.forEach((b, i) => {
        if (i < last && (b.max === null || b.max === '' || Number(b.max) <= Number(b.min))) clientError = 'Each band must end above where it starts.';
        if (!b.label) clientError = clientError || 'Every band needs a label.';
    });
    const nonAsc = bands.some((b, i) => i < last && i > 0 && Number(b.max) <= Number(bands[i - 1].max));
    if (nonAsc) clientError = 'Band limits must ascend.';

    return (
        <Card title="Delegation of authority bands">
            <form onSubmit={(e) => { e.preventDefault(); if (!clientError) form.put(route('finance.settings.update'), { preserveScroll: true }); }}>
                <div className="space-y-3 px-5 py-4">
                    <p className="text-xs text-[#718096]">Bands drive the amount_band condition on every finance approval in Workflow Studio. Amounts are in {baseCurrency}.</p>
                    {bands.map((b, i) => (
                        <div key={i} className="grid grid-cols-2 items-end gap-3 rounded-lg border border-gray-100 p-3 sm:grid-cols-12">
                            <div className="col-span-2 sm:col-span-4"><label className={LABEL}>Label</label><input disabled={!can.manage} maxLength={40} value={b.label} onChange={(e) => update(i, { label: e.target.value })} className={FIELD} /></div>
                            <div className="sm:col-span-3"><label className={LABEL}>From</label><input readOnly value={formatMoney(b.min, baseCurrency, { decimals: 0 })} className={`${FIELD} bg-gray-50 text-[#718096]`} /></div>
                            <div className="sm:col-span-3"><label className={LABEL}>Up to (exclusive)</label>
                                {i === last ? <input readOnly value="No upper limit" className={`${FIELD} bg-gray-50 text-[#718096]`} />
                                    : <input disabled={!can.manage} type="number" min="0" step="1" value={b.max ?? ''} onChange={(e) => update(i, { max: e.target.value === '' ? '' : Number(e.target.value) })} className={FIELD} />}</div>
                            <div className="text-right sm:col-span-2">{can.manage && i > 0 && <button type="button" onClick={() => remove(i)} className="text-xs text-[#B3261E] underline">Remove</button>}</div>
                            <div className="col-span-2 sm:col-span-12"><InputError message={form.errors[`doa_bands.${i}.label`] || form.errors[`doa_bands.${i}.min`] || form.errors[`doa_bands.${i}.max`]} /></div>
                        </div>
                    ))}
                    <InputError message={form.errors.doa_bands} />
                    {clientError && <p className="text-xs text-[#B3261E]">{clientError}</p>}
                    {can.manage && bands.length < 8 && <button type="button" onClick={add} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs">Add band</button>}
                </div>
                {can.manage && <SaveBar processing={form.processing} dirty={!clientError} />}
            </form>
        </Card>
    );
}

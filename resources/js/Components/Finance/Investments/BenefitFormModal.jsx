import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const blank = (o) => ({ title: '', type: o.benefitTypes[0]?.value ?? 'cost_saving', description: '', owner_id: '', measurement_method: '', baseline_value: '0', target_value: '', unit: 'currency', aggregation: 'sum', due_on: '' });
const defaultAgg = (unit) => (['pct', 'score'].includes(unit) ? 'latest' : 'sum');

/** Add or edit a benefit. `benefit` null = add. Currency benefits are in the base currency. */
export default function BenefitFormModal({ show, onClose, investment, benefit, users, options, baseCurrency }) {
    const form = useForm(blank(options));
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        if (benefit) {
            const d = blank(options);
            Object.keys(d).forEach((k) => { d[k] = benefit[k] ?? (k === 'owner_id' ? benefit.owner?.id : '') ?? ''; });
            d.owner_id = benefit.owner?.id ?? '';
            form.setData(d);
        } else form.setData(blank(options));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, benefit?.id]);
    const d = form.data; const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const submit = () => {
        form.transform((x) => { const o = { ...x }; Object.keys(o).forEach((k) => { if (o[k] === '') o[k] = null; }); return o; });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (benefit) form.put(route('finance.benefits.update', benefit.id), opts);
        else form.post(route('finance.benefits.store', investment.id), opts);
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !d.title || d.target_value === ''} maxWidth="2xl" title={benefit ? `Edit ${benefit.benefit_code}` : 'Add benefit'}>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2"><label className={LABEL} htmlFor="bn-t">Title</label><input id="bn-t" maxLength={200} value={d.title} onChange={set('title')} className={FIELD} /><InputError message={e.title} /></div>
                <div><label className={LABEL} htmlFor="bn-ty">Type</label><select id="bn-ty" value={d.type} onChange={set('type')} className={FIELD}>{options.benefitTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.type} /></div>
                <div><label className={LABEL} htmlFor="bn-o">Owner</label><select id="bn-o" value={d.owner_id} onChange={set('owner_id')} className={FIELD}><option value="">Select…</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select><InputError message={e.owner_id} /></div>
                <div><label className={LABEL} htmlFor="bn-u">Unit</label>
                    <select id="bn-u" value={d.unit} onChange={(ev) => form.setData({ ...d, unit: ev.target.value, aggregation: defaultAgg(ev.target.value) })} className={FIELD}>{options.units.map((o) => <option key={o.value} value={o.value}>{o.value === 'currency' ? `${o.label} (${baseCurrency})` : o.label}</option>)}</select><InputError message={e.unit} /></div>
                <div><label className={LABEL} htmlFor="bn-a">Measured as</label>
                    <select id="bn-a" value={d.aggregation} onChange={set('aggregation')} className={FIELD}><option value="sum">Sum of readings (cumulative)</option><option value="latest">Latest reading (level)</option></select><InputError message={e.aggregation} /></div>
                <div><label className={LABEL} htmlFor="bn-b">Baseline</label><input id="bn-b" type="number" step="any" value={d.baseline_value} onChange={set('baseline_value')} className={FIELD} /><InputError message={e.baseline_value} /></div>
                <div><label className={LABEL} htmlFor="bn-g">Target</label><input id="bn-g" type="number" step="any" value={d.target_value} onChange={set('target_value')} className={FIELD} /><InputError message={e.target_value} /></div>
                <div><label className={LABEL} htmlFor="bn-d">Due on</label><input id="bn-d" type="date" value={d.due_on} onChange={set('due_on')} className={FIELD} /><InputError message={e.due_on} /></div>
            </div>
            <div><label className={LABEL} htmlFor="bn-m">How it is measured</label><textarea id="bn-m" rows={2} value={d.measurement_method} onChange={set('measurement_method')} className={FIELD} /><InputError message={e.measurement_method} /></div>
            <div><label className={LABEL} htmlFor="bn-de">Description</label><textarea id="bn-de" rows={2} value={d.description} onChange={set('description')} className={FIELD} /><InputError message={e.description} /></div>
        </FormModal>
    );
}

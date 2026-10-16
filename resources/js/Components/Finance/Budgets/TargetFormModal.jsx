import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export default function TargetFormModal({ show, onClose, cycle, target, costCentres, categories, legalEntities }) {
    const form = useForm({ cycle_id: cycle?.id ?? '', cost_centre_id: '', category_id: '', legal_entity_id: '', capex_ceiling: '', opex_ceiling: '', notes: '' });
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        form.setData(target ? {
            cycle_id: cycle?.id, cost_centre_id: target.cost_centre?.id ?? '', category_id: target.category?.id ?? '', legal_entity_id: target.legal_entity?.id ?? '',
            capex_ceiling: target.capex_ceiling ?? '', opex_ceiling: target.opex_ceiling ?? '', notes: target.notes ?? '',
        } : { cycle_id: cycle?.id ?? '', cost_centre_id: '', category_id: '', legal_entity_id: '', capex_ceiling: '', opex_ceiling: '', notes: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, target?.id]);
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const submit = () => {
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (target) form.put(route('finance.targets.update', target.id), opts); else form.post(route('finance.targets.store'), opts);
    };
    const opt = (list, f) => list.map((x) => <option key={x.id} value={x.id}>{f(x)}</option>);
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing} title={target ? 'Edit ceiling' : 'Add ceiling'}>
            <p className="text-xs text-[#718096]">Leave a scope blank to apply the ceiling to everything in the cycle for that dimension.</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label className={LABEL}>Cost centre</label><select value={form.data.cost_centre_id} onChange={set('cost_centre_id')} className={FIELD}><option value="">Any</option>{opt(costCentres, (c) => `${c.code} ${c.name}`)}</select><InputError message={e.cost_centre_id} /></div>
                <div><label className={LABEL}>Cost pool / tower</label><select value={form.data.category_id} onChange={set('category_id')} className={FIELD}><option value="">Any</option>{opt(categories, (c) => `${humanize(c.kind)} · ${c.code} ${c.name}`)}</select><InputError message={e.category_id} /></div>
                <div><label className={LABEL}>Legal entity</label><select value={form.data.legal_entity_id} onChange={set('legal_entity_id')} className={FIELD}><option value="">Any</option>{opt(legalEntities, (c) => `${c.code} ${c.name}`)}</select><InputError message={e.legal_entity_id} /></div>
                <div />
                <div><label className={LABEL}>CapEx ceiling</label><input type="number" min="0" step="any" value={form.data.capex_ceiling} onChange={set('capex_ceiling')} className={FIELD} /><InputError message={e.capex_ceiling} /></div>
                <div><label className={LABEL}>OpEx ceiling</label><input type="number" min="0" step="any" value={form.data.opex_ceiling} onChange={set('opex_ceiling')} className={FIELD} /><InputError message={e.opex_ceiling} /></div>
            </div>
            <div><label className={LABEL}>Notes</label><input maxLength={500} value={form.data.notes} onChange={set('notes')} className={FIELD} /><InputError message={e.notes} /></div>
            <InputError message={e.workflow || e.cycle_id} />
        </FormModal>
    );
}

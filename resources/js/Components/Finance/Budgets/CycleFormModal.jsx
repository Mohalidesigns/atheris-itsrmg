import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export default function CycleFormModal({ show, onClose, cycle, fiscalYears, users, types }) {
    const form = useForm({ fiscal_year_id: '', name: '', type: types[0], opens_on: '', closes_on: '', instructions: '', coordinator_id: '' });
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        form.setData(cycle ? {
            fiscal_year_id: cycle.fiscal_year.id, name: cycle.name, type: cycle.type, opens_on: cycle.opens_on, closes_on: cycle.closes_on,
            instructions: cycle.instructions ?? '', coordinator_id: cycle.coordinator?.id ?? '',
        } : { fiscal_year_id: fiscalYears.find((y) => y.is_current)?.id ?? fiscalYears[0]?.id ?? '', name: '', type: types[0], opens_on: '', closes_on: '', instructions: '', coordinator_id: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, cycle?.id]);
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const submit = () => {
        form.transform((d) => ({ ...d, coordinator_id: d.coordinator_id || null, instructions: d.instructions || null }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (cycle) form.put(route('finance.cycles.update', cycle.id), opts); else form.post(route('finance.cycles.store'), opts);
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing} title={cycle ? `Edit ${cycle.cycle_code}` : 'New planning cycle'}>
            <div><label className={LABEL}>Name</label><input maxLength={160} value={form.data.name} onChange={set('name')} className={FIELD} /><InputError message={e.name} /></div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label className={LABEL}>Fiscal year</label><select value={form.data.fiscal_year_id} onChange={set('fiscal_year_id')} className={FIELD}><option value="">Select…</option>{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</select><InputError message={e.fiscal_year_id} /></div>
                <div><label className={LABEL}>Type</label><select value={form.data.type} onChange={set('type')} className={FIELD}>{types.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select><InputError message={e.type} /></div>
                <div><label className={LABEL}>Opens on</label><input type="date" value={form.data.opens_on} onChange={set('opens_on')} className={FIELD} /><InputError message={e.opens_on} /></div>
                <div><label className={LABEL}>Closes on</label><input type="date" value={form.data.closes_on} onChange={set('closes_on')} className={FIELD} /><InputError message={e.closes_on} /></div>
            </div>
            <div><label className={LABEL}>Coordinator</label><select value={form.data.coordinator_id} onChange={set('coordinator_id')} className={FIELD}><option value="">None</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select><InputError message={e.coordinator_id} /></div>
            <div><label className={LABEL}>Instructions to budget owners</label><textarea rows={4} value={form.data.instructions} onChange={set('instructions')} className={FIELD} /><InputError message={e.instructions} /></div>
            <InputError message={e.workflow} />
        </FormModal>
    );
}

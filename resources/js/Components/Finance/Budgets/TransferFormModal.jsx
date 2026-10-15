import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, formatMoney } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const BLANK = { from_line_id: '', to_line_id: '', to_cost_centre_id: '', to_cost_pool_id: '', to_tower_id: '', to_description: '', from_period_id: '', to_period_id: '', amount: '', reason: '' };

/** Move budget between baseline lines (virement). The destination is an existing line or a new one. */
export default function TransferFormModal({ show, onClose, transfer, fiscalYearId, lineOptions, periods, costCentres, costPools, towers, baseCurrency }) {
    const form = useForm({ ...BLANK, fiscal_year_id: fiscalYearId });
    const [dest, setDest] = useState('existing');
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        if (transfer) {
            form.setData({
                fiscal_year_id: fiscalYearId, from_line_id: transfer.from_line?.id ?? '', to_line_id: transfer.to_line?.id ?? '', to_cost_centre_id: transfer.to_cost_centre?.id ?? '',
                to_cost_pool_id: transfer.to_cost_pool?.id ?? '', to_tower_id: transfer.to_tower?.id ?? '', to_description: transfer.to_description ?? '',
                from_period_id: transfer.from_period?.id ?? '', to_period_id: transfer.to_period?.id ?? '', amount: transfer.amount, reason: transfer.reason ?? '',
            });
            setDest(transfer.to_line ? 'existing' : 'new');
        } else { form.setData({ ...BLANK, fiscal_year_id: fiscalYearId }); setDest('existing'); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, transfer?.id]);
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const from = lineOptions.find((l) => String(l.id) === String(form.data.from_line_id));
    const available = from ? (form.data.from_period_id ? from.available_by_period?.[form.data.from_period_id] : from.current_base_amount) : null;
    const submit = () => {
        form.transform((d) => {
            const out = Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
            if (dest === 'existing') Object.assign(out, { to_cost_centre_id: null, to_cost_pool_id: null, to_tower_id: null, to_description: null });
            else out.to_line_id = null;
            return out;
        });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (transfer) form.put(route('finance.transfers.update', transfer.id), opts); else form.post(route('finance.transfers.store'), opts);
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing} title={transfer ? `Edit ${transfer.transfer_code}` : 'New transfer'} maxWidth="2xl">
            <div><label className={LABEL}>From line</label>
                <select value={form.data.from_line_id} onChange={set('from_line_id')} className={FIELD}><option value="">Select…</option>{lineOptions.map((l) => <option key={l.id} value={l.id}>{l.label}</option>)}</select>
                <InputError message={e.from_line_id} /></div>
            <fieldset>
                <legend className={LABEL}>To</legend>
                <div className="mt-1 flex gap-4 text-sm">
                    <label className="flex items-center gap-1"><input type="radio" name="dest" checked={dest === 'existing'} onChange={() => setDest('existing')} /> Existing line</label>
                    <label className="flex items-center gap-1"><input type="radio" name="dest" checked={dest === 'new'} onChange={() => setDest('new')} /> New line</label>
                </div>
            </fieldset>
            {dest === 'existing' ? (
                <div><select aria-label="To line" value={form.data.to_line_id} onChange={set('to_line_id')} className={FIELD}><option value="">Select…</option>{lineOptions.filter((l) => String(l.id) !== String(form.data.from_line_id)).map((l) => <option key={l.id} value={l.id}>{l.label}</option>)}</select><InputError message={e.to_line_id} /></div>
            ) : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL}>Cost centre</label><select value={form.data.to_cost_centre_id} onChange={set('to_cost_centre_id')} className={FIELD}><option value="">Select…</option>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.to_cost_centre_id} /></div>
                    <div><label className={LABEL}>Cost pool</label><select value={form.data.to_cost_pool_id} onChange={set('to_cost_pool_id')} className={FIELD}><option value="">Select…</option>{costPools.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.to_cost_pool_id} /></div>
                    <div><label className={LABEL}>Tower</label><select value={form.data.to_tower_id} onChange={set('to_tower_id')} className={FIELD}><option value="">Select…</option>{towers.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.to_tower_id} /></div>
                    <div className="sm:col-span-3"><label className={LABEL}>Description</label><input maxLength={255} value={form.data.to_description} onChange={set('to_description')} className={FIELD} /><InputError message={e.to_description} /></div>
                </div>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div><label className={LABEL}>From period (optional)</label><select value={form.data.from_period_id} onChange={set('from_period_id')} className={FIELD}><option value="">Whole year</option>{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select><InputError message={e.from_period_id} /></div>
                <div><label className={LABEL}>To period (optional)</label><select value={form.data.to_period_id} onChange={set('to_period_id')} className={FIELD}><option value="">Whole year</option>{periods.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select><InputError message={e.to_period_id} /></div>
                <div><label className={LABEL}>Amount ({baseCurrency})</label><input type="number" min="0" step="any" value={form.data.amount} onChange={set('amount')} className={FIELD} />
                    {available !== null && available !== undefined && <p className="mt-1 text-[11px] text-[#718096]">Available {formatMoney(available, baseCurrency, { decimals: 0 })}</p>}<InputError message={e.amount} /></div>
            </div>
            <div><label className={LABEL}>Reason</label><textarea rows={3} value={form.data.reason} onChange={set('reason')} className={FIELD} /><InputError message={e.reason} /></div>
            <InputError message={e.workflow} />
        </FormModal>
    );
}

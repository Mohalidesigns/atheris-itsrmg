import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const BLANK = { name: '', source_type: 'cost_centre', source_id: '', target_type: 'business_unit', target_id: '', target_ref: '', method: 'percentage', percentage: '', driver_id: '', driver_quantity: '', effective_from: '', effective_to: '', journal_cost_centre_code: '', notes: '' };

/** Create or edit an allocation rule. Source, target and method are locked once the rule has results (end it and add a new one). */
export default function AllocationFormModal({ show, onClose, rule, sources, targets, drivers, options, firstOpenDate }) {
    const form = useForm(BLANK);
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        form.setData(rule ? {
            name: rule.name, source_type: rule.source_type, source_id: rule.source?.id ?? '', target_type: rule.target_type, target_id: rule.target?.id ?? '', target_ref: rule.target?.ref ?? '',
            method: rule.method, percentage: rule.percentage ?? '', driver_id: rule.driver?.id ?? '', driver_quantity: rule.driver_quantity ?? '', effective_from: rule.effective_from ?? '', effective_to: rule.effective_to ?? '',
            journal_cost_centre_code: rule.journal_cost_centre_code ?? '', notes: rule.notes ?? '',
        } : { ...BLANK, effective_from: firstOpenDate ?? '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, rule?.id]);
    const d = form.data; const e = form.errors;
    const locked = !!rule?.has_results;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const isUnit = d.target_type === 'business_unit';
    const targetList = isUnit ? (targets.business_unit ?? []).map((u) => ({ id: u.ref, label: u.label })) : (targets[d.target_type] ?? []);
    const submit = () => {
        form.transform((x) => {
            const o = Object.fromEntries(Object.entries(x).map(([k, v]) => [k, v === '' ? null : v]));
            if (isUnit) { o.target_ref = x.target_id || x.target_ref || null; o.target_id = null; } else { o.target_ref = null; }
            if (o.method === 'direct') { o.percentage = 100; o.driver_id = null; o.driver_quantity = null; }
            if (o.method === 'percentage') { o.driver_id = null; o.driver_quantity = null; }
            if (o.method === 'driver') o.percentage = null;
            return o;
        });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (rule) form.put(route('finance.allocations.update', rule.id), opts); else form.post(route('finance.allocations.store'), opts);
    };
    const targetValue = isUnit ? (d.target_id || d.target_ref) : d.target_id;
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !d.name.trim() || !d.source_id || !targetValue || !d.effective_from} title={rule ? `Edit ${rule.allocation_code}` : 'Add allocation rule'} maxWidth="2xl">
            {locked && <p className="rounded-lg bg-[#FCEBC4] px-3 py-2 text-xs text-[#5F4300]">This rule already has results, so source, target and method are fixed. End it and add a new rule to change them.</p>}
            <div><label className={LABEL} htmlFor="al-nm">Name</label><input id="al-nm" maxLength={160} value={d.name} onChange={set('name')} className={FIELD} /><InputError message={e.name} /></div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label className={LABEL} htmlFor="al-st">Source (what is allocated)</label><select id="al-st" disabled={locked} value={d.source_type} onChange={(ev) => form.setData({ ...d, source_type: ev.target.value, source_id: '' })} className={FIELD}>{options.sourceTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.source_type} /></div>
                <div><label className={LABEL} htmlFor="al-si">Source record</label><select id="al-si" disabled={locked} value={d.source_id} onChange={set('source_id')} className={FIELD}><option value="">Select…</option>{(sources[d.source_type] ?? []).map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select><InputError message={e.source_id} /></div>
                <div><label className={LABEL} htmlFor="al-tt">Target (who bears it)</label><select id="al-tt" disabled={locked} value={d.target_type} onChange={(ev) => form.setData({ ...d, target_type: ev.target.value, target_id: '', target_ref: '' })} className={FIELD}>{options.targetTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.target_type} /></div>
                <div><label className={LABEL} htmlFor="al-ti">Target record</label><select id="al-ti" disabled={locked} value={targetValue ?? ''} onChange={set('target_id')} className={FIELD}><option value="">Select…</option>{targetList.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select><InputError message={isUnit ? (e.target_ref || e.target_id) : e.target_id} />{isUnit && targetList.length === 0 && <p className="mt-1 text-[11px] text-[#8A6400]">No business units defined. Add them under Settings, FinOps & showback.</p>}</div>
                <div><label className={LABEL} htmlFor="al-me">Method</label><select id="al-me" disabled={locked} value={d.method} onChange={set('method')} className={FIELD}>{options.methods.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.method} /></div>
                {d.method === 'percentage' && <div><label className={LABEL} htmlFor="al-pc">Percentage</label><input id="al-pc" type="number" min="0.0001" max="100" step="0.0001" value={d.percentage} onChange={set('percentage')} className={FIELD} /><InputError message={e.percentage} /></div>}
                {d.method === 'direct' && <p className="self-end pb-2 text-xs text-[#718096]">Direct assigns 100% of the source and allows a single rule per source and lens.</p>}
                {d.method === 'driver' && (
                    <>
                        <div><label className={LABEL} htmlFor="al-dr">Driver</label><select id="al-dr" value={d.driver_id} onChange={set('driver_id')} className={FIELD}><option value="">Select…</option>{drivers.map((x) => <option key={x.id} value={x.id}>{x.code} {x.name} ({x.unit})</option>)}</select><InputError message={e.driver_id} /></div>
                        <div><label className={LABEL} htmlFor="al-dq">Driver quantity for this target</label><input id="al-dq" type="number" min="0" step="0.0001" value={d.driver_quantity} onChange={set('driver_quantity')} className={FIELD} /><InputError message={e.driver_quantity} /><p className="mt-1 text-[11px] text-[#718096]">Share = this quantity over the sum across the rules in the group.</p></div>
                    </>
                )}
                <div><label className={LABEL} htmlFor="al-ef">Effective from</label><input id="al-ef" type="date" min={firstOpenDate || undefined} value={d.effective_from} onChange={set('effective_from')} className={FIELD} /><InputError message={e.effective_from} />{firstOpenDate && <p className="mt-1 text-[11px] text-[#718096]">Not earlier than {firstOpenDate}, the first open period.</p>}</div>
                <div><label className={LABEL} htmlFor="al-et">Effective to (optional)</label><input id="al-et" type="date" value={d.effective_to} onChange={set('effective_to')} className={FIELD} /><InputError message={e.effective_to} /></div>
                <div className="sm:col-span-2"><label className={LABEL} htmlFor="al-jc">Journal cost-centre code (ERP receiving cost centre, optional)</label><input id="al-jc" maxLength={32} value={d.journal_cost_centre_code} onChange={set('journal_cost_centre_code')} className={`${FIELD} font-mono`} /><InputError message={e.journal_cost_centre_code} /></div>
            </div>
            <InputError message={e.allocation || e.group} />
        </FormModal>
    );
}

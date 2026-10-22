import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const BLANK_FIELDS = { sponsor_id: '', manager_id: '', cost_centre_id: '', rgt: '', is_regulatory: '' };

/**
 * Request a change. Scope (cost or schedule): within tolerance it applies at once, beyond it goes to approval.
 * Reassign (sponsor, manager, cost centre, run/grow/transform, regulatory flag): always approved through the change gate.
 */
export default function ChangeRequestModal({ show, onClose, investment, baseCurrency, viewAmounts = true, reasonMin = 20, users = [], costCentres = [], reassignFields = [] }) {
    const form = useForm({ kind: 'scope', description: '', reason: '', cost_delta: '0', schedule_delta_days: '0', field_changes: BLANK_FIELDS });
    useEffect(() => { if (show) { form.reset(); form.clearErrors(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [show]);
    const e = form.errors; const d = form.data;
    const reassign = d.kind === 'reassign';
    const allowed = (f) => reassignFields.length === 0 || reassignFields.some((o) => o.value === f);
    const setField = (k) => (ev) => form.setData('field_changes', { ...d.field_changes, [k]: ev.target.value });
    const changed = Object.entries(d.field_changes).filter(([, v]) => v !== '');
    const submit = () => {
        form.transform((x) => {
            if (x.kind !== 'reassign') return { kind: 'scope', description: x.description, reason: x.reason, cost_delta: x.cost_delta, schedule_delta_days: x.schedule_delta_days };
            const fc = {};
            changed.forEach(([k, v]) => { fc[k] = k === 'is_regulatory' ? v === '1' : ['sponsor_id', 'manager_id', 'cost_centre_id'].includes(k) ? Number(v) : v; });
            return { kind: 'reassign', description: x.description || null, reason: x.reason, field_changes: fc };
        });
        form.post(route('finance.investment-changes.store', investment.id), { preserveScroll: true, onSuccess: onClose });
    };
    const ready = d.reason.trim().length >= reasonMin && (reassign ? changed.length > 0 : !!d.description.trim());
    const userSel = (k, current) => (
        <select id={`cr-${k}`} value={d.field_changes[k]} onChange={setField(k)} className={FIELD}><option value="">No change{current ? ` (${current.name})` : ''}</option>{users.filter((u) => u.id !== current?.id).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
    );
    const fcError = Object.entries(e).find(([k]) => k.startsWith('field_changes'))?.[1];
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !ready} title="Request a change">
            <div role="group" aria-label="Kind of change" className="flex gap-1">
                {[['scope', 'Scope (cost or schedule)'], ['reassign', 'Reassign (owners, cost centre)']].map(([k, label]) => (
                    <button key={k} type="button" aria-pressed={d.kind === k} onClick={() => form.setData('kind', k)} className={`rounded-lg border px-3 py-1.5 text-sm ${d.kind === k ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#4A5568]'}`}>{label}</button>
                ))}
            </div>
            {!reassign && (
                <>
                    <div><label className={LABEL} htmlFor="ch-d">What changes</label><textarea id="ch-d" rows={2} value={d.description} onChange={(ev) => form.setData('description', ev.target.value)} className={FIELD} /><InputError message={e.description} /></div>
                    <div className="grid grid-cols-2 gap-3">
                        {viewAmounts && <div><label className={LABEL} htmlFor="ch-c">Cost change ({baseCurrency}, negative to reduce)</label><input id="ch-c" type="number" step="any" value={d.cost_delta} onChange={(ev) => form.setData('cost_delta', ev.target.value)} className={FIELD} /><InputError message={e.cost_delta} /></div>}
                        <div><label className={LABEL} htmlFor="ch-s">Schedule change (days)</label><input id="ch-s" type="number" step="1" value={d.schedule_delta_days} onChange={(ev) => form.setData('schedule_delta_days', ev.target.value)} className={FIELD} /><InputError message={e.schedule_delta_days} /></div>
                    </div>
                </>
            )}
            {reassign && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {allowed('sponsor_id') && <div><label className={LABEL} htmlFor="cr-sponsor_id">Sponsor</label>{userSel('sponsor_id', investment.sponsor)}</div>}
                    {allowed('manager_id') && <div><label className={LABEL} htmlFor="cr-manager_id">Manager</label>{userSel('manager_id', investment.manager)}</div>}
                    {allowed('cost_centre_id') && costCentres.length > 0 && <div><label className={LABEL} htmlFor="cr-cost_centre_id">Cost centre</label><select id="cr-cost_centre_id" value={d.field_changes.cost_centre_id} onChange={setField('cost_centre_id')} className={FIELD}><option value="">No change</option>{costCentres.filter((c) => c.id !== investment.cost_centre?.id).map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select></div>}
                    {allowed('rgt') && <div><label className={LABEL} htmlFor="cr-rgt">Run, grow or transform</label><select id="cr-rgt" value={d.field_changes.rgt} onChange={setField('rgt')} className={FIELD}><option value="">No change</option>{['run', 'grow', 'transform'].filter((r) => r !== investment.rgt).map((r) => <option key={r} value={r}>{r[0].toUpperCase() + r.slice(1)}</option>)}</select></div>}
                    {allowed('is_regulatory') && <div><label className={LABEL} htmlFor="cr-is_regulatory">Regulatory mandate</label><select id="cr-is_regulatory" value={d.field_changes.is_regulatory} onChange={setField('is_regulatory')} className={FIELD}><option value="">No change</option>{investment.is_regulatory ? <option value="0">Not regulatory</option> : <option value="1">Regulatory</option>}</select></div>}
                    <div className="sm:col-span-2"><InputError message={fcError} /></div>
                </div>
            )}
            <div><label className={LABEL} htmlFor="ch-r">Reason (at least {reasonMin} characters)</label><textarea id="ch-r" rows={2} value={d.reason} onChange={(ev) => form.setData('reason', ev.target.value)} className={FIELD} /><InputError message={e.reason} /></div>
            <p className="text-xs text-[#718096]">{reassign ? 'A reassignment always needs approval. Open value reviews follow a changed sponsor on approval.' : `Tolerance: ${Number(investment.cost_tolerance_pct)}% of the budget at completion in total, or the schedule limit. Beyond it the change waits for approval.`}</p>
            <InputError message={e.change} />
        </FormModal>
    );
}

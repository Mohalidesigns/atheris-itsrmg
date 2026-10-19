import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { Card, SaveBar } from './ui';

/** Cloud anomaly thresholds, the bank's showback business units and chargeback GL codes. */
export default function FinOpsTab({ policy, baseCurrency, can }) {
    const def = policy.defaults ?? {};
    const form = useForm({
        section: 'finops',
        cloud_anomaly_pct: policy.cloud_anomaly_pct ?? '',
        cloud_anomaly_abs_floor: policy.cloud_anomaly_abs_floor ?? '',
        showback_units: (policy.showback_units ?? []).map((u) => ({ code: u.code, name: u.name, erp_cost_centre_code: u.erp_cost_centre_code ?? '' })),
        chargeback_debit_gl: policy.chargeback_debit_gl ?? '',
        chargeback_credit_gl: policy.chargeback_credit_gl ?? '',
    });
    const d = form.data; const e = form.errors;
    const setUnit = (i, patch) => form.setData('showback_units', d.showback_units.map((u, j) => (j === i ? { ...u, ...patch } : u)));
    const submit = (ev) => {
        ev.preventDefault();
        form.transform((x) => ({
            ...x,
            cloud_anomaly_pct: x.cloud_anomaly_pct === '' ? null : x.cloud_anomaly_pct,
            cloud_anomaly_abs_floor: x.cloud_anomaly_abs_floor === '' ? null : x.cloud_anomaly_abs_floor,
            chargeback_debit_gl: x.chargeback_debit_gl === '' ? null : x.chargeback_debit_gl,
            chargeback_credit_gl: x.chargeback_credit_gl === '' ? null : x.chargeback_credit_gl,
            showback_units: x.showback_units.filter((u) => u.code.trim() || u.name.trim()).map((u) => ({ ...u, code: u.code.trim().toUpperCase(), erp_cost_centre_code: u.erp_cost_centre_code || null })),
        }));
        form.put(route('finance.settings.update'), { preserveScroll: true });
    };
    const unitErr = Object.entries(e).filter(([k]) => k.startsWith('showback_units')).map(([, v]) => v);
    return (
        <Card title="FinOps & showback" right={<Chip size="xs" tone={policy.showback_enabled ? 'success' : 'muted'}>{policy.showback_enabled ? 'Showback on' : 'Showback off'}</Chip>}>
            <form onSubmit={submit}>
                <fieldset disabled={!can.manage} className="space-y-5 px-5 py-4">
                    <div>
                        <p className="text-sm font-medium text-[#2D3748]">Cloud anomaly detection</p>
                        <p className="text-xs text-[#718096]">A day is flagged when it exceeds its baseline by the percentage and by at least the floor. Blank uses the default.</p>
                        <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div><label className={LABEL} htmlFor="fo-pct">Deviation threshold (%)</label><input id="fo-pct" type="number" min="1" max="1000" step="0.01" value={d.cloud_anomaly_pct} onChange={(ev) => form.setData('cloud_anomaly_pct', ev.target.value)} placeholder={def.cloud_anomaly_pct ?? '30'} className={FIELD} /><InputError message={e.cloud_anomaly_pct} /></div>
                            <div><label className={LABEL} htmlFor="fo-floor">Absolute floor ({baseCurrency})</label><input id="fo-floor" type="number" min="0" step="0.01" value={d.cloud_anomaly_abs_floor} onChange={(ev) => form.setData('cloud_anomaly_abs_floor', ev.target.value)} placeholder={def.cloud_anomaly_abs_floor ?? '250000'} className={FIELD} /><InputError message={e.cloud_anomaly_abs_floor} /></div>
                        </div>
                    </div>
                    <div>
                        <p className="text-sm font-medium text-[#2D3748]">Showback business units</p>
                        <p className="text-xs text-[#718096]">The consumers IT cost is shown back to (up to 50). The ERP cost centre code is the receiving side of the chargeback journal.</p>
                        <div className="mt-2 space-y-2">
                            {d.showback_units.map((u, i) => (
                                <div key={i} className="grid grid-cols-1 gap-2 sm:grid-cols-[8rem_1fr_10rem_auto] sm:items-center">
                                    <input aria-label="Unit code" maxLength={16} placeholder="Code" value={u.code} onChange={(ev) => setUnit(i, { code: ev.target.value.toUpperCase() })} className={`${FIELD} mt-0 font-mono`} />
                                    <input aria-label="Unit name" maxLength={120} placeholder="Name" value={u.name} onChange={(ev) => setUnit(i, { name: ev.target.value })} className={`${FIELD} mt-0`} />
                                    <input aria-label="ERP cost centre code" maxLength={32} placeholder="ERP cost centre" value={u.erp_cost_centre_code} onChange={(ev) => setUnit(i, { erp_cost_centre_code: ev.target.value })} className={`${FIELD} mt-0 font-mono`} />
                                    <button type="button" onClick={() => form.setData('showback_units', d.showback_units.filter((_, j) => j !== i))} className="text-xs text-[#B3261E] underline">Remove</button>
                                </div>
                            ))}
                        </div>
                        {can.manage && <button type="button" disabled={d.showback_units.length >= 50} onClick={() => form.setData('showback_units', [...d.showback_units, { code: '', name: '', erp_cost_centre_code: '' }])} className="mt-2 text-xs text-[#0A1F44] underline">Add business unit</button>}
                        {unitErr.map((m, i) => <InputError key={i} message={m} />)}
                        <InputError message={e.showback_units} />
                    </div>
                    <div>
                        <p className="text-sm font-medium text-[#2D3748]">Chargeback journal GL codes</p>
                        <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div><label className={LABEL} htmlFor="fo-dr">Debit GL (receiving side)</label><input id="fo-dr" maxLength={32} value={d.chargeback_debit_gl} onChange={(ev) => form.setData('chargeback_debit_gl', ev.target.value)} placeholder={def.chargeback_debit_gl ?? '6950'} className={`${FIELD} font-mono`} /><InputError message={e.chargeback_debit_gl} /></div>
                            <div><label className={LABEL} htmlFor="fo-cr">Credit GL (IT recovery)</label><input id="fo-cr" maxLength={32} value={d.chargeback_credit_gl} onChange={(ev) => form.setData('chargeback_credit_gl', ev.target.value)} placeholder={def.chargeback_credit_gl ?? '6951'} className={`${FIELD} font-mono`} /><InputError message={e.chargeback_credit_gl} /></div>
                        </div>
                    </div>
                </fieldset>
                {can.manage && <SaveBar processing={form.processing} />}
            </form>
        </Card>
    );
}

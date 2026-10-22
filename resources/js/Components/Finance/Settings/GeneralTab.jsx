import InputError from '@/Components/InputError';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { Card, Check, SaveBar } from './ui';

export default function GeneralTab({ settings, options, baseCurrency, can }) {
    const form = useForm({
        section: 'general',
        fiscal_year_start_month: settings.fiscal_year_start_month,
        period_scheme: settings.period_scheme,
        overbudget_mode: settings.overbudget_mode,
        match_tolerance_pct: settings.match_tolerance_pct,
        match_tolerance_abs: settings.match_tolerance_abs,
        eac_method: settings.eac_method,
        showback_enabled: !!settings.showback_enabled,
        digest_hour: settings.digest_hour,
    });
    const d = form.data;
    const set = (k) => (e) => form.setData(k, e.target.value);
    const dis = !can.manage;
    return (
        <Card title="General">
            <form onSubmit={(e) => { e.preventDefault(); form.put(route('finance.settings.update'), { preserveScroll: true }); }}>
                <fieldset disabled={dis} className="space-y-4 px-5 py-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div><label className={LABEL}>Fiscal year starts in</label>
                            <select value={d.fiscal_year_start_month} onChange={set('fiscal_year_start_month')} className={FIELD}>{options.months.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}</select>
                            <InputError message={form.errors.fiscal_year_start_month} /></div>
                        <div><label className={LABEL}>Period scheme</label>
                            <select value={d.period_scheme} onChange={set('period_scheme')} className={FIELD}>{options.periodSchemes.map((s) => <option key={s} value={s}>{s === '4-4-5' ? '4-4-5 weeks' : humanize(s)}</option>)}</select>
                            <InputError message={form.errors.period_scheme} /></div>
                        <div><label className={LABEL}>EAC method</label>
                            <select value={d.eac_method} onChange={set('eac_method')} className={FIELD}>{options.eacMethods.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</select>
                            <InputError message={form.errors.eac_method} /></div>
                    </div>
                    <div>
                        <p className={LABEL}>Over-budget mode</p>
                        <div className="mt-1 flex flex-wrap gap-4">
                            {options.overbudgetModes.map((m) => (
                                <label key={m} className="flex items-center gap-2 text-sm text-[#2D3748]">
                                    <input type="radio" name="overbudget_mode" value={m} checked={d.overbudget_mode === m} onChange={set('overbudget_mode')} /> {humanize(m)}
                                </label>
                            ))}
                        </div>
                        <p className="mt-1 text-xs text-[#718096]">Block refuses submission over availability; warn allows it with a justification.</p>
                        <InputError message={form.errors.overbudget_mode} />
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div><label className={LABEL}>Three-way match tolerance (%)</label>
                            <input type="number" step="0.01" min="0" max="100" value={d.match_tolerance_pct} onChange={set('match_tolerance_pct')} className={FIELD} />
                            <InputError message={form.errors.match_tolerance_pct} /></div>
                        <div><label className={LABEL}>Match tolerance, absolute ({baseCurrency})</label>
                            <input type="number" step="0.01" min="0" value={d.match_tolerance_abs} onChange={set('match_tolerance_abs')} className={FIELD} />
                            <InputError message={form.errors.match_tolerance_abs} /></div>
                        <div><label className={LABEL}>Daily digest hour (0 to 23)</label>
                            <input type="number" min="0" max="23" value={d.digest_hour} onChange={set('digest_hour')} className={FIELD} />
                            <InputError message={form.errors.digest_hour} /></div>
                    </div>
                    <Check label="Enable showback" checked={d.showback_enabled} onChange={(v) => form.setData('showback_enabled', v)} />
                    <InputError message={form.errors.showback_enabled} />
                </fieldset>
                {can.manage && <SaveBar processing={form.processing} />}
            </form>
        </Card>
    );
}

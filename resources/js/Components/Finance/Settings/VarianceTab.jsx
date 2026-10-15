import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { FIELD, LABEL, formatMoney, formatPct } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { Card, SaveBar } from './ui';

export default function VarianceTab({ settings, baseCurrency, can }) {
    const form = useForm({
        section: 'variance',
        variance_amber_pct: settings.variance_amber_pct,
        variance_red_pct: settings.variance_red_pct,
        variance_abs_floor: settings.variance_abs_floor,
    });
    const d = form.data;
    const amber = Number(d.variance_amber_pct);
    const red = Number(d.variance_red_pct);
    const bad = !(amber > 0 && red > amber);
    const set = (k) => (e) => form.setData(k, e.target.value);
    return (
        <Card title="Variance thresholds">
            <form onSubmit={(e) => { e.preventDefault(); if (!bad) form.put(route('finance.settings.update'), { preserveScroll: true }); }}>
                <fieldset disabled={!can.manage} className="space-y-4 px-5 py-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div><label className={LABEL}>Amber from (%)</label><input type="number" step="0.01" min="0.01" max="100" value={d.variance_amber_pct} onChange={set('variance_amber_pct')} className={FIELD} /><InputError message={form.errors.variance_amber_pct} /></div>
                        <div><label className={LABEL}>Red from (%)</label><input type="number" step="0.01" min="0.01" max="1000" value={d.variance_red_pct} onChange={set('variance_red_pct')} className={FIELD} /><InputError message={form.errors.variance_red_pct} /></div>
                        <div><label className={LABEL}>Absolute floor ({baseCurrency})</label><input type="number" step="0.01" min="0" value={d.variance_abs_floor} onChange={set('variance_abs_floor')} className={FIELD} /><InputError message={form.errors.variance_abs_floor} /></div>
                    </div>
                    {bad && <p className="text-xs text-[#B3261E]">Red threshold must be greater than amber.</p>}
                    <div>
                        <p className={LABEL}>Preview</p>
                        <div className="mt-1 flex flex-wrap gap-2">
                            <Chip tone="success">Under {formatPct(amber)}</Chip>
                            <Chip tone="warning">{formatPct(amber)} to {formatPct(red)}</Chip>
                            <Chip tone="error">{formatPct(red)} and above</Chip>
                        </div>
                        <p className="mt-2 text-xs text-[#718096]">Variances smaller than {formatMoney(d.variance_abs_floor || 0, baseCurrency, { decimals: 0 })} are not flagged, whatever the percentage.</p>
                    </div>
                </fieldset>
                {can.manage && <SaveBar processing={form.processing} dirty={!bad} />}
            </form>
        </Card>
    );
}

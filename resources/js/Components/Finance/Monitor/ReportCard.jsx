import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import Icon from '@/Components/Material/Icon';
import { BTN_SECONDARY, FIELD, LABEL } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import ReportPreview from './ReportPreview';

const WINDOWS = [['all', 'All live contracts'], ['30', 'Within 30 days'], ['60', 'Within 60 days'], ['90', 'Within 90 days']];
const FORMATS = { xlsx: 'Excel', csv: 'CSV', pdf: 'PDF' };

/** One report: its own filter form, a preview (partial reload) and plain-link downloads. */
export default function ReportCard({ report, active, initial, fiscalYears, periods, costCentres, preview, baseCurrency, money, canExport }) {
    const [f, setF] = useState(() => ({ fiscal_year_id: '', from_period_id: '', to_period_id: '', cost_centre_id: '', window: 'all', as_of: '', ...(active ? initial : {}) }));
    const [busy, setBusy] = useState(false);
    const has = (k) => report.filters.includes(k);
    const set = (k) => (e) => setF({ ...f, [k]: e.target.value, ...(k === 'fiscal_year_id' ? { from_period_id: '', to_period_id: '' } : {}) });
    const params = Object.fromEntries(Object.entries(f).filter(([k, v]) => v !== '' && v !== null && !(k === 'window' && v === 'all') && ((has(k)) || (k.endsWith('_period_id') && has('period_range')))));
    const periodOptions = periods.filter((p) => !f.fiscal_year_id || String(p.fiscal_year_id) === String(f.fiscal_year_id));
    const load = () => {
        setBusy(true);
        router.reload({ only: ['preview', 'filters'], data: { report: report.key, ...params }, preserveScroll: true, onFinish: () => setBusy(false) });
    };
    return (
        <section className="rounded-lg border border-gray-200 bg-white" aria-label={report.title}>
            <div className="px-5 py-4">
                <h3 className="text-sm font-semibold text-[#0A1F44]">{report.title}</h3>
                <p className="mt-1 text-xs text-[#718096]">{report.description}</p>
            </div>
            {report.filters.length > 0 && (
                <div className="grid grid-cols-2 gap-3 px-5 pb-3">
                    {has('fiscal_year_id') && <SelectFilter id={`${report.key}-fy`} label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>}
                    {has('period_range') && <SelectFilter id={`${report.key}-from`} label="From period" value={f.from_period_id} onChange={set('from_period_id')} all="First">{periodOptions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>}
                    {has('period_range') && <SelectFilter id={`${report.key}-to`} label="To period" value={f.to_period_id} onChange={set('to_period_id')} all="Last ended">{periodOptions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>}
                    {has('cost_centre_id') && <SelectFilter id={`${report.key}-cc`} label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>}
                    {has('window') && <div><label className={LABEL} htmlFor={`${report.key}-win`}>Window</label><select id={`${report.key}-win`} value={f.window} onChange={set('window')} className={FIELD}>{WINDOWS.map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select></div>}
                    {has('as_of') && <div><label className={LABEL} htmlFor={`${report.key}-asof`}>As of</label><input id={`${report.key}-asof`} type="date" value={f.as_of} onChange={set('as_of')} className={FIELD} /></div>}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-2 px-5 pb-4">
                <button type="button" onClick={load} disabled={busy} className={`${BTN_SECONDARY} flex items-center gap-1`}><Icon name="visibility" size={18} />{busy ? 'Loading…' : 'Preview'}</button>
                {canExport && report.formats.map((fmt) => (
                    <a key={fmt} href={route('finance.reports.show', { report: report.key, format: fmt, ...params })} className={`${BTN_SECONDARY} flex items-center gap-1`}><Icon name="download" size={18} />{FORMATS[fmt] ?? fmt.toUpperCase()}</a>
                ))}
            </div>
            {active && <ReportPreview preview={preview} baseCurrency={baseCurrency} money={money} />}
        </section>
    );
}

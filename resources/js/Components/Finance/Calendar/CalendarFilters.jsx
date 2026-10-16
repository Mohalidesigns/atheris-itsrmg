import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { normOpts } from './calendarUtils';

/** Filters re-request the page through `onChange(nextFilters)`. An empty `sources` list means every source. */
export default function CalendarFilters({ filters, options, onChange }) {
    const f = filters;
    const set = (k) => (e) => onChange({ ...f, [k]: e.target.value || null });
    const sources = normOpts(options.sources, humanize);
    const sel = f.sources ?? [];
    const toggleSource = (v) => onChange({ ...f, sources: sel.includes(v) ? sel.filter((x) => x !== v) : [...sel, v] });
    const pick = (id, label, key, list) => (
        <div><label className={LABEL} htmlFor={id}>{label}</label>
            <select id={id} value={f[key] ?? ''} onChange={set(key)} className={FIELD}><option value="">All</option>{list.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></div>
    );
    const active = Object.entries(f).some(([k, v]) => (k === 'sources' ? (v ?? []).length > 0 : v !== null && v !== undefined && v !== ''));
    return (
        <div className="mb-4 rounded-lg border border-gray-200 bg-white p-3 print:hidden">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                {pick('cf-vendor', 'Vendor', 'vendor_id', normOpts(options.vendors))}
                {pick('cf-owner', 'Owner', 'owner_id', normOpts(options.owners))}
                {pick('cf-cc', 'Cost centre', 'cost_centre_id', normOpts(options.costCentres))}
                {pick('cf-type', 'Type', 'type', normOpts(options.types, humanize))}
                {pick('cf-band', 'Value band', 'value_band', normOpts(options.valueBands, humanize))}
                {pick('cf-status', 'Status', 'status', normOpts(options.statuses, humanize))}
                <label className="flex items-end gap-2 pb-2 text-sm text-[#2D3748]"><input type="checkbox" checked={f.member === 'me'} onChange={(e) => onChange({ ...f, member: e.target.checked ? 'me' : null })} /> My contracts</label>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-2" role="group" aria-label="Sources">
                <span className="text-xs text-[#718096]">Sources</span>
                {sources.map((s) => (
                    <button key={s.value} type="button" aria-pressed={sel.includes(s.value)} onClick={() => toggleSource(s.value)}
                        className={`rounded-lg border px-2.5 py-1 text-xs ${sel.includes(s.value) ? 'border-[#0A1F44] bg-[#0A1F44] text-white' : 'border-gray-200 text-[#2D3748]'}`}>{s.label}</button>
                ))}
                {active && <button type="button" onClick={() => onChange({ vendor_id: null, owner_id: null, member: null, cost_centre_id: null, type: null, value_band: null, status: null, sources: [] })} className="ml-auto text-xs text-[#0A1F44] underline">Clear filters</button>}
            </div>
        </div>
    );
}

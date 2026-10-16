import { PlusIcon, TrashIcon } from '@/Components/Material/Symbols';
import { BTN_PRIMARY, BTN_SECONDARY, formatMoney, formatPct, fromCents, humanize, sumDecimal, toCents } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

const CELL = 'w-full rounded border border-transparent bg-transparent px-1.5 py-1 text-sm focus:border-[#C9A86A] focus:bg-white focus:outline-none disabled:text-[#2D3748]';
const NUM = `${CELL} text-right tabular-nums`;
const STICKY = 'sticky left-0 z-10 bg-white';

let seq = 0;
const newKey = () => `n${++seq}`;

const fromLine = (l) => ({
    key: `l${l.id}`, id: l.id, line_no: l.line_no, description: l.description, cost_pool_id: String(l.cost_pool_id), tower_id: String(l.tower_id),
    priority: l.priority, is_capex: l.is_capex, phasing_method: l.phasing_method, currency: l.currency, fx_rate: l.fx_rate,
    annual_amount: l.annual_amount, amounts: { ...l.amounts }, py_budget: l.py_budget, yoy_pct: l.yoy_pct,
    touched: new Set(), stale: false,
});

const blankRow = (dims, baseCurrency, periods) => ({
    key: newKey(), id: null, line_no: null, description: '', cost_pool_id: '', tower_id: '', priority: dims.priorities[0] ?? 'committed', is_capex: false,
    phasing_method: 'equal', currency: baseCurrency, fx_rate: '1', annual_amount: '', amounts: Object.fromEntries(periods.map((p) => [p.id, ''])),
    py_budget: null, yoy_pct: null, touched: new Set(['description']), stale: false,
});

/** Spread annual evenly across periods in cents; remainder goes to the last period. */
function equalSplit(annual, periods) {
    const total = toCents(annual);
    const n = BigInt(periods.length || 1);
    const base = total / n;
    const out = {};
    let used = 0n;
    periods.forEach((p, i) => {
        const v = i === periods.length - 1 ? total - used : base;
        used += v;
        out[p.id] = fromCents(v);
    });
    return out;
}

export default function WorkbookGrid({ budget, lines, periods, dims, baseCurrency, readOnly, errors }) {
    const [rows, setRows] = useState(() => lines.map(fromLine));
    const [deleted, setDeleted] = useState([]);
    const [saving, setSaving] = useState(false);
    const sentKeys = useRef([]);
    const gridRef = useRef(null);
    const dirty = deleted.length > 0 || rows.some((r) => r.id === null || r.touched.size > 0);

    // Re-seed after a successful server save (lines prop changes identity).
    useEffect(() => { setRows(lines.map(fromLine)); setDeleted([]); }, [lines]);
    useEffect(() => {
        const warn = (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const cellErrors = useMemo(() => {
        const map = {};
        Object.entries(errors || {}).forEach(([k, msg]) => {
            const m = k.match(/^lines\.(\d+)\.(.+)$/);
            if (!m) return;
            const key = sentKeys.current[Number(m[1])];
            if (key) (map[key] ||= {})[m[2]] = msg;
        });
        return map;
    }, [errors]);
    const generalError = Object.entries(errors || {}).filter(([k]) => !k.startsWith('lines.')).map(([, v]) => v)[0];

    const patch = (key, changes, touched) => setRows((rs) => rs.map((r) => {
        if (r.key !== key) return r;
        const t = new Set(r.touched);
        touched.forEach((x) => t.add(x));
        return { ...r, ...changes, touched: t };
    }));
    const setField = (key, f, v) => patch(key, { [f]: v }, [f]);
    const setAnnual = (r, v) => {
        const ch = { annual_amount: v, stale: r.phasing_method !== 'equal' && r.phasing_method !== 'custom' };
        if (r.phasing_method === 'equal' || r.phasing_method === 'custom') {
            // keep the periods consistent with the new annual figure
            if (r.phasing_method === 'equal') ch.amounts = equalSplit(v, periods);
        }
        patch(r.key, ch, ['annual_amount']);
    };
    const setPeriod = (r, pid, v) => {
        const amounts = { ...r.amounts, [pid]: v };
        patch(r.key, { amounts, phasing_method: 'custom', annual_amount: sumDecimal(periods.map((p) => amounts[p.id])), stale: false }, ['amounts', 'phasing_method', 'annual_amount']);
    };
    const setPhasing = (r, m) => {
        const ch = { phasing_method: m };
        if (m === 'equal' && r.annual_amount !== '') ch.amounts = equalSplit(r.annual_amount, periods);
        else if (m !== 'custom') ch.stale = true;
        patch(r.key, ch, ['phasing_method']);
    };
    const addRow = () => setRows((rs) => [...rs, blankRow(dims, baseCurrency, periods)]);
    const removeRow = (r) => {
        if (r.id !== null) setDeleted((d) => [...d, r.id]);
        setRows((rs) => rs.filter((x) => x.key !== r.key));
    };

    // Column order for keyboard navigation and paste.
    const colCount = 8 + periods.length;
    const focusCell = (r, c) => {
        const el = gridRef.current?.querySelector(`[data-r="${r}"][data-c="${c}"]`);
        if (el) { el.focus(); if (el.select) el.select(); }
    };
    const onKeyDown = (e, r, c) => {
        const t = e.target;
        const text = t.tagName === 'INPUT' && t.type !== 'checkbox';
        const atStart = !text || t.type === 'number' || (t.selectionStart === 0 && t.selectionEnd === 0);
        const atEnd = !text || t.type === 'number' || (t.selectionStart === t.value.length);
        if (e.key === 'ArrowDown' && t.tagName !== 'SELECT') { e.preventDefault(); focusCell(r + 1, c); }
        else if (e.key === 'ArrowUp' && t.tagName !== 'SELECT') { e.preventDefault(); focusCell(r - 1, c); }
        else if (e.key === 'Enter') { e.preventDefault(); focusCell(r + (e.shiftKey ? -1 : 1), c); }
        else if (e.key === 'ArrowRight' && atEnd && t.tagName !== 'SELECT') { e.preventDefault(); focusCell(r, c + 1); }
        else if (e.key === 'ArrowLeft' && atStart && t.tagName !== 'SELECT') { e.preventDefault(); focusCell(r, c - 1); }
    };

    // Paste a TSV block starting at the focused cell: description (c0), annual (c7), periods (c8..).
    const onPaste = (e, r, c) => {
        if (readOnly) return;
        const text = e.clipboardData.getData('text');
        if (!text || (!text.includes('\t') && !text.includes('\n'))) return;
        e.preventDefault();
        const grid = text.replace(/\r/g, '').replace(/\n$/, '').split('\n').map((l) => l.split('\t'));
        const cleanNum = (s) => s.replace(/[,\s]/g, '');
        setRows((rs) => {
            const next = rs.slice();
            grid.forEach((cells, dr) => {
                if (r + dr >= next.length) next.push(blankRow(dims, baseCurrency, periods));
                let row = { ...next[r + dr], touched: new Set(next[r + dr].touched), amounts: { ...next[r + dr].amounts } };
                cells.forEach((val, dc) => {
                    const col = c + dc;
                    if (col === 0) { row.description = val; row.touched.add('description'); }
                    else if (col === 7) { row.annual_amount = cleanNum(val); row.touched.add('annual_amount'); if (row.phasing_method === 'equal') row.amounts = equalSplit(row.annual_amount, periods); else if (row.phasing_method !== 'custom') row.stale = true; }
                    else if (col >= 8 && col < colCount) {
                        row.amounts[periods[col - 8].id] = cleanNum(val); row.phasing_method = 'custom'; row.stale = false;
                        row.touched.add('amounts'); row.touched.add('phasing_method');
                        row.annual_amount = sumDecimal(periods.map((p) => row.amounts[p.id]));
                        row.touched.add('annual_amount');
                    }
                });
                next[r + dr] = row;
            });
            return next;
        });
    };

    const save = () => {
        const send = rows.filter((r) => r.id === null || r.touched.size > 0);
        sentKeys.current = send.map((r) => r.key);
        const payload = send.map((r) => {
            const line = {
                id: r.id, description: r.description, cost_pool_id: r.cost_pool_id || null, tower_id: r.tower_id || null, priority: r.priority,
                is_capex: r.is_capex, phasing_method: r.phasing_method, currency: r.currency,
            };
            if (r.phasing_method === 'custom') line.amounts = Object.fromEntries(periods.map((p) => [p.id, r.amounts[p.id] === '' ? '0' : r.amounts[p.id]]));
            if (r.annual_amount !== '') line.annual_amount = r.annual_amount;
            return line;
        });
        setSaving(true);
        router.put(route('finance.budgets.lines.bulk', budget.id), { lines: payload, deleted_ids: deleted }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    const baseOf = (r, v) => (r.currency === baseCurrency ? v : fromCents(BigInt(Math.round(Number(v || 0) * Number(r.fx_rate || 1) * 100))));
    const periodTotals = periods.map((p) => sumDecimal(rows.map((r) => baseOf(r, r.amounts[p.id] || 0))));
    const annualTotal = sumDecimal(rows.map((r) => baseOf(r, r.annual_amount || 0)));
    const pyTotal = sumDecimal(rows.map((r) => r.py_budget || 0));
    const poolOpts = dims.costPools, towerOpts = dims.towers;

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center gap-2">
                {!readOnly && <button type="button" onClick={addRow} className={`${BTN_SECONDARY} inline-flex items-center gap-1`}><PlusIcon className="h-4 w-4" /> Add row</button>}
                {!readOnly && <button type="button" onClick={save} disabled={!dirty || saving} className={BTN_PRIMARY}>{saving ? 'Saving…' : 'Save workbook'}</button>}
                {dirty && <span className="text-xs text-[#8C5A00]">Unsaved changes</span>}
                {generalError && <span className="text-xs text-[#B3261E]">{generalError}</span>}
                <span className="ml-auto text-xs text-[#718096]">Arrows and Enter move between cells. Paste a block from a spreadsheet into a description, annual or period cell. Totals are in {baseCurrency}.</span>
            </div>
            <div ref={gridRef} className="max-h-[70vh] overflow-auto rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full border-separate border-spacing-0 text-sm">
                    <thead className="sticky top-0 z-20 bg-gray-50 text-xs uppercase tracking-wide text-[#718096]">
                        <tr>
                            <th className={`${STICKY} bg-gray-50 px-2 py-2 text-left`}>#</th>
                            {['Description', 'Pool', 'Tower', 'Priority', 'CapEx', 'Phasing', 'Annual'].map((h) => <th key={h} className="whitespace-nowrap px-2 py-2 text-left font-medium">{h}</th>)}
                            {periods.map((p) => <th key={p.id} title={p.name} className="whitespace-nowrap px-2 py-2 text-right font-medium">P{String(p.sequence).padStart(2, '0')}</th>)}
                            <th className="px-2 py-2 text-right font-medium">Σ</th><th className="px-2 py-2 text-right font-medium">Base</th><th className="px-2 py-2 text-right font-medium">PY</th><th className="px-2 py-2 text-right font-medium">YoY</th><th />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && <tr><td colSpan={colCount + 6} className="px-4 py-8 text-center text-sm text-[#718096]">No lines yet. Add a row, paste from a spreadsheet or import a CSV.</td></tr>}
                        {rows.map((r, ri) => {
                            const ce = cellErrors[r.key] || {};
                            const sum = sumDecimal(periods.map((p) => r.amounts[p.id] || 0));
                            const off = !r.stale && r.annual_amount !== '' && toCents(sum) !== toCents(r.annual_amount);
                            const cellProps = (c) => ({ 'data-r': ri, 'data-c': c, onKeyDown: (e) => onKeyDown(e, ri, c), onPaste: (e) => onPaste(e, ri, c), disabled: readOnly });
                            const err = (f) => ce[f] ? { 'aria-invalid': true, title: ce[f], className: undefined } : {};
                            return (
                                <tr key={r.key} className={r.id === null ? 'bg-[#FBF7EE]' : ''}>
                                    <td className={`${STICKY} px-2 py-1 font-mono text-xs text-[#718096]`}>{r.line_no ?? '+'}</td>
                                    <td className="min-w-[14rem] border-t border-gray-50 px-1 py-0.5"><input {...cellProps(0)} {...err('description')} value={r.description} onChange={(e) => setField(r.key, 'description', e.target.value)} aria-label={`Description row ${ri + 1}`} className={`${CELL} ${ce.description ? '!border-[#B3261E]' : ''}`} /></td>
                                    <td className="border-t border-gray-50 px-1 py-0.5"><select {...cellProps(1)} value={r.cost_pool_id} onChange={(e) => setField(r.key, 'cost_pool_id', e.target.value)} aria-label={`Cost pool row ${ri + 1}`} className={`${CELL} ${ce.cost_pool_id ? '!border-[#B3261E]' : ''}`} title={ce.cost_pool_id}><option value="">—</option>{poolOpts.map((o) => <option key={o.id} value={o.id}>{o.code}</option>)}</select></td>
                                    <td className="border-t border-gray-50 px-1 py-0.5"><select {...cellProps(2)} value={r.tower_id} onChange={(e) => setField(r.key, 'tower_id', e.target.value)} aria-label={`Tower row ${ri + 1}`} className={`${CELL} ${ce.tower_id ? '!border-[#B3261E]' : ''}`} title={ce.tower_id}><option value="">—</option>{towerOpts.map((o) => <option key={o.id} value={o.id}>{o.code}</option>)}</select></td>
                                    <td className="border-t border-gray-50 px-1 py-0.5"><select {...cellProps(3)} value={r.priority} onChange={(e) => setField(r.key, 'priority', e.target.value)} aria-label={`Priority row ${ri + 1}`} className={CELL}>{dims.priorities.map((o) => <option key={o} value={o}>{humanize(o)}</option>)}</select></td>
                                    <td className="border-t border-gray-50 px-2 py-0.5 text-center"><input type="checkbox" {...cellProps(4)} checked={r.is_capex} onChange={(e) => setField(r.key, 'is_capex', e.target.checked)} aria-label={`CapEx row ${ri + 1}`} /></td>
                                    <td className="border-t border-gray-50 px-1 py-0.5"><select {...cellProps(5)} value={r.phasing_method} onChange={(e) => setPhasing(r, e.target.value)} aria-label={`Phasing row ${ri + 1}`} className={CELL}>{dims.phasingMethods.map((o) => <option key={o} value={o}>{humanize(o)}</option>)}</select></td>
                                    <td className="border-t border-gray-50 px-1 py-0.5">
                                        <div className="flex items-center gap-1">
                                            <select {...cellProps(6)} value={r.currency} onChange={(e) => setField(r.key, 'currency', e.target.value)} aria-label={`Currency row ${ri + 1}`} className={`${CELL} !w-16`}>{dims.currencies.map((o) => <option key={o.code} value={o.code}>{o.code}</option>)}</select>
                                            <input type="number" step="any" {...cellProps(7)} value={r.annual_amount} onChange={(e) => setAnnual(r, e.target.value)} aria-label={`Annual amount row ${ri + 1}`} title={ce.annual_amount} className={`${NUM} min-w-[7rem] ${ce.annual_amount ? '!border-[#B3261E]' : ''}`} />
                                        </div>
                                    </td>
                                    {periods.map((p, pi) => (
                                        <td key={p.id} className="border-t border-gray-50 px-0.5 py-0.5"><input type="number" step="any" {...cellProps(8 + pi)} value={r.amounts[p.id] ?? ''} onChange={(e) => setPeriod(r, p.id, e.target.value)} aria-label={`${p.name} row ${ri + 1}`} title={ce[`amounts.${p.id}`]} className={`${NUM} min-w-[5.5rem] ${r.stale ? 'text-[#A0A4AB]' : ''} ${ce[`amounts.${p.id}`] ? '!border-[#B3261E]' : ''}`} /></td>
                                    ))}
                                    <td className={`border-t border-gray-50 px-2 py-1 text-right text-xs tabular-nums ${off ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`} title={off ? 'Periods do not add up to the annual amount' : r.stale ? 'Re-phased on save' : undefined}>{r.stale ? '·' : formatMoney(sum, r.currency, { decimals: 0 })}</td>
                                    <td className="border-t border-gray-50 px-2 py-1 text-right text-xs tabular-nums">{formatMoney(baseOf(r, r.annual_amount || 0), baseCurrency, { decimals: 0 })}</td>
                                    <td className="border-t border-gray-50 px-2 py-1 text-right text-xs tabular-nums text-[#718096]">{r.py_budget === null ? '—' : formatMoney(r.py_budget, baseCurrency, { decimals: 0 })}</td>
                                    <td className="border-t border-gray-50 px-2 py-1 text-right text-xs">{formatPct(r.yoy_pct, 1)}</td>
                                    <td className="border-t border-gray-50 px-2 py-1">{!readOnly && <button type="button" onClick={() => removeRow(r)} aria-label={`Delete row ${ri + 1}`} className="text-[#B3261E]"><TrashIcon className="h-4 w-4" /></button>}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                    {rows.length > 0 && (
                        <tfoot className="sticky bottom-0 bg-gray-50 text-xs font-semibold text-[#0A1F44]">
                            <tr>
                                <td className={`${STICKY} bg-gray-50 px-2 py-2`} colSpan={8}>Totals (base)</td>
                                {periods.map((p, i) => <td key={p.id} className="px-2 py-2 text-right tabular-nums">{formatMoney(periodTotals[i], baseCurrency, { decimals: 0 })}</td>)}
                                <td />
                                <td className="px-2 py-2 text-right tabular-nums">{formatMoney(annualTotal, baseCurrency, { decimals: 0 })}</td>
                                <td className="px-2 py-2 text-right tabular-nums">{formatMoney(pyTotal, baseCurrency, { decimals: 0 })}</td>
                                <td colSpan={2} />
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
}

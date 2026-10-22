import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { AddButton, Card, Empty, FormModal, RowActions, Table } from '@/Components/Finance/Settings/ui';
import Money from './Money';
import { FIELD, LABEL, formatPct } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function ScenariosPanel({ budget, scenarios, lines, totals, baseCurrency, can }) {
    const form = useForm({ name: '', adjustment_pct: '0', line_adjustments: {} });
    const [target, setTarget] = useState(null);
    const [pickLine, setPickLine] = useState('');
    const open = (s) => {
        form.clearErrors();
        form.setData(s ? { name: s.name, adjustment_pct: s.adjustment_pct, line_adjustments: { ...(s.line_adjustments || {}) } } : { name: '', adjustment_pct: '0', line_adjustments: {} });
        setTarget(s || 'new');
    };
    const close = () => setTarget(null);
    const submit = () => {
        const opts = { preserveScroll: true, onSuccess: close };
        if (target === 'new') form.post(route('finance.budgets.scenarios.store', budget.id), opts);
        else form.put(route('finance.budgets.scenarios.update', { budget: budget.id, scenario: target.id }), opts);
    };
    const overrides = Object.entries(form.data.line_adjustments);
    const setOverride = (id, v) => form.setData('line_adjustments', { ...form.data.line_adjustments, [id]: v });
    const dropOverride = (id) => { const n = { ...form.data.line_adjustments }; delete n[id]; form.setData('line_adjustments', n); };
    const del = (s) => confirm(`Delete scenario "${s.name}"?`) && router.delete(route('finance.budgets.scenarios.destroy', { budget: budget.id, scenario: s.id }), { preserveScroll: true });
    const promote = (s) => confirm(`Promote "${s.name}"? Line amounts will be re-scaled to this scenario.`) && router.post(route('finance.budgets.scenarios.promote', { budget: budget.id, scenario: s.id }), {}, { preserveScroll: true });

    return (
        <Card title="What-if scenarios" right={can.edit && <AddButton onClick={() => open(null)}>Add scenario</AddButton>}>
            <Table head={['Scenario', 'Adjustment', 'Overrides', 'Total (base)', 'Δ vs plan', 'Status', ...(can.edit ? [''] : [])]} empty={scenarios.length === 0 && <Empty>No scenarios. Add one to test a percentage change against the plan.</Empty>}>
                <tr className="bg-gray-50"><td className="px-4 py-2 font-medium text-[#0A1F44]">Current plan</td><td className="px-4 py-2">—</td><td className="px-4 py-2">—</td>
                    <td className="px-4 py-2 text-right"><Money value={totals.base} currency={baseCurrency} baseCurrency={baseCurrency} /></td><td colSpan={can.edit ? 3 : 2} /></tr>
                {scenarios.map((s) => {
                    const delta = Number(s.total_base) - Number(totals.base);
                    return (
                        <tr key={s.id}>
                            <td className="px-4 py-2 text-[#2D3748]">{s.name}</td>
                            <td className="px-4 py-2 text-xs">{formatPct(s.adjustment_pct)}</td>
                            <td className="px-4 py-2 text-xs">{Object.keys(s.line_adjustments || {}).length}</td>
                            <td className="px-4 py-2 text-right"><Money value={s.total_base} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                            <td className={`px-4 py-2 text-right text-xs ${delta > 0 ? 'text-[#B3261E]' : 'text-[#1B5530]'}`}>{delta > 0 ? '+' : ''}{delta.toLocaleString(undefined, { maximumFractionDigits: 0 })}</td>
                            <td className="px-4 py-2">{s.is_promoted ? <Chip size="xs" tone="success">Promoted</Chip> : <Chip size="xs" tone="neutral">Draft</Chip>}</td>
                            {can.edit && <td className="px-4 py-2"><div className="flex justify-end gap-3 text-xs">
                                {!s.is_promoted && <button type="button" onClick={() => promote(s)} className="text-[#0A1F44] underline">Promote</button>}
                                <RowActions onEdit={() => open(s)} onDelete={() => del(s)} /></div></td>}
                        </tr>
                    );
                })}
            </Table>
            <FormModal show={target !== null} onClose={close} onSubmit={submit} processing={form.processing} title={target === 'new' ? 'Add scenario' : 'Edit scenario'}>
                <div><label className={LABEL}>Name</label><input maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={FIELD} /><InputError message={form.errors.name} /></div>
                <div className="w-48"><label className={LABEL}>Across-the-board adjustment %</label><input type="number" step="any" min="-100" max="500" value={form.data.adjustment_pct} onChange={(e) => form.setData('adjustment_pct', e.target.value)} className={FIELD} /><InputError message={form.errors.adjustment_pct} /></div>
                <div>
                    <p className={LABEL}>Per-line overrides (replace the across-the-board % for that line)</p>
                    {overrides.map(([id, v]) => {
                        const l = lines.find((x) => String(x.id) === String(id));
                        return (
                            <div key={id} className="mt-1 flex items-center gap-2">
                                <span className="min-w-0 flex-1 truncate text-sm">#{l?.line_no} {l?.description}</span>
                                <input aria-label={`Override for line ${l?.line_no}`} type="number" step="any" value={v} onChange={(e) => setOverride(id, e.target.value)} className="w-24 rounded-lg border border-gray-200 px-2 py-1 text-sm" />
                                <button type="button" onClick={() => dropOverride(id)} className="text-xs text-[#B3261E] underline">Remove</button>
                            </div>
                        );
                    })}
                    <div className="mt-2 flex gap-2">
                        <select aria-label="Line to override" value={pickLine} onChange={(e) => setPickLine(e.target.value)} className="min-w-0 flex-1 rounded-lg border border-gray-200 px-2 py-1 text-sm">
                            <option value="">Add a line override…</option>
                            {lines.filter((l) => !(l.id in form.data.line_adjustments)).map((l) => <option key={l.id} value={l.id}>#{l.line_no} {l.description}</option>)}
                        </select>
                        <button type="button" disabled={!pickLine} onClick={() => { setOverride(pickLine, '0'); setPickLine(''); }} className="rounded-lg border border-gray-200 px-3 py-1 text-xs">Add</button>
                    </div>
                    <InputError message={form.errors.line_adjustments} />
                </div>
            </FormModal>
        </Card>
    );
}

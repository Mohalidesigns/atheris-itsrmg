import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { useMemo } from 'react';
import { AddButton, Card, Check, Empty, FormModal, RowActions, Table } from './ui';
import useRecordForm, { destroy } from './useRecordForm';

const BLANK = { code: '', name: '', parent_id: '', tbm_reference: '', default_capex: false, default_gl_account_id: '', is_active: true, sort_order: 0 };

export default function CategoriesTab({ categories, glAccounts, can }) {
    const ed = useRecordForm({
        blank: BLANK,
        toData: (r) => ({ code: r.code, name: r.name, parent_id: r.parent_id ?? '', tbm_reference: r.tbm_reference ?? '', default_capex: r.default_capex, default_gl_account_id: r.default_gl_account_id ?? '', is_active: r.is_active, sort_order: r.sort_order }),
        storeRoute: 'finance.categories.store',
        updateRoute: 'finance.categories.update',
        transform: (d) => ({ ...d, default_gl_account_id: d.default_gl_account_id || null, tbm_reference: d.tbm_reference || null }),
    });
    const { form } = ed;
    const gl = (id) => glAccounts.find((g) => g.id === id);
    const parents = categories.filter((c) => c.kind === 'cost_pool' || c.kind === 'tower');

    const sections = useMemo(() => {
        const kids = {};
        categories.filter((c) => c.parent_id).forEach((c) => { (kids[c.parent_id] ||= []).push(c); });
        const build = (kind) => parents.filter((p) => p.kind === kind).flatMap((p) => [{ ...p, depth: 0 }, ...(kids[p.id] || []).map((k) => ({ ...k, depth: 1 }))]);
        return [['Cost pools', build('cost_pool')], ['IT towers', build('tower')]];
    }, [categories]);

    const system = ed.target && ed.target !== 'new' && ed.target.is_system;
    const set = (k) => (e) => form.setData(k, e.target.value);

    return (
        <div className="space-y-4">
            {can.manage && <div className="flex justify-end"><AddButton onClick={() => ed.openNew()}>Add sub-category</AddButton></div>}
            {sections.map(([title, rows]) => (
                <Card key={title} title={title}>
                    <Table head={['Code', 'Name', 'TBM reference', 'Default', 'Default GL', 'Type', 'Active', ...(can.manage ? [''] : [])]} empty={rows.length === 0 && <Empty>Nothing here yet.</Empty>}>
                        {rows.map((c) => (
                            <tr key={c.id} className={c.is_active ? '' : 'opacity-50'}>
                                <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-[#4A5568]" style={{ paddingLeft: 16 + c.depth * 20 }}>{c.depth > 0 && <span className="mr-1 text-[#A0AEC0]">└</span>}{c.code}</td>
                                <td className={`px-4 py-2 text-[#2D3748] ${c.depth === 0 ? 'font-medium' : ''}`}>{c.name}</td>
                                <td className="px-4 py-2 text-xs text-[#718096]">{c.tbm_reference || '—'}</td>
                                <td className="px-4 py-2"><Chip tone={c.default_capex ? 'info' : 'neutral'} size="xs">{c.default_capex ? 'CapEx' : 'OpEx'}</Chip></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{c.default_gl_account_id && gl(c.default_gl_account_id) ? gl(c.default_gl_account_id).code : '—'}</td>
                                <td className="px-4 py-2"><Chip tone={c.is_system ? 'primary' : 'tertiary'} size="xs">{c.is_system ? 'System' : 'Custom'}</Chip></td>
                                <td className="px-4 py-2 text-xs">{c.is_active ? 'Yes' : 'No'}</td>
                                {can.manage && <td className="px-4 py-2"><RowActions onEdit={() => ed.openEdit(c)} onDelete={c.is_system ? null : () => destroy('finance.categories.destroy', c.id, `Delete ${c.code}?`)} /></td>}
                            </tr>
                        ))}
                    </Table>
                </Card>
            ))}

            <FormModal show={ed.target !== null} onClose={ed.close} onSubmit={ed.submit} processing={form.processing} title={ed.isNew ? 'Add sub-category' : `Edit ${system ? 'system' : 'custom'} category`}>
                {system && <p className="text-xs text-[#718096]">System rows keep their code, kind, parent and TBM reference. Only the name, CapEx default, default GL account and active flag can change.</p>}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL}>Code</label><input disabled={system} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={`${FIELD} font-mono disabled:bg-gray-50`} /><InputError message={form.errors.code} /></div>
                    <div className="sm:col-span-2"><label className={LABEL}>Name</label><input value={form.data.name} onChange={set('name')} className={FIELD} /><InputError message={form.errors.name} /></div>
                </div>
                {!system && (
                    <div><label className={LABEL}>Parent (cost pool or tower)</label>
                        <select value={form.data.parent_id} onChange={set('parent_id')} className={FIELD}><option value="">Select…</option>{parents.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name} ({humanize(p.kind)})</option>)}</select>
                        <InputError message={form.errors.parent_id} /></div>
                )}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label className={LABEL}>Default GL account</label><select value={form.data.default_gl_account_id} onChange={set('default_gl_account_id')} className={FIELD}><option value="">None</option>{glAccounts.map((g) => <option key={g.id} value={g.id}>{g.code} · {g.name}</option>)}</select><InputError message={form.errors.default_gl_account_id} /></div>
                    {!system && <div><label className={LABEL}>TBM reference</label><input maxLength={64} value={form.data.tbm_reference} onChange={set('tbm_reference')} className={FIELD} /><InputError message={form.errors.tbm_reference} /></div>}
                </div>
                <div className="flex flex-wrap gap-6">
                    <Check label="CapEx by default" checked={form.data.default_capex} onChange={(v) => form.setData('default_capex', v)} />
                    <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
                </div>
            </FormModal>
        </div>
    );
}

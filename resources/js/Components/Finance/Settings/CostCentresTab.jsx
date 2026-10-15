import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import CsvImportForm from '@/Components/Finance/CsvImportForm';
import ImportReport from '@/Components/Finance/ImportReport';
import { FIELD, LABEL } from '@/Utils/finance';
import { useMemo } from 'react';
import { AddButton, Card, Check, Empty, FormModal, RowActions, Table } from './ui';
import useRecordForm, { destroy } from './useRecordForm';

const BLANK = { code: '', name: '', parent_id: '', owner_id: '', legal_entity_id: '', business_unit: '', is_active: true };

export default function CostCentresTab({ costCentres, users, legalEntities, can }) {
    const ed = useRecordForm({
        blank: BLANK,
        toData: (r) => ({ code: r.code, name: r.name, parent_id: r.parent_id ?? '', owner_id: r.owner_id ?? '', legal_entity_id: r.legal_entity_id ?? '', business_unit: r.business_unit ?? '', is_active: r.is_active }),
        storeRoute: 'finance.cost-centres.store',
        updateRoute: 'finance.cost-centres.update',
        transform: (d) => ({ ...d, parent_id: d.parent_id || null, owner_id: d.owner_id || null, legal_entity_id: d.legal_entity_id || null, business_unit: d.business_unit || null }),
    });
    const { form } = ed;

    const { rows, descendants } = useMemo(() => {
        const kids = {};
        costCentres.forEach((c) => { (kids[c.parent_id ?? 0] ||= []).push(c); });
        const ids = new Set(costCentres.map((c) => c.id));
        const out = [];
        const walk = (pid, depth) => (kids[pid] || []).forEach((c) => { out.push({ ...c, depth }); walk(c.id, depth + 1); });
        walk(0, 0);
        // orphans whose parent is not in the list
        costCentres.filter((c) => c.parent_id && !ids.has(c.parent_id)).forEach((c) => { out.push({ ...c, depth: 0 }); walk(c.id, 1); });
        const desc = (id) => { const s = new Set(); const rec = (x) => (kids[x] || []).forEach((c) => { s.add(c.id); rec(c.id); }); rec(id); return s; };
        return { rows: out, descendants: desc };
    }, [costCentres]);

    const excluded = ed.target && ed.target !== 'new' ? new Set([ed.target.id, ...descendants(ed.target.id)]) : new Set();
    const set = (k) => (e) => form.setData(k, e.target.value);

    return (
        <div className="space-y-4">
            <ImportReport type="cost_centres" />
            <Card title={`Cost centres (${costCentres.length})`} right={can.manage && <AddButton onClick={() => ed.openNew()}>Add cost centre</AddButton>}>
                <Table head={['Code', 'Name', 'Owner', 'Legal entity', 'Business unit', 'Active', ...(can.manage ? [''] : [])]}
                    empty={costCentres.length === 0 && <Empty>No cost centres yet. Add one, or import a CSV.</Empty>}>
                    {rows.map((c) => (
                        <tr key={c.id} className={c.is_active ? '' : 'opacity-50'}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-[#4A5568]" style={{ paddingLeft: 16 + c.depth * 20 }}>{c.depth > 0 && <span className="mr-1 text-[#A0AEC0]">└</span>}{c.code}</td>
                            <td className="px-4 py-2 font-medium text-[#2D3748]">{c.name}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{c.owner ? <>{c.owner.name}{c.owner.job_title && <span className="block text-[10px] text-[#718096]">{c.owner.job_title}</span>}</> : <Chip tone="warning">Unowned</Chip>}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{c.legal_entity ? `${c.legal_entity.code}` : '—'}</td>
                            <td className="px-4 py-2 text-xs text-[#4A5568]">{c.business_unit || '—'}</td>
                            <td className="px-4 py-2 text-xs">{c.is_active ? 'Yes' : 'No'}</td>
                            {can.manage && <td className="px-4 py-2"><RowActions onEdit={() => ed.openEdit(c)} onDelete={() => destroy('finance.cost-centres.destroy', c.id, `Delete cost centre ${c.code}?`)} /></td>}
                        </tr>
                    ))}
                </Table>
            </Card>
            {can.import && <CsvImportForm routeName="finance.cost-centres.import" label="Import cost centres (CSV)" hint="code,name,parent_code,owner_email,legal_entity_code,business_unit,is_active" />}

            <FormModal show={ed.target !== null} onClose={ed.close} onSubmit={ed.submit} processing={form.processing} title={ed.isNew ? 'Add cost centre' : 'Edit cost centre'}>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL}>Code</label><input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={`${FIELD} font-mono`} /><InputError message={form.errors.code} /></div>
                    <div className="sm:col-span-2"><label className={LABEL}>Name</label><input value={form.data.name} onChange={set('name')} className={FIELD} /><InputError message={form.errors.name} /></div>
                </div>
                <div><label className={LABEL}>Parent</label>
                    <select value={form.data.parent_id} onChange={set('parent_id')} className={FIELD}><option value="">None (top level)</option>{costCentres.filter((c) => !excluded.has(c.id)).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}</select>
                    <InputError message={form.errors.parent_id} /></div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label className={LABEL}>Owner</label><select value={form.data.owner_id} onChange={set('owner_id')} className={FIELD}><option value="">Unowned</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` (${u.job_title})` : ''}</option>)}</select><InputError message={form.errors.owner_id} /></div>
                    <div><label className={LABEL}>Legal entity</label><select value={form.data.legal_entity_id} onChange={set('legal_entity_id')} className={FIELD}><option value="">None</option>{legalEntities.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}</select><InputError message={form.errors.legal_entity_id} /></div>
                </div>
                <div><label className={LABEL}>Business unit</label><input value={form.data.business_unit} onChange={set('business_unit')} className={FIELD} /><InputError message={form.errors.business_unit} /></div>
                <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
            </FormModal>
        </div>
    );
}

import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { FIELD, LABEL, formatPct, humanize } from '@/Utils/finance';
import { AddButton, Card, Check, Empty, FormModal, RowActions, Table } from './ui';
import useRecordForm from './useRecordForm';

const BLANK = { code: '', name: '', kind: 'vat', rate: '0', is_inclusive: false, applies_to: ['expense', 'invoice'], is_active: true };

export default function TaxesTab({ taxTypes, options, can }) {
    const ed = useRecordForm({ blank: BLANK, toData: (r) => ({ code: r.code, name: r.name, kind: r.kind, rate: r.rate, is_inclusive: r.is_inclusive, applies_to: r.applies_to || [], is_active: r.is_active }), storeRoute: 'finance.tax-types.store', updateRoute: 'finance.tax-types.update' });
    const { form } = ed;
    const toggle = (v) => form.setData('applies_to', form.data.applies_to.includes(v) ? form.data.applies_to.filter((x) => x !== v) : [...form.data.applies_to, v]);
    return (
        <Card title="Tax types" right={can.manage && <AddButton onClick={() => ed.openNew()}>Add tax type</AddButton>}>
            <Table head={['Code', 'Name', 'Kind', 'Rate', 'Inclusive', 'Applies to', 'Active', ...(can.manage ? [''] : [])]} empty={taxTypes.length === 0 && <Empty>No tax types yet.</Empty>}>
                {taxTypes.map((t) => (
                    <tr key={t.id} className={t.is_active ? '' : 'opacity-50'}>
                        <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-[#4A5568]">{t.code}</td>
                        <td className="px-4 py-2 text-[#2D3748]">{t.name}</td>
                        <td className="px-4 py-2 text-xs uppercase text-[#4A5568]">{t.kind === 'none' ? 'None' : t.kind.replace('_', ' ')}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{formatPct(t.rate, 4)}</td>
                        <td className="px-4 py-2 text-xs">{t.is_inclusive ? 'Yes' : 'No'}</td>
                        <td className="px-4 py-2"><span className="flex gap-1">{(t.applies_to || []).map((a) => <Chip key={a} size="xs" tone="neutral">{humanize(a)}</Chip>)}</span></td>
                        <td className="px-4 py-2 text-xs">{t.is_active ? 'Yes' : 'No'}</td>
                        {can.manage && <td className="px-4 py-2"><RowActions onEdit={() => ed.openEdit(t)} /></td>}
                    </tr>
                ))}
            </Table>
            <p className="px-5 py-3 text-[11px] text-[#718096]">Withholding tax defaults follow current Nigerian practice but are not verified against the latest schedule. Review them before relying on invoice calculations.</p>
            <FormModal show={ed.target !== null} onClose={ed.close} onSubmit={ed.submit} processing={form.processing} title={ed.isNew ? 'Add tax type' : 'Edit tax type'}>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL}>Code</label><input maxLength={16} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={`${FIELD} font-mono`} /><InputError message={form.errors.code} /></div>
                    <div className="sm:col-span-2"><label className={LABEL}>Name</label><input maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={FIELD} /><InputError message={form.errors.name} /></div>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label className={LABEL}>Kind</label><select value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)} className={FIELD}>{options.taxKinds.map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</select><InputError message={form.errors.kind} /></div>
                    <div><label className={LABEL}>Rate (%)</label><input type="number" step="0.0001" min="0" max="100" value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} className={FIELD} /><InputError message={form.errors.rate} /></div>
                </div>
                <div><p className={LABEL}>Applies to</p>
                    <div className="mt-1 flex gap-4">{options.taxAppliesTo.map((a) => <Check key={a} label={humanize(a)} checked={form.data.applies_to.includes(a)} onChange={() => toggle(a)} />)}</div>
                    <InputError message={form.errors.applies_to} /></div>
                <div className="flex flex-wrap gap-6">
                    <Check label="Rate is tax-inclusive" checked={form.data.is_inclusive} onChange={(v) => form.setData('is_inclusive', v)} />
                    <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
                </div>
            </FormModal>
        </Card>
    );
}

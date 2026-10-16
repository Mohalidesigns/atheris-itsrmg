import InputError from '@/Components/InputError';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { AddButton, Card, Check, Empty, FormModal, RowActions, Table } from './ui';
import useRecordForm, { destroy } from './useRecordForm';

const BLANK = { code: '', name: '', type: 'opex', is_active: true };

export default function GlAccountsTab({ glAccounts, options, can }) {
    const ed = useRecordForm({ blank: BLANK, toData: (r) => ({ code: r.code, name: r.name, type: r.type, is_active: r.is_active }), storeRoute: 'finance.gl-accounts.store', updateRoute: 'finance.gl-accounts.update' });
    const { form } = ed;
    return (
        <Card title={`GL accounts (${glAccounts.length})`} right={can.manage && <AddButton onClick={() => ed.openNew()}>Add GL account</AddButton>}>
            <Table head={['Code', 'Name', 'Type', 'Active', ...(can.manage ? [''] : [])]} empty={glAccounts.length === 0 && <Empty>No GL accounts yet.</Empty>}>
                {glAccounts.map((g) => (
                    <tr key={g.id} className={g.is_active ? '' : 'opacity-50'}>
                        <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-[#4A5568]">{g.code}</td>
                        <td className="px-4 py-2 text-[#2D3748]">{g.name}</td>
                        <td className="px-4 py-2 text-xs text-[#4A5568]">{g.type === 'capex' ? 'CapEx' : g.type === 'opex' ? 'OpEx' : humanize(g.type)}</td>
                        <td className="px-4 py-2 text-xs">{g.is_active ? 'Yes' : 'No'}</td>
                        {can.manage && <td className="px-4 py-2"><RowActions onEdit={() => ed.openEdit(g)} onDelete={() => destroy('finance.gl-accounts.destroy', g.id, `Delete GL account ${g.code}?`)} /></td>}
                    </tr>
                ))}
            </Table>
            <FormModal show={ed.target !== null} onClose={ed.close} onSubmit={ed.submit} processing={form.processing} title={ed.isNew ? 'Add GL account' : 'Edit GL account'}>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL}>Code</label><input maxLength={32} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} className={`${FIELD} font-mono`} /><InputError message={form.errors.code} /></div>
                    <div className="sm:col-span-2"><label className={LABEL}>Name</label><input maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={FIELD} /><InputError message={form.errors.name} /></div>
                </div>
                <div><label className={LABEL}>Type</label><select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className={FIELD}>{options.glTypes.map((t) => <option key={t} value={t}>{t === 'capex' ? 'CapEx' : t === 'opex' ? 'OpEx' : humanize(t)}</option>)}</select><InputError message={form.errors.type} /></div>
                <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
            </FormModal>
        </Card>
    );
}

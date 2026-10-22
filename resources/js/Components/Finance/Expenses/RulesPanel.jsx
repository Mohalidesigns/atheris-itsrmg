import InputError from '@/Components/InputError';
import Field from './Field';
import { AddButton, Card, Check, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, localDate } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/** Automatic match rules (first match by priority wins). */
export default function RulesPanel({ rules, budgetLines, costCentres, glAccounts, vendors, contracts, canEdit }) {
    const [show, setShow] = useState(false);
    const form = useForm({ id: '', name: '', priority: 100, cost_centre_id: '', gl_account_id: '', vendor_id: '', contract_id: '', description_contains: '', budget_line_id: '', is_active: true });
    const e = form.errors;
    const open = (r) => {
        form.setData(r ? { id: r.id, name: r.name, priority: r.priority, cost_centre_id: r.cost_centre?.id ?? '', gl_account_id: r.gl_account?.id ?? '', vendor_id: r.vendor?.id ?? '', contract_id: r.contract?.id ?? '', description_contains: r.description_contains ?? '', budget_line_id: r.budget_line?.id ?? '', is_active: r.is_active }
            : { id: '', name: '', priority: 100, cost_centre_id: '', gl_account_id: '', vendor_id: '', contract_id: '', description_contains: '', budget_line_id: '', is_active: true });
        form.clearErrors(); setShow(true);
    };
    const submit = () => {
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(route('finance.actuals.rules.store'), { preserveScroll: true, onSuccess: () => setShow(false) });
    };
    const remove = (r) => confirm(`Delete rule "${r.name}"?`) && router.delete(route('finance.actuals.rules.destroy', r.id), { preserveScroll: true });
    const sel = (id, k, label, list, fmt) => <Field id={id} label={label} error={e[k]}><select id={id} value={form.data[k]} onChange={(ev) => form.setData(k, ev.target.value)} className={FIELD}><option value="">Any</option>{list.map((x) => <option key={x.id} value={x.id}>{fmt(x)}</option>)}</select></Field>;
    return (
        <Card title="Match rules" right={canEdit && <AddButton onClick={() => open(null)}>Add rule</AddButton>}>
            <Table head={['Priority', 'Name', 'When', 'Budget line', 'Hits', 'Last hit', 'Active', ...(canEdit ? [''] : [])]} empty={rules.length === 0 && <Empty>No rules yet. Match an actual with "remember" ticked to create one.</Empty>}>
                {rules.map((r) => (
                    <tr key={r.id}>
                        <td className="px-4 py-2 text-xs tabular-nums">{r.priority}</td>
                        <td className="px-4 py-2 text-[#2D3748]">{r.name}</td>
                        <td className="min-w-[10rem] px-4 py-2 text-xs text-[#4A5568]">{[r.cost_centre && `CC ${r.cost_centre.code}`, r.gl_account && `GL ${r.gl_account.code}`, r.vendor && r.vendor.name, r.contract && r.contract.contract_code, r.description_contains && `text "${r.description_contains}"`].filter(Boolean).join(' · ') || 'Anything'}</td>
                        <td className="px-4 py-2 text-xs">{r.budget_line ? `${r.budget_line.budget_code} #${r.budget_line.line_no}` : '—'}</td>
                        <td className="px-4 py-2 text-xs tabular-nums">{r.hits}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(r.last_hit_at)}</td>
                        <td className="px-4 py-2 text-xs">{r.is_active ? 'Yes' : 'No'}</td>
                        {canEdit && <td className="whitespace-nowrap px-4 py-2 text-right text-xs"><button type="button" onClick={() => open(r)} className="text-[#0A1F44] underline">Edit</button><button type="button" onClick={() => remove(r)} className="ml-3 text-[#B3261E] underline">Delete</button></td>}
                    </tr>
                ))}
            </Table>
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.name || !form.data.budget_line_id} title={form.data.id ? 'Edit match rule' : 'New match rule'} maxWidth="2xl">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <Field id="r-name" label="Name" error={e.name} className="sm:col-span-2"><input id="r-name" maxLength={120} value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} className={FIELD} /></Field>
                    <Field id="r-pri" label="Priority (low runs first)" error={e.priority}><input id="r-pri" type="number" min="1" max="9999" value={form.data.priority} onChange={(ev) => form.setData('priority', ev.target.value)} className={FIELD} /></Field>
                    {sel('r-cc', 'cost_centre_id', 'Cost centre', costCentres, (c) => `${c.code} ${c.name}`)}
                    {sel('r-gl', 'gl_account_id', 'GL account', glAccounts, (g) => `${g.code} ${g.name}`)}
                    {sel('r-v', 'vendor_id', 'Vendor', vendors, (v) => v.name)}
                    {sel('r-k', 'contract_id', 'Contract', contracts, (c) => `${c.contract_code} ${c.title}`)}
                    <Field id="r-txt" label="Description contains" error={e.description_contains} className="sm:col-span-2"><input id="r-txt" maxLength={120} value={form.data.description_contains} onChange={(ev) => form.setData('description_contains', ev.target.value)} className={FIELD} /></Field>
                </div>
                <Field id="r-bl" label="Budget line to match to" error={e.budget_line_id}><select id="r-bl" value={form.data.budget_line_id} onChange={(ev) => form.setData('budget_line_id', ev.target.value)} className={FIELD}><option value="">Select…</option>{budgetLines.map((l) => <option key={l.id} value={l.id}>{l.budget_code} #{l.line_no} {l.label}</option>)}</select></Field>
                <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
                <InputError message={e.workflow} />
            </FormModal>
        </Card>
    );
}

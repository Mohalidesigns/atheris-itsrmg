import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { AddButton, Card, Check, Empty, FormModal, RowActions, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize, localDate } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const BLANK = { cloud_account_id: '', tag_key: '', tag_value: '', dimension: 'cost_centre', target_id: '', is_active: true };

/** Tag rules: an exact tag value resolves to a cost centre, application or business service. */
export default function TagRulesPanel({ rules, accounts, dimensions, targets, can }) {
    const form = useForm(BLANK);
    const [target, setTarget] = useState(null); // null | 'new' | rule
    const e = form.errors;
    const open = (rule) => {
        form.clearErrors();
        form.setData(rule ? { cloud_account_id: rule.cloud_account_id ?? '', tag_key: rule.tag_key, tag_value: rule.tag_value, dimension: rule.dimension, target_id: rule.target?.id ?? '', is_active: rule.is_active } : BLANK);
        setTarget(rule ?? 'new');
    };
    const close = () => setTarget(null);
    const submit = () => {
        form.transform((d) => ({ ...d, cloud_account_id: d.cloud_account_id === '' ? null : d.cloud_account_id }));
        const opts = { preserveScroll: true, onSuccess: close };
        if (target === 'new') form.post(route('finance.cloud.tag-rules.store'), opts); else form.put(route('finance.cloud.tag-rules.update', target.id), opts);
    };
    const remove = (r) => confirm(`Delete the rule ${r.tag_key} = ${r.tag_value}?`) && router.delete(route('finance.cloud.tag-rules.destroy', r.id), { preserveScroll: true });
    const list = targets[form.data.dimension] ?? [];
    return (
        <Card title="Tag rules" right={can.manageRules && <AddButton onClick={() => open(null)}>Add rule</AddButton>}>
            <Table head={['Tag', 'Account', 'Resolves to', 'Hits', 'Last hit', 'Active', '']} empty={rules.length === 0 && <Empty>No tag rules. Tags that equal a cost-centre code, application or service name are matched automatically.</Empty>}>
                {rules.map((r) => (
                    <tr key={r.id} className={r.is_active ? '' : 'opacity-50'}>
                        <td className="px-4 py-2 font-mono text-xs">{r.tag_key} = {r.tag_value}</td>
                        <td className="px-4 py-2 text-xs">{r.account_name ?? <Chip size="xs" tone="muted">All accounts</Chip>}</td>
                        <td className="px-4 py-2 text-xs"><span className="text-[#718096]">{humanize(r.dimension)}: </span>{r.target?.label ?? '—'}</td>
                        <td className="px-4 py-2 text-right tabular-nums">{r.hits}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{r.last_hit_at ? localDate(r.last_hit_at) : '—'}</td>
                        <td className="px-4 py-2 text-xs">{r.is_active ? 'Yes' : 'No'}</td>
                        <td className="px-4 py-2">{r.can?.edit && <RowActions onEdit={() => open(r)} onDelete={() => remove(r)} />}</td>
                    </tr>
                ))}
            </Table>
            <FormModal show={target !== null} onClose={close} onSubmit={submit} processing={form.processing || !form.data.tag_key.trim() || !form.data.tag_value.trim() || !form.data.target_id} title={target === 'new' ? 'Add tag rule' : 'Edit tag rule'}>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className="sm:col-span-2"><label className={LABEL} htmlFor="tr-ac">Account</label><select id="tr-ac" value={form.data.cloud_account_id} onChange={(ev) => form.setData('cloud_account_id', ev.target.value)} className={FIELD}><option value="">All accounts</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select><InputError message={e.cloud_account_id} /></div>
                    <div><label className={LABEL} htmlFor="tr-k">Tag key</label><input id="tr-k" maxLength={128} value={form.data.tag_key} onChange={(ev) => form.setData('tag_key', ev.target.value)} className={FIELD} /><InputError message={e.tag_key} /></div>
                    <div><label className={LABEL} htmlFor="tr-v">Tag value</label><input id="tr-v" maxLength={255} value={form.data.tag_value} onChange={(ev) => form.setData('tag_value', ev.target.value)} className={FIELD} /><InputError message={e.tag_value} /></div>
                    <div><label className={LABEL} htmlFor="tr-d">Dimension</label><select id="tr-d" value={form.data.dimension} onChange={(ev) => form.setData({ ...form.data, dimension: ev.target.value, target_id: '' })} className={FIELD}>{dimensions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.dimension} /></div>
                    <div><label className={LABEL} htmlFor="tr-t">Resolves to</label><select id="tr-t" value={form.data.target_id} onChange={(ev) => form.setData('target_id', ev.target.value)} className={FIELD}><option value="">Select…</option>{list.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select><InputError message={e.target_id} /></div>
                </div>
                <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
            </FormModal>
        </Card>
    );
}

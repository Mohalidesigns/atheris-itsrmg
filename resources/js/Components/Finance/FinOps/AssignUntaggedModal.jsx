import InputError from '@/Components/InputError';
import { Check, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, finMoney } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

/** Attribute an untagged resource (open periods only) to a cost centre, application or service, optionally saving a tag rule. */
export default function AssignUntaggedModal({ item, onClose, dimensions, targets, tagKeys = {}, baseCurrency, money }) {
    const form = useForm({ cloud_account_id: '', resource_id: '', service: '', dimension: 'cost_centre', target_id: '', create_rule: false, tag_key: '' });
    useEffect(() => {
        if (!item) return;
        form.clearErrors();
        form.setData({ cloud_account_id: item.cloud_account_id, resource_id: item.resource_id ?? '', service: item.service ?? '', dimension: 'cost_centre', target_id: '', create_rule: false, tag_key: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item?.cloud_account_id, item?.resource_id, item?.service]);
    if (!item) return null;
    const e = form.errors;
    const keys = tagKeys[item.cloud_account_id] ?? [];
    const hasTags = item.tags && Object.keys(item.tags).length > 0;
    const list = targets[form.data.dimension] ?? [];
    const submit = () => {
        form.transform((d) => ({ ...d, service: d.service || null, resource_id: d.resource_id || null, tag_key: d.create_rule ? (d.tag_key || null) : null }));
        form.post(route('finance.cloud.untagged.assign'), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <FormModal show onClose={onClose} onSubmit={submit} processing={form.processing || !form.data.target_id || (form.data.create_rule && !form.data.tag_key)} title="Attribute untagged spend" submitLabel="Assign">
            <div className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-[#4A5568]">
                <p><b>{item.service}</b> · <span className="font-mono">{item.resource_id || 'no resource id'}</span></p>
                <p>{item.account} · {item.lines} lines · {finMoney(item.cost, baseCurrency, money, { decimals: 0 })}</p>
                {!item.in_open_period && <p className="mt-1 text-[#8C1D18]">Part of this spend sits in a closed period; only open-period lines change.</p>}
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label className={LABEL} htmlFor="au-d">Attribute to</label><select id="au-d" value={form.data.dimension} onChange={(ev) => form.setData({ ...form.data, dimension: ev.target.value, target_id: '' })} className={FIELD}>{dimensions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.dimension} /></div>
                <div><label className={LABEL} htmlFor="au-t">Target</label><select id="au-t" value={form.data.target_id} onChange={(ev) => form.setData('target_id', ev.target.value)} className={FIELD}><option value="">Select…</option>{list.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select><InputError message={e.target_id} /></div>
            </div>
            <Check label="Also create a tag rule so future lines resolve automatically" checked={form.data.create_rule} onChange={(v) => form.setData('create_rule', v)} disabled={!hasTags} />
            {!hasTags && <p className="text-[11px] text-[#718096]">This resource carries no tags, so no rule can be created from it.</p>}
            {form.data.create_rule && (
                <div><label className={LABEL} htmlFor="au-k">Tag key to match</label>
                    <select id="au-k" value={form.data.tag_key} onChange={(ev) => form.setData('tag_key', ev.target.value)} className={FIELD}><option value="">Select…</option>{[...new Set([...Object.keys(item.tags ?? {}), ...keys])].map((k) => <option key={k} value={k}>{k}{item.tags?.[k] ? ` = ${item.tags[k]}` : ''}</option>)}</select>
                    <InputError message={e.tag_key} /></div>
            )}
            <InputError message={e.resource_id || e.cloud_account_id} />
        </FormModal>
    );
}

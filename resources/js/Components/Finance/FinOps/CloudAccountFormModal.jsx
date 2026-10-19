import InputError from '@/Components/InputError';
import TagMappingEditor from '@/Components/Finance/FinOps/TagMappingEditor';
import { Check, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const BLANK = { provider: 'aws', account_identifier: '', name: '', default_format: 'aws_cur', currency: 'USD', default_cost_centre_id: '', owner_id: '', contract_id: '', is_active: true, notes: '' };

export default function CloudAccountFormModal({ show, onClose, account, options, costCentres, users, contracts, baseCurrency }) {
    const form = useForm(BLANK);
    const [rows, setRows] = useState([]);
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        form.setData(account ? {
            provider: account.provider, account_identifier: account.account_identifier, name: account.name, default_format: account.default_format, currency: account.currency,
            default_cost_centre_id: account.default_cost_centre?.id ?? '', owner_id: account.owner?.id ?? '', contract_id: account.contract?.id ?? '', is_active: account.is_active, notes: account.notes ?? '',
        } : { ...BLANK, currency: baseCurrency || 'USD' });
        setRows(Object.entries(account?.tag_mapping ?? {}).map(([key, dimension]) => ({ key, dimension })));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, account?.id]);
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const submit = () => {
        form.transform((d) => {
            const o = Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
            o.tag_mapping = Object.fromEntries(rows.filter((r) => r.key.trim()).map((r) => [r.key.trim(), r.dimension]));
            return o;
        });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (account) form.put(route('finance.cloud.accounts.update', account.id), opts); else form.post(route('finance.cloud.accounts.store'), opts);
    };
    const mappingError = Object.entries(e).find(([k]) => k.startsWith('tag_mapping'))?.[1];
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !form.data.name.trim() || !form.data.account_identifier.trim() || !form.data.default_cost_centre_id} title={account ? `Edit ${account.name}` : 'Add cloud account'} maxWidth="2xl">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label className={LABEL} htmlFor="ca-pr">Provider</label><select id="ca-pr" value={form.data.provider} onChange={set('provider')} className={FIELD}>{options.providers.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.provider} /></div>
                <div><label className={LABEL} htmlFor="ca-id">Account / subscription / project id</label><input id="ca-id" maxLength={100} value={form.data.account_identifier} onChange={set('account_identifier')} className={`${FIELD} font-mono`} /><InputError message={e.account_identifier} /></div>
                <div className="sm:col-span-2"><label className={LABEL} htmlFor="ca-nm">Name</label><input id="ca-nm" maxLength={160} value={form.data.name} onChange={set('name')} className={FIELD} /><InputError message={e.name} /></div>
                <div><label className={LABEL} htmlFor="ca-fm">Default export format</label><select id="ca-fm" value={form.data.default_format} onChange={set('default_format')} className={FIELD}>{options.formats.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.default_format} /></div>
                <div><label className={LABEL} htmlFor="ca-cu">Billing currency</label><select id="ca-cu" value={form.data.currency} onChange={set('currency')} className={FIELD}>{options.currencies.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.currency} /></div>
                <div><label className={LABEL} htmlFor="ca-cc">Default cost centre</label><select id="ca-cc" value={form.data.default_cost_centre_id} onChange={set('default_cost_centre_id')} className={FIELD}><option value="">Select…</option>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</select><InputError message={e.default_cost_centre_id} /></div>
                <div><label className={LABEL} htmlFor="ca-ow">Owner</label><select id="ca-ow" value={form.data.owner_id} onChange={set('owner_id')} className={FIELD}><option value="">Unassigned</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select><InputError message={e.owner_id} /></div>
                <div className="sm:col-span-2"><label className={LABEL} htmlFor="ca-co">Contract (commitment or agreement)</label><select id="ca-co" value={form.data.contract_id} onChange={set('contract_id')} className={FIELD}><option value="">None</option>{contracts.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}</select><InputError message={e.contract_id} /></div>
            </div>
            <TagMappingEditor rows={rows} onChange={setRows} dimensions={options.dimensions} error={mappingError} />
            <div><label className={LABEL} htmlFor="ca-nt">Notes</label><input id="ca-nt" value={form.data.notes} onChange={set('notes')} className={FIELD} /><InputError message={e.notes} /></div>
            <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
        </FormModal>
    );
}

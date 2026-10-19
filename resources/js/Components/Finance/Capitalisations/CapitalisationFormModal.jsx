import InputError from '@/Components/InputError';
import { Check, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, finMoney, sourceLabel } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

const BLANK = {
    source_type: 'investment', source_id: '', title: '', standard: 'ias_38', available_for_use_on: '', amount: '', excluded_amount: '0', excluded_reason: '', residual_value: '0',
    useful_life_months: 60, method: 'straight_line', declining_rate_pct: '', create_asset: true, asset_id: '', asset_name: '', asset_type: 'software', asset_owner_id: '',
};

/**
 * Create (from a candidate) or edit (draft / rejected) a capitalisation.
 * `source` = { source_type, id, label, remaining, standard_hint }; when editing, source and its remaining pool are fixed.
 */
export default function CapitalisationFormModal({ show, onClose, capitalisation, source, options, defaults, assets, users, baseCurrency, money, remaining }) {
    const form = useForm(BLANK);
    useEffect(() => {
        if (!show) return;
        form.clearErrors();
        if (capitalisation) {
            form.setData({
                source_type: capitalisation.source_type, source_id: capitalisation.source?.id ?? '', title: capitalisation.title, standard: capitalisation.standard, available_for_use_on: capitalisation.available_for_use_on ?? '',
                amount: capitalisation.amount ?? '', excluded_amount: capitalisation.excluded_amount ?? '0', excluded_reason: capitalisation.excluded_reason ?? '', residual_value: capitalisation.residual_value ?? '0',
                useful_life_months: capitalisation.useful_life_months, method: capitalisation.method, declining_rate_pct: capitalisation.declining_rate_pct ?? '', create_asset: capitalisation.create_asset,
                asset_id: capitalisation.asset?.id ?? '', asset_name: capitalisation.asset_name ?? '', asset_type: capitalisation.asset_type ?? 'software', asset_owner_id: capitalisation.asset_owner?.id ?? '',
            });
        } else if (source) {
            const std = source.standard_hint || 'ias_38';
            form.setData({ ...BLANK, source_type: source.source_type, source_id: source.id, title: source.label, standard: std, amount: source.remaining ?? '', useful_life_months: defaults?.life_months?.[std] ?? 60, asset_name: source.label, asset_type: std === 'ias_16' ? 'hardware' : 'software' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, capitalisation?.id, source?.id, source?.source_type]);
    const d = form.data; const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.value);
    const left = remaining ?? source?.remaining;
    const over = money && left !== null && left !== undefined && d.amount !== '' && Number(d.amount) > Number(left);
    const submit = () => {
        form.transform((x) => {
            const o = Object.fromEntries(Object.entries(x).map(([k, v]) => [k, v === '' ? null : v]));
            if (x.create_asset) o.asset_id = null; else { o.asset_name = null; o.asset_type = null; o.asset_owner_id = null; }
            if (x.method !== 'declining') o.declining_rate_pct = null;
            if (capitalisation) o.criteria_checklist = (capitalisation.criteria_checklist ?? []).map((r) => ({ key: r.key, met: !!r.met, note: r.note || null, document_id: r.document_id || null }));
            return o;
        });
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (capitalisation) form.put(route('finance.capitalisations.update', capitalisation.id), opts); else form.post(route('finance.capitalisations.store'), opts);
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={submit} processing={form.processing || !d.title.trim() || !d.available_for_use_on || d.amount === ''} title={capitalisation ? `Edit ${capitalisation.capitalisation_code}` : 'Capitalise spend'} maxWidth="2xl">
            <p className="rounded-lg bg-gray-50 px-3 py-2 text-xs text-[#4A5568]">Source: <b>{sourceLabel(d.source_type)}</b> {source?.label ?? capitalisation?.source?.label}</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2"><label className={LABEL} htmlFor="cp-ti">Title</label><input id="cp-ti" maxLength={200} value={d.title} onChange={set('title')} className={FIELD} /><InputError message={e.title} /></div>
                <div><label className={LABEL} htmlFor="cp-st">Standard</label><select id="cp-st" value={d.standard} onChange={(ev) => form.setData({ ...d, standard: ev.target.value, useful_life_months: defaults?.life_months?.[ev.target.value] ?? d.useful_life_months, asset_type: ev.target.value === 'ias_16' ? 'hardware' : 'software' })} className={FIELD}>{options.standards.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.standard} /></div>
                <div><label className={LABEL} htmlFor="cp-av">Available for use on</label><input id="cp-av" type="date" value={d.available_for_use_on} onChange={set('available_for_use_on')} className={FIELD} /><InputError message={e.available_for_use_on} /><p className="mt-1 text-[11px] text-[#718096]">Later cost is not capitalised. Amortisation starts this month.</p></div>
                <div><label className={LABEL} htmlFor="cp-am">Amount to capitalise ({baseCurrency})</label><input id="cp-am" type="number" min="0" step="0.01" value={d.amount} onChange={set('amount')} className={FIELD} /><InputError message={e.amount} />
                    {money && left !== null && left !== undefined && <p className={`mt-1 text-[11px] ${over ? 'text-[#B3261E]' : 'text-[#718096]'}`}>Remaining eligible: {finMoney(left, baseCurrency, true, { decimals: 0 })}{over ? ', amount exceeds it' : ''}</p>}</div>
                <div><label className={LABEL} htmlFor="cp-rv">Residual value</label><input id="cp-rv" type="number" min="0" step="0.01" value={d.residual_value} onChange={set('residual_value')} className={FIELD} /><InputError message={e.residual_value} /></div>
                <div><label className={LABEL} htmlFor="cp-ex">Excluded amount (research, training)</label><input id="cp-ex" type="number" min="0" step="0.01" value={d.excluded_amount} onChange={set('excluded_amount')} className={FIELD} /><InputError message={e.excluded_amount} /></div>
                <div><label className={LABEL} htmlFor="cp-er">Exclusion reason</label><input id="cp-er" value={d.excluded_reason} onChange={set('excluded_reason')} className={FIELD} /><InputError message={e.excluded_reason} /></div>
                <div><label className={LABEL} htmlFor="cp-li">Useful life (months)</label><input id="cp-li" type="number" min="1" max="600" step="1" value={d.useful_life_months} onChange={set('useful_life_months')} className={FIELD} /><InputError message={e.useful_life_months} /></div>
                <div><label className={LABEL} htmlFor="cp-me">Method</label><select id="cp-me" value={d.method} onChange={set('method')} className={FIELD}>{options.methods.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.method} /></div>
                {d.method === 'declining' && <div><label className={LABEL} htmlFor="cp-dr">Declining rate (% a year)</label><input id="cp-dr" type="number" min="0" max="100" step="0.01" value={d.declining_rate_pct} onChange={set('declining_rate_pct')} placeholder={`Default ${(200 / Math.max(1, d.useful_life_months / 12)).toFixed(0)}`} className={FIELD} /><InputError message={e.declining_rate_pct} /></div>}
            </div>
            <fieldset className="space-y-3 rounded-lg border border-gray-100 p-3">
                <legend className="px-1 text-xs font-medium text-[#4A5568]">Asset register</legend>
                <Check label="Create a new asset on approval" checked={d.create_asset} onChange={(v) => form.setData('create_asset', v)} />
                {d.create_asset ? (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div><label className={LABEL} htmlFor="cp-an">Asset name</label><input id="cp-an" maxLength={200} value={d.asset_name} onChange={set('asset_name')} className={FIELD} /><InputError message={e.asset_name} /></div>
                        <div><label className={LABEL} htmlFor="cp-at">Asset type</label><select id="cp-at" value={d.asset_type} onChange={set('asset_type')} className={FIELD}>{options.assetTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select><InputError message={e.asset_type} /></div>
                        <div><label className={LABEL} htmlFor="cp-ao">Asset owner</label><select id="cp-ao" value={d.asset_owner_id} onChange={set('asset_owner_id')} className={FIELD}><option value="">Source owner</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select><InputError message={e.asset_owner_id} /></div>
                    </div>
                ) : (
                    <div><label className={LABEL} htmlFor="cp-ai">Link an existing asset</label><select id="cp-ai" value={d.asset_id} onChange={set('asset_id')} className={FIELD}><option value="">Select…</option>{assets.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select><InputError message={e.asset_id} /></div>
                )}
            </fieldset>
            <InputError message={e.capitalisation || e.investment_id || e.expense_request_id || e.source} />
        </FormModal>
    );
}

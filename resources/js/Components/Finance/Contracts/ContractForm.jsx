import InputError from '@/Components/InputError';
import { Card, Check } from '@/Components/Finance/Settings/ui';
import Icon from '@/Components/Material/Icon';
import { BTN_PRIMARY, BTN_SECONDARY, FIELD, LABEL, addDays, isoDate, localDate, parseIso } from '@/Utils/finance';
import { Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const slug = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40) || 'clause';

function monthsBetween(a, b) {
    if (!a || !b) return '';
    const s = parseIso(a); const e = addDays(parseIso(b), 1);
    let m = (e.getFullYear() - s.getFullYear()) * 12 + (e.getMonth() - s.getMonth());
    if (e.getDate() > s.getDate()) m += 1;
    return Math.max(1, m);
}

function Field({ id, label, error, children, className = '' }) {
    return <div className={className}><label className={LABEL} htmlFor={id}>{label}</label>{children}<InputError message={error} /></div>;
}

function Section({ icon, title, children }) {
    return (
        <Card title={<span className="flex items-center gap-2"><Icon name={icon} size={18} />{title}</span>}>
            <div className="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
        </Card>
    );
}

/** Create / edit form for one contract. `contract` null = create. */
export default function ContractForm({ contract, options, vendors, users, costCentres, legalEntities, categories, budgetLines, currencies, defaults, baseCurrency, can }) {
    const money = can?.viewAmounts !== false;
    const c = contract;
    const form = useForm({
        title: c?.title ?? '', type: c?.type ?? options.types[0]?.value ?? 'licence', vendor_id: c?.vendor?.id ?? '', legal_entity_id: c?.legal_entity?.id ?? '',
        cost_centre_id: c?.cost_centre?.id ?? '', category_id: c?.category?.id ?? '', budget_line_id: c?.budget_line?.id ?? '', description: c?.description ?? '',
        owner_id: c ? (c.owner?.id ?? '') : (defaults.owner_id ?? ''), technical_owner_id: c?.technical_owner?.id ?? '', procurement_contact_id: c?.procurement_contact?.id ?? '',
        commenced_on: c?.commenced_on ?? '', ends_on: c?.ends_on ?? '', term_months: c?.term_months ?? '', notice_period_days: c?.notice_period_days ?? defaults.notice_period_days ?? 90,
        renewal_type: c?.renewal_type ?? 'manual', auto_renew: !!c?.auto_renew, renewal_lead_days: c?.renewal_lead_days ?? '',
        custom_offsets: !!c?.notification_offsets, notification_offsets: c?.notification_offsets ?? defaults.notification_offsets ?? [],
        currency: c?.currency ?? defaults.currency ?? baseCurrency, contract_value: c?.contract_value ?? '', billing_frequency: c?.billing_frequency ?? 'annual',
        amount_per_billing: c?.amount_per_billing ?? '', payment_terms_days: c?.payment_terms_days ?? '', price_escalation_pct: c?.price_escalation_pct ?? '',
        licence_metric: c?.licence_metric ?? '', licence_quantity: c?.licence_quantity ?? '', deployed_quantity: c?.deployed_quantity ?? '',
        data_processing_role: c?.data_processing_role ?? 'none', dpa_status: c?.dpa_status ?? 'not_required',
        sla_summary: c?.sla_summary ?? '', exit_terms: c?.exit_terms ?? '', key_clauses: c?.key_clauses ?? [], external_reference: c?.external_reference ?? '',
        activate: false,
    });
    const d = form.data;
    const e = form.errors;
    const set = (k) => (ev) => form.setData(k, ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value);
    const [termTouched, setTermTouched] = useState(!!c);
    const [newOffset, setNewOffset] = useState('');
    const lines = budgetLines.filter((l) => !d.cost_centre_id || String(l.cost_centre_id) === String(d.cost_centre_id) || String(l.id) === String(d.budget_line_id));

    const setDates = (k, v) => form.setData((x) => {
        const next = { ...x, [k]: v };
        if (!termTouched) next.term_months = monthsBetween(next.commenced_on, next.ends_on);
        return next;
    });

    const preview = useMemo(() => {
        if (!d.ends_on) return null;
        const end = parseIso(d.ends_on);
        const lead = d.renewal_lead_days === '' ? Number(defaults.renewal_lead_days) : Number(d.renewal_lead_days);
        const noticeDate = addDays(end, -Number(d.notice_period_days || 0));
        return { window: isoDate(addDays(noticeDate, -lead)), notice: isoDate(noticeDate), lead };
    }, [d.ends_on, d.renewal_lead_days, d.notice_period_days, defaults.renewal_lead_days]);

    const offsets = d.custom_offsets ? d.notification_offsets : (defaults.notification_offsets ?? []);
    const addOffset = () => {
        const n = parseInt(newOffset, 10);
        if (Number.isNaN(n) || n < 0 || n > 730 || d.notification_offsets.includes(n)) return;
        form.setData('notification_offsets', [...d.notification_offsets, n].sort((a, b) => b - a));
        setNewOffset('');
    };

    const submit = (ev) => {
        ev.preventDefault();
        form.transform((x) => {
            const out = { ...x };
            Object.keys(out).forEach((k) => { if (out[k] === '') out[k] = null; });
            out.notification_offsets = x.custom_offsets ? x.notification_offsets.map(Number) : null;
            delete out.custom_offsets;
            out.key_clauses = x.key_clauses.filter((k) => (k.label || '').trim()).map((k) => ({ key: k.key || slug(k.label), label: k.label, value: k.value || null }));
            if (!money && c) { delete out.contract_value; delete out.amount_per_billing; }
            if (c) delete out.activate;
            return out;
        });
        if (c) form.put(route('finance.contracts.update', c.id));
        else form.post(route('finance.contracts.store'));
    };

    const sel = (id, k, items, { none = 'None', fmt = (i) => i.name } = {}) => (
        <select id={id} value={d[k]} onChange={set(k)} className={FIELD}><option value="">{none}</option>{items.map((i) => <option key={i.id} value={i.id}>{fmt(i)}</option>)}</select>
    );
    const opt = (id, k, list) => <select id={id} value={d[k] ?? ''} onChange={set(k)} className={FIELD}>{list.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>;
    const userLabel = (u) => `${u.name}${u.job_title ? ` · ${u.job_title}` : ''}`;
    const dec = currencies.find((x) => x.code === d.currency)?.decimals ?? 2;

    return (
        <form onSubmit={submit} className="space-y-4">
            <Section icon="description" title="Basics">
                <Field id="k-title" label="Title" error={e.title} className="sm:col-span-2"><input id="k-title" maxLength={200} value={d.title} onChange={set('title')} className={FIELD} /></Field>
                <Field id="k-type" label="Type" error={e.type}>{opt('k-type', 'type', options.types)}</Field>
                <Field id="k-vendor" label="Vendor" error={e.vendor_id}>{sel('k-vendor', 'vendor_id', vendors, { none: 'Select…' })}</Field>
                <Field id="k-le" label="Legal entity" error={e.legal_entity_id}>{sel('k-le', 'legal_entity_id', legalEntities, { fmt: (i) => `${i.code} ${i.name}` })}</Field>
                <Field id="k-cc" label="Cost centre" error={e.cost_centre_id}>{sel('k-cc', 'cost_centre_id', costCentres, { fmt: (i) => `${i.code} ${i.name}` })}</Field>
                <Field id="k-cat" label="Category" error={e.category_id}>{sel('k-cat', 'category_id', categories, { fmt: (i) => `${i.code} ${i.name}` })}</Field>
                <Field id="k-bl" label="Budget line" error={e.budget_line_id}>{sel('k-bl', 'budget_line_id', lines, { fmt: (i) => i.label })}</Field>
                <Field id="k-ref" label="External reference" error={e.external_reference}><input id="k-ref" maxLength={100} value={d.external_reference} onChange={set('external_reference')} className={FIELD} /></Field>
                <Field id="k-desc" label="Description" error={e.description} className="sm:col-span-2 lg:col-span-3"><textarea id="k-desc" rows={3} value={d.description} onChange={set('description')} className={FIELD} /></Field>
            </Section>

            <Section icon="group" title="People">
                <Field id="k-owner" label="Owner" error={e.owner_id}>{sel('k-owner', 'owner_id', users, { none: 'Unassigned', fmt: userLabel })}</Field>
                <Field id="k-tech" label="Technical owner" error={e.technical_owner_id}>{sel('k-tech', 'technical_owner_id', users, { fmt: userLabel })}</Field>
                <Field id="k-proc" label="Procurement contact" error={e.procurement_contact_id}>{sel('k-proc', 'procurement_contact_id', users, { fmt: userLabel })}</Field>
            </Section>

            <Section icon="event_repeat" title="Term and renewal">
                <Field id="k-from" label="Commences on" error={e.commenced_on}><input id="k-from" type="date" value={d.commenced_on} onChange={(ev) => setDates('commenced_on', ev.target.value)} className={FIELD} /></Field>
                <Field id="k-to" label="Ends on" error={e.ends_on}><input id="k-to" type="date" value={d.ends_on} onChange={(ev) => setDates('ends_on', ev.target.value)} className={FIELD} /></Field>
                <Field id="k-term" label="Term (months)" error={e.term_months}><input id="k-term" type="number" min="1" value={d.term_months} onChange={(ev) => { setTermTouched(true); set('term_months')(ev); }} className={FIELD} /></Field>
                <Field id="k-notice" label="Notice period (days)" error={e.notice_period_days}><input id="k-notice" type="number" min="0" value={d.notice_period_days} onChange={set('notice_period_days')} className={FIELD} /></Field>
                <Field id="k-rt" label="Renewal type" error={e.renewal_type}>{opt('k-rt', 'renewal_type', options.renewalTypes)}</Field>
                <Field id="k-lead" label="Renewal window lead (days)" error={e.renewal_lead_days}><input id="k-lead" type="number" min="0" placeholder={`Default ${defaults.renewal_lead_days}`} value={d.renewal_lead_days} onChange={set('renewal_lead_days')} className={FIELD} /></Field>
                <div className="flex items-end"><Check label="Renews automatically" checked={d.auto_renew} onChange={(v) => form.setData('auto_renew', v)} /></div>
                <div className="sm:col-span-2 lg:col-span-3">
                    <p className={LABEL}>Reminder offsets (days before end and before notice deadline)</p>
                    <div className="mt-1"><Check label="Override the bank default for this contract" checked={d.custom_offsets} onChange={(v) => form.setData('custom_offsets', v)} /></div>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        {offsets.length === 0 && <span className="text-xs text-[#718096]">No reminders.</span>}
                        {offsets.map((n) => (
                            <span key={n} className="inline-flex h-7 items-center gap-1 rounded-lg bg-[#E9EBEF] px-2 text-xs text-[#43474E]">
                                T-{n}
                                {d.custom_offsets && <button type="button" aria-label={`Remove T-${n}`} onClick={() => form.setData('notification_offsets', d.notification_offsets.filter((x) => x !== n))}><Icon name="close" size={14} /></button>}
                            </span>
                        ))}
                        {d.custom_offsets && (
                            <span className="flex items-center gap-1">
                                <input aria-label="New offset in days" type="number" min="0" max="730" value={newOffset} onChange={(ev) => setNewOffset(ev.target.value)} onKeyDown={(ev) => { if (ev.key === 'Enter') { ev.preventDefault(); addOffset(); } }} className="w-20 rounded-lg border border-gray-200 px-2 py-1 text-xs" />
                                <button type="button" onClick={addOffset} className="rounded-lg border border-gray-200 px-2 py-1 text-xs">Add</button>
                            </span>
                        )}
                    </div>
                    <InputError message={e.notification_offsets} />
                </div>
                <div className="sm:col-span-2 lg:col-span-3 flex items-start gap-2 rounded-lg border border-[#F3E9D2] bg-[#FBF7EE] px-4 py-3 text-sm text-[#5E4818]" aria-live="polite">
                    <Icon name="event_upcoming" size={18} className="mt-0.5" />
                    {preview
                        ? <span>Renewal window opens <b>{localDate(preview.window)}</b> · Notice deadline <b>{localDate(preview.notice)}</b></span>
                        : <span>Enter the end date to preview the renewal window and notice deadline.</span>}
                </div>
            </Section>

            <Section icon="payments" title="Money">
                {money ? <>
                    <Field id="k-cur" label="Currency" error={e.currency}><select id="k-cur" value={d.currency} onChange={set('currency')} className={FIELD}>{currencies.map((x) => <option key={x.code} value={x.code}>{x.code}{x.is_base ? ' (base)' : ''}</option>)}</select></Field>
                    <Field id="k-val" label="Contract value (total)" error={e.contract_value}><input id="k-val" type="number" min="0" step={dec === 0 ? '1' : '0.01'} value={d.contract_value} onChange={set('contract_value')} className={FIELD} /></Field>
                    <Field id="k-bf" label="Billing frequency" error={e.billing_frequency}>{opt('k-bf', 'billing_frequency', options.billingFrequencies)}</Field>
                    <Field id="k-apb" label="Amount per billing" error={e.amount_per_billing}><input id="k-apb" type="number" min="0" step="0.01" value={d.amount_per_billing} onChange={set('amount_per_billing')} className={FIELD} /></Field>
                </> : <p className="text-sm text-[#718096] sm:col-span-2 lg:col-span-3">Amounts are hidden for your role. {c ? 'The stored value is kept as it is.' : 'The contract is created with a zero value.'}</p>}
                <Field id="k-pt" label="Payment terms (days)" error={e.payment_terms_days}><input id="k-pt" type="number" min="0" value={d.payment_terms_days} onChange={set('payment_terms_days')} className={FIELD} /></Field>
                <Field id="k-esc" label="Price escalation (% per term)" error={e.price_escalation_pct}><input id="k-esc" type="number" min="0" max="100" step="0.01" value={d.price_escalation_pct} onChange={set('price_escalation_pct')} className={FIELD} /></Field>
            </Section>

            <Section icon="key" title="Licence">
                <Field id="k-lm" label="Metric" error={e.licence_metric}><select id="k-lm" value={d.licence_metric} onChange={set('licence_metric')} className={FIELD}><option value="">Not licence based</option>{options.licenceMetrics.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></Field>
                <Field id="k-lq" label="Entitled quantity" error={e.licence_quantity}><input id="k-lq" type="number" min="0" step="0.01" value={d.licence_quantity} onChange={set('licence_quantity')} className={FIELD} /></Field>
                <Field id="k-dq" label="Deployed quantity" error={e.deployed_quantity}><input id="k-dq" type="number" min="0" step="0.01" value={d.deployed_quantity} onChange={set('deployed_quantity')} className={FIELD} /></Field>
            </Section>

            <Section icon="shield" title="Data protection">
                <Field id="k-dpr" label="Data processing role" error={e.data_processing_role}>{opt('k-dpr', 'data_processing_role', options.dpRoles)}</Field>
                <Field id="k-dpa" label="DPA status" error={e.dpa_status}>{opt('k-dpa', 'dpa_status', options.dpaStatuses)}</Field>
            </Section>

            <Card title={<span className="flex items-center gap-2"><Icon name="gavel" size={18} />Clauses</span>}>
                <div className="space-y-4 px-5 py-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field id="k-sla" label="SLA summary" error={e.sla_summary}><textarea id="k-sla" rows={3} value={d.sla_summary} onChange={set('sla_summary')} className={FIELD} /></Field>
                        <Field id="k-exit" label="Exit terms" error={e.exit_terms}><textarea id="k-exit" rows={3} value={d.exit_terms} onChange={set('exit_terms')} className={FIELD} /></Field>
                    </div>
                    <div>
                        <p className={LABEL}>Key clauses</p>
                        {d.key_clauses.map((k, i) => (
                            <div key={i} className="mt-2 flex flex-wrap gap-2">
                                <input aria-label={`Clause ${i + 1} label`} placeholder="Clause (e.g. Liability cap)" maxLength={80} value={k.label} onChange={(ev) => form.setData('key_clauses', d.key_clauses.map((x, j) => (j === i ? { ...x, label: ev.target.value, key: x.key || '' } : x)))} className="w-full rounded-lg border border-gray-200 px-2 py-1 text-sm sm:w-56" />
                                <input aria-label={`Clause ${i + 1} value`} placeholder="Value" maxLength={500} value={k.value ?? ''} onChange={(ev) => form.setData('key_clauses', d.key_clauses.map((x, j) => (j === i ? { ...x, value: ev.target.value } : x)))} className="min-w-0 flex-1 rounded-lg border border-gray-200 px-2 py-1 text-sm" />
                                <button type="button" onClick={() => form.setData('key_clauses', d.key_clauses.filter((_, j) => j !== i))} className="text-xs text-[#B3261E] underline">Remove</button>
                            </div>
                        ))}
                        {d.key_clauses.length < 20 && <button type="button" onClick={() => form.setData('key_clauses', [...d.key_clauses, { key: '', label: '', value: '' }])} className="mt-2 rounded-lg border border-gray-200 px-3 py-1 text-xs">Add clause</button>}
                        <InputError message={e.key_clauses} />
                    </div>
                </div>
            </Card>

            <InputError message={e.workflow} />
            <div className="flex flex-wrap items-center justify-end gap-3">
                {!c && <Check label="Save and activate" checked={d.activate} onChange={(v) => form.setData('activate', v)} />}
                <Link href={c ? route('finance.contracts.show', c.id) : route('finance.contracts.index')} className={BTN_SECONDARY}>Cancel</Link>
                <button disabled={form.processing} className={BTN_PRIMARY}>{c ? 'Save changes' : d.activate ? 'Create and activate' : 'Create draft'}</button>
            </div>
        </form>
    );
}

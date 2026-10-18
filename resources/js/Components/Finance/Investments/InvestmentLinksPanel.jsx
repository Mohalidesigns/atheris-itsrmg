import InputError from '@/Components/InputError';
import { AddButton, Card, Empty, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const TYPES = [['ea_application', 'Application'], ['capability', 'Capability'], ['business_service', 'Business service'], ['risk', 'Risk'], ['change', 'Change request']];

/** Links to EA applications and capabilities, business services, risks and change requests. */
export default function InvestmentLinksPanel({ investment, links, linkOptions, can }) {
    const [show, setShow] = useState(false);
    const form = useForm({ type: 'ea_application', id: '', note: '' });
    const choices = linkOptions?.[form.data.type] ?? [];
    const submit = () => {
        form.transform((d) => ({ ...d, note: d.note || null }));
        form.post(route('finance.investments.links.store', investment.id), { preserveScroll: true, onSuccess: () => { form.reset(); setShow(false); } });
    };
    const remove = (l) => confirm(`Remove the link to ${l.label}?`) && router.delete(route('finance.investments.links.destroy', { investment: investment.id, link: l.id }), { preserveScroll: true });
    return (
        <Card title="Linked records" right={can.edit && <AddButton onClick={() => setShow(true)}>Add link</AddButton>}>
            {links.length === 0 ? <Empty>Not linked to any application, capability, service, risk or change.</Empty> : TYPES.map(([key, label]) => {
                const group = links.filter((l) => l.type === key);
                if (group.length === 0) return null;
                return (
                    <div key={key} className="border-b border-gray-50 last:border-0">
                        <p className="bg-gray-50 px-5 py-1 text-xs font-medium uppercase tracking-wide text-[#718096]">{label}</p>
                        <ul className="divide-y divide-gray-50">
                            {group.map((l) => (
                                <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-sm">
                                    {l.href ? <Link className="text-[#0A1F44] underline" href={l.href}>{l.label}</Link> : <span className="text-[#2D3748]">{l.label}</span>}
                                    {can.edit && <button type="button" onClick={() => remove(l)} className="text-xs text-[#B3261E] underline">Remove</button>}
                                </li>
                            ))}
                        </ul>
                    </div>
                );
            })}
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.id} title="Link a record" submitLabel="Link">
                <div><label className={LABEL} htmlFor="il-type">Type</label>
                    <select id="il-type" value={form.data.type} onChange={(e) => form.setData({ ...form.data, type: e.target.value, id: '' })} className={FIELD}>{TYPES.map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select><InputError message={form.errors.type} /></div>
                <div><label className={LABEL} htmlFor="il-id">Record</label>
                    <select id="il-id" value={form.data.id} onChange={(e) => form.setData('id', e.target.value)} className={FIELD}><option value="">Select…</option>{choices.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}</select>
                    {choices.length === 0 && <p className="mt-1 text-xs text-[#718096]">No records of this type exist yet.</p>}<InputError message={form.errors.id} /></div>
                <div><label className={LABEL} htmlFor="il-note">Note (optional)</label><input id="il-note" maxLength={300} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className={FIELD} /><InputError message={form.errors.note} /></div>
            </FormModal>
        </Card>
    );
}

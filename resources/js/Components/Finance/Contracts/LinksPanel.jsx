import InputError from '@/Components/InputError';
import { AddButton, Card, Empty, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function LinksPanel({ contract, links, linkOptions, options, can }) {
    const [show, setShow] = useState(false);
    const form = useForm({ type: options.linkTypes[0]?.value ?? 'asset', id: '', note: '' });
    const choices = linkOptions[form.data.type] ?? [];
    const submit = () => { form.transform((d) => ({ ...d, note: d.note || null })); form.post(route('finance.contracts.links.store', contract.id), { preserveScroll: true, onSuccess: () => { form.reset(); setShow(false); } }); };
    const remove = (l) => confirm(`Remove the link to ${l.label}?`) && router.delete(route('finance.contracts.links.destroy', { contract: contract.id, link: l.id }), { preserveScroll: true });
    return (
        <Card title="Linked records" right={can.manageTeam && <AddButton onClick={() => setShow(true)}>Add link</AddButton>}>
            {links.length === 0 ? <Empty>This contract is not linked to any asset, application, service or risk.</Empty> : options.linkTypes.map((t) => {
                const group = links.filter((l) => l.type === t.value);
                if (group.length === 0) return null;
                return (
                    <div key={t.value} className="border-b border-gray-50 last:border-0">
                        <p className="bg-gray-50 px-5 py-1 text-xs font-medium uppercase tracking-wide text-[#718096]">{t.label}</p>
                        <ul className="divide-y divide-gray-50">
                            {group.map((l) => (
                                <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-sm">
                                    <span>{l.href ? <Link className="text-[#0A1F44] underline" href={l.href}>{l.label}</Link> : <span className="text-[#2D3748]">{l.label}</span>}{l.note && <span className="ml-2 text-xs text-[#718096]">{l.note}</span>}</span>
                                    {can.manageTeam && <button type="button" onClick={() => remove(l)} className="text-xs text-[#B3261E] underline">Remove</button>}
                                </li>
                            ))}
                        </ul>
                    </div>
                );
            })}
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.id} title="Link a record" submitLabel="Link">
                <div><label className={LABEL} htmlFor="ln-type">Type</label>
                    <select id="ln-type" value={form.data.type} onChange={(e) => form.setData({ ...form.data, type: e.target.value, id: '' })} className={FIELD}>{options.linkTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
                    <InputError message={form.errors.type} /></div>
                <div><label className={LABEL} htmlFor="ln-id">Record</label>
                    <select id="ln-id" value={form.data.id} onChange={(e) => form.setData('id', e.target.value)} className={FIELD}><option value="">Select…</option>{choices.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}</select>
                    {choices.length === 0 && <p className="mt-1 text-xs text-[#718096]">No records of this type exist yet.</p>}
                    <InputError message={form.errors.id} /></div>
                <div><label className={LABEL} htmlFor="ln-note">Note (optional)</label><input id="ln-note" maxLength={255} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className={FIELD} /><InputError message={form.errors.note} /></div>
            </FormModal>
        </Card>
    );
}

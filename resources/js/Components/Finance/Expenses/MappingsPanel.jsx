import { AddButton, Card, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import Field from './Field';
import InputError from '@/Components/InputError';
import { FIELD, humanize } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/** Saved GL column mappings. */
export default function MappingsPanel({ mappings, mappingKeys, dateFormats, canEdit }) {
    const [show, setShow] = useState(false);
    const form = useForm({ name: '', columns: {}, delimiter: ',', date_format: dateFormats[0] ?? 'Y-m-d', decimal_comma: false, has_header: true, default_currency: '' });
    const e = form.errors;
    const submit = () => {
        form.transform((d) => ({ ...d, default_currency: d.default_currency || null }));
        form.post(route('finance.actuals.mappings.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setShow(false); } });
    };
    const remove = (m) => confirm(`Delete mapping "${m.name}"?`) && router.delete(route('finance.actuals.mappings.destroy', m.id), { preserveScroll: true });
    return (
        <Card title="Saved column mappings" right={canEdit && <AddButton onClick={() => setShow(true)}>Add mapping</AddButton>}>
            <Table head={['Name', 'Columns', 'Delimiter', 'Date format', 'Header', 'Default', ...(canEdit ? [''] : [])]} empty={mappings.length === 0 && <Empty>No saved mappings. Save one from the import form.</Empty>}>
                {mappings.map((m) => (
                    <tr key={m.id}>
                        <td className="px-4 py-2 text-[#2D3748]">{m.name}</td>
                        <td className="min-w-[14rem] px-4 py-2 text-xs text-[#4A5568]">{Object.entries(m.columns ?? {}).map(([k, v]) => `${humanize(k)}: ${v}`).join(' · ')}</td>
                        <td className="px-4 py-2 text-xs">{m.delimiter === '\t' ? 'Tab' : m.delimiter}</td>
                        <td className="px-4 py-2 text-xs">{m.date_format}</td>
                        <td className="px-4 py-2 text-xs">{m.has_header ? 'Yes' : 'No'}</td>
                        <td className="px-4 py-2 text-xs">{m.is_default ? 'Yes' : ''}</td>
                        {canEdit && <td className="px-4 py-2 text-right text-xs"><button type="button" onClick={() => remove(m)} className="text-[#B3261E] underline">Delete</button></td>}
                    </tr>
                ))}
            </Table>
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.name} title="New column mapping" maxWidth="2xl">
                <Field id="mp-name" label="Name" error={e.name}><input id="mp-name" maxLength={120} value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} className={FIELD} /></Field>
                <div className="grid grid-cols-2 gap-3">
                    {[...mappingKeys.required.map((k) => [k, true]), ...mappingKeys.optional.map((k) => [k, false])].map(([k, req]) => (
                        <div key={k}><label className="text-xs text-[#4A5568]" htmlFor={`mp-${k}`}>{humanize(k)}{req ? ' (required)' : ''}</label><input id={`mp-${k}`} value={form.data.columns[k] ?? ''} onChange={(ev) => form.setData('columns', { ...form.data.columns, [k]: ev.target.value })} className={FIELD} /></div>
                    ))}
                </div>
                <InputError message={e.columns} />
                <div className="grid grid-cols-2 gap-3">
                    <Field id="mp-del" label="Delimiter" error={e.delimiter}><select id="mp-del" value={form.data.delimiter} onChange={(ev) => form.setData('delimiter', ev.target.value)} className={FIELD}><option value=",">Comma</option><option value=";">Semicolon</option><option value={'\t'}>Tab</option><option value="|">Pipe</option></select></Field>
                    <Field id="mp-df" label="Date format" error={e.date_format}><select id="mp-df" value={form.data.date_format} onChange={(ev) => form.setData('date_format', ev.target.value)} className={FIELD}>{dateFormats.map((f) => <option key={f} value={f}>{f}</option>)}</select></Field>
                </div>
            </FormModal>
        </Card>
    );
}

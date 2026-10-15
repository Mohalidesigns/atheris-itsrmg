import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const blank = { subject_type: 'Risk', key: '', label: '', data_type: 'text', required: false, options: '' };

export default function CustomFieldsSettings({ fields = [], entities = [] }) {
    const [open, setOpen] = useState(false);
    const form = useForm(blank);
    const grouped = fields.reduce((acc, f) => { (acc[f.subject_type] ||= []).push(f); return acc; }, {});

    const submit = (e) => {
        e.preventDefault();
        const options = form.data.data_type === 'select' ? form.data.options.split(',').map((o) => o.trim()).filter(Boolean) : [];
        form.transform((d) => ({ ...d, options }));
        form.post(route('settings.custom-fields.store'), { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };
    const err = (k) => form.errors[k] && <p className="text-xs text-[#B3261E] mt-1">{form.errors[k]}</p>;

    return (
        <AuthenticatedLayout header="Custom Fields">
            <Head title="Custom Fields" />
            <PageHeader
                breadcrumbs={[{ label: 'Settings' }, { label: 'Custom Fields' }]}
                title="Custom Field Editor"
                subtitle="Per-object custom fields for your bank (text, number, date, select)."
                actions={<button onClick={() => setOpen(!open)} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">+ Add field</button>}
            />
            {open && (
                <form onSubmit={submit} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div><select className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" value={form.data.subject_type} onChange={(e) => form.setData('subject_type', e.target.value)}>
                        {entities.map((x) => <option key={x}>{x}</option>)}</select>{err('subject_type')}</div>
                    <div><input className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2 font-mono" placeholder="key_slug" value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} />{err('key')}</div>
                    <div><input className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" placeholder="Label" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} />{err('label')}</div>
                    <div><select className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" value={form.data.data_type} onChange={(e) => form.setData('data_type', e.target.value)}>
                        {['text', 'number', 'date', 'select'].map((x) => <option key={x}>{x}</option>)}</select>{err('data_type')}</div>
                    {form.data.data_type === 'select' && <div><input className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" placeholder="Options, comma separated" value={form.data.options} onChange={(e) => form.setData('options', e.target.value)} />{err('options')}</div>}
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.required} onChange={(e) => form.setData('required', e.target.checked)} /> Required</label>
                    <div><button type="submit" disabled={form.processing} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Save field</button></div>
                </form>
            )}
            {Object.keys(grouped).length === 0 && <p className="text-sm text-[#718096]">No custom fields defined for your bank yet.</p>}
            {Object.entries(grouped).map(([subj, fs]) => (
                <div key={subj} className="bg-white rounded-xl border border-gray-100 shadow-sm mb-4 overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#0A1F44] border-b border-gray-100">{subj}</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Order</th><th className="px-3 py-2">Key</th><th className="px-3 py-2">Label</th>
                                <th className="px-3 py-2">Type</th><th className="px-3 py-2">Required</th><th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {fs.map((f) => (
                                <tr key={f.id}>
                                    <td className="px-3 py-2 text-xs">{f.order_index}</td>
                                    <td className="px-3 py-2 font-mono text-xs">{f.key}</td>
                                    <td className="px-3 py-2">{f.label}</td>
                                    <td className="px-3 py-2 text-xs">{f.data_type}{f.options?.length ? ` (${f.options.join(', ')})` : ''}</td>
                                    <td className="px-3 py-2 text-xs">{f.required ? 'Yes' : 'No'}</td>
                                    <td className="px-3 py-2 text-xs"><button className="text-[#B3261E]" onClick={() => confirm('Remove this field?') && router.delete(route('settings.custom-fields.destroy', f.id), { preserveScroll: true })}>Delete</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ))}
        </AuthenticatedLayout>
    );
}

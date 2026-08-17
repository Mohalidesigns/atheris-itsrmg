import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';

export default function BulkImport({ templates = [] }) {
    const form = useForm({ type: templates[0] || 'capability', csv: null });
    const submit = (e) => { e.preventDefault(); form.post(route('ea.bulk-import.run'), { forceFormData: true }); };

    return (
        <AuthenticatedLayout header="EA Bulk Import">
            <Head title="EA Bulk Import" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Operations' }, { label: 'Bulk Import' }]}
                title="Bulk Import (CSV)"
                subtitle="CSV templates for the 9 importable entity types. Drop-in compatible with the BulkImporter service."
            />

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
                <h3 className="font-bold text-[#0A1F44] mb-3">Templates</h3>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                    {templates.map((t) => (
                        <a key={t} href={route('ea.bulk-import.template', t)} className="block px-3 py-2 border border-gray-100 rounded-lg hover:border-[#C9A86A] text-[#0A1F44]">⬇ {t}.csv</a>
                    ))}
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h3 className="font-bold text-[#0A1F44] mb-3">Upload</h3>
                <form onSubmit={submit} className="space-y-3 text-sm">
                    <select value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className="w-full border border-gray-200 rounded-lg px-3 py-2">
                        {templates.map((t) => <option key={t} value={t}>{t}</option>)}
                    </select>
                    <input type="file" accept=".csv,text/csv" onChange={(e) => form.setData('csv', e.target.files?.[0])} className="w-full text-xs" />
                    <button disabled={form.processing} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">Run import</button>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

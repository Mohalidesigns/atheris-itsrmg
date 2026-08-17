import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function CustomFieldsSettings({ fields = [] }) {
    const grouped = fields.reduce((acc, f) => { (acc[f.subject_type] ||= []).push(f); return acc; }, {});
    return (
        <AuthenticatedLayout header="Custom Fields">
            <Head title="Custom Fields" />
            <PageHeader
                breadcrumbs={[{ label: 'Settings' }, { label: 'Custom Fields' }]}
                title="Custom Field Editor"
                subtitle="Per-object custom fields with data types (text, number, date, select, multiselect, boolean)."
                actions={<button className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">+ Add field</button>}
            />
            {Object.entries(grouped).map(([subj, fs]) => (
                <div key={subj} className="bg-white rounded-xl border border-gray-100 shadow-sm mb-4 overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#0A1F44] border-b border-gray-100">{subj}</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Order</th>
                                <th className="px-3 py-2">Key</th>
                                <th className="px-3 py-2">Label</th>
                                <th className="px-3 py-2">Type</th>
                                <th className="px-3 py-2">Required</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {fs.map((f) => (
                                <tr key={f.id}>
                                    <td className="px-3 py-2 text-xs">{f.order_index}</td>
                                    <td className="px-3 py-2 font-mono text-xs">{f.key}</td>
                                    <td className="px-3 py-2">{f.label}</td>
                                    <td className="px-3 py-2 text-xs">{f.data_type}</td>
                                    <td className="px-3 py-2 text-xs">{f.required ? 'Yes' : 'No'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ))}
        </AuthenticatedLayout>
    );
}

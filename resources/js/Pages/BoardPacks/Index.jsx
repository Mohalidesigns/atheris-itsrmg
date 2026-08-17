import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm } from '@inertiajs/react';
import { DocumentArrowDownIcon } from '@heroicons/react/24/outline';

export default function BoardPacksIndex({ templates = [], runs = [] }) {
    const { data, setData, post, processing } = useForm({ template_id: templates[0]?.id || '' });
    return (
        <AuthenticatedLayout header="Board Packs">
            <Head title="Board Packs" />
            <PageHeader
                breadcrumbs={[{ label: 'KRIs & Dashboards' }, { label: 'Board Packs' }]}
                title="Board Pack Generator"
                subtitle="Navy/Gold branded PPTX + PDF board packs with exec summary, risk heat map, KRI panel, top 10 risks, control status, CBN return readiness."
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <form onSubmit={(e) => { e.preventDefault(); post(route('board-packs.generate')); }}
                    className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Generate a board pack</h3>
                    <label className="block text-xs text-[#718096] mt-3">Template</label>
                    <select value={data.template_id} onChange={(e) => setData('template_id', e.target.value)}
                        className="mt-1 w-full rounded-lg border border-gray-200 text-sm px-3 py-2">
                        {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                    <button disabled={processing}
                        className="mt-4 w-full px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm hover:bg-[#1A2F54]">
                        Generate now
                    </button>
                </form>

                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Recent runs</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Template</th>
                                <th className="px-3 py-2">Period</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Generated</th>
                                <th className="px-3 py-2">Artefacts</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {runs.map((r) => (
                                <tr key={r.id}>
                                    <td className="px-3 py-2 font-medium text-[#0A1F44]">{r.template?.name}</td>
                                    <td className="px-3 py-2 text-xs">{r.period}</td>
                                    <td className="px-3 py-2"><StatusBadge status={r.status === 'generated' ? 'active' : 'draft'} label={r.status} /></td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{r.generated_at}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex items-center gap-2 text-xs">
                                            {r.pptx_path && <span className="inline-flex items-center gap-1 text-[#0A1F44]"><DocumentArrowDownIcon className="w-3 h-3" /> PPTX</span>}
                                            {r.pdf_path && <span className="inline-flex items-center gap-1 text-[#0A1F44]"><DocumentArrowDownIcon className="w-3 h-3" /> PDF</span>}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

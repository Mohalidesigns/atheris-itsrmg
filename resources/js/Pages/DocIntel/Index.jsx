import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm } from '@inertiajs/react';
import { CloudArrowUpIcon } from '@heroicons/react/24/outline';

export default function DocIntelIndex({ jobs = [] }) {
    const { data, setData, post, processing } = useForm({ doc_type: 'circular' });
    return (
        <AuthenticatedLayout header="Document Intelligence">
            <Head title="Document Intelligence" />
            <PageHeader
                breadcrumbs={[{ label: 'Regulatory Intelligence' }, { label: 'Document Intelligence' }]}
                title="Document Intelligence"
                subtitle="Upload circulars, NDPC decisions, audit reports or vendor DDQs. Pipeline: OCR → LLM structured extraction → schema mapping → review."
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <form onSubmit={(e) => { e.preventDefault(); post(route('doc-intel.upload')); }}
                    className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <CloudArrowUpIcon className="w-8 h-8 text-[#C9A86A]" />
                    <h3 className="text-sm font-semibold text-[#2D3748] mt-2">Upload a document</h3>
                    <label className="block text-xs text-[#718096] mt-3">Document type</label>
                    <select value={data.doc_type} onChange={(e) => setData('doc_type', e.target.value)}
                        className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        <option value="circular">CBN Circular</option>
                        <option value="ndpc_decision">NDPC Decision</option>
                        <option value="audit_report">Audit Report</option>
                        <option value="vendor_ddq">Vendor DDQ</option>
                    </select>
                    <div className="mt-3 border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                        <p className="text-xs text-[#718096]">Drop PDF here (demo: click submit to queue a sample)</p>
                    </div>
                    <button disabled={processing}
                        className="mt-4 w-full px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">
                        Queue for extraction
                    </button>
                </form>
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Recent jobs</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Type</th>
                                <th className="px-3 py-2">File</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Confidence</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {jobs.map((j) => (
                                <tr key={j.id}>
                                    <td className="px-3 py-2 text-xs text-[#0A1F44]">{j.doc_type}</td>
                                    <td className="px-3 py-2 font-mono text-[11px] text-[#718096] truncate max-w-[200px]">{j.source_file}</td>
                                    <td className="px-3 py-2"><StatusBadge status={j.status === 'accepted' ? 'active' : j.status === 'rejected' ? 'critical' : 'draft'} label={j.status} /></td>
                                    <td className="px-3 py-2 text-xs">{(Number(j.confidence) * 100).toFixed(1)}%</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

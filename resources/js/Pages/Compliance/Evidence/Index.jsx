import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import Pagination from '@/Components/Pagination';
import { DocumentIcon, LinkIcon, CameraIcon, ClipboardIcon } from '@heroicons/react/24/outline';

const typeIcons = { document: DocumentIcon, url: LinkIcon, screenshot: CameraIcon, attestation: ClipboardIcon, log: DocumentIcon };

export default function EvidenceIndex({ evidence }) {
    return (
        <AuthenticatedLayout header="Evidence Repository">
            <Head title="Evidence" />
            <p className="text-sm text-[#718096] mb-6">{evidence.total} evidence item{evidence.total !== 1 ? 's' : ''}</p>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead><tr className="bg-gray-50/50 border-b border-gray-100">
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Type</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Uploaded By</th>
                        <th className="px-4 py-3 text-left font-medium text-[#718096]">Valid Until</th>
                    </tr></thead>
                    <tbody className="divide-y divide-gray-50">
                        {evidence.data.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-12 text-center text-[#718096]">No evidence uploaded yet.</td></tr>
                        ) : evidence.data.map(e => {
                            const Icon = typeIcons[e.type] || DocumentIcon;
                            return (
                                <tr key={e.id} className="hover:bg-gray-50/50">
                                    <td className="px-4 py-3 flex items-center gap-2">
                                        <Icon className="w-4 h-4 text-[#718096]" />
                                        <span className="text-[#2D3748] font-medium">{e.title}</span>
                                    </td>
                                    <td className="px-4 py-3 capitalize text-[#718096] text-xs">{e.type}</td>
                                    <td className="px-4 py-3">
                                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${e.status === 'approved' ? 'bg-green-50 text-green-700' : e.status === 'rejected' ? 'bg-red-50 text-red-700' : e.status === 'expired' ? 'bg-gray-50 text-gray-500' : 'bg-amber-50 text-amber-700'}`}>
                                            {e.status.charAt(0).toUpperCase() + e.status.slice(1)}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{e.uploader?.name}</td>
                                    <td className="px-4 py-3 text-[#718096] text-xs">{e.valid_until || '--'}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={evidence.links} /></div>
            </div>
        </AuthenticatedLayout>
    );
}

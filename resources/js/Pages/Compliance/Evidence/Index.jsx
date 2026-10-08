import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '@/Components/Pagination';
import Chip from '@/Components/Compliance/Chip';
import EvidenceUploadModal from '@/Components/Compliance/EvidenceUploadModal';
import EvidenceReviewModal from '@/Components/Compliance/EvidenceReviewModal';
import { DocumentIcon, LinkIcon, CameraIcon, ClipboardIcon, CommandLineIcon, ArrowUpTrayIcon, MagnifyingGlassIcon, ArrowDownTrayIcon, TrashIcon } from '@heroicons/react/24/outline';
import { EVIDENCE_TYPES, evidenceState, formatDate } from '@/Utils/compliance';

const typeIcons = { document: DocumentIcon, url: LinkIcon, screenshot: CameraIcon, attestation: ClipboardIcon, log: CommandLineIcon };

export default function EvidenceIndex({ evidence, stats, filters, subjects, can }) {
    const [uploading, setUploading] = useState(false);
    const [reviewing, setReviewing] = useState(null);
    const [search, setSearch] = useState(filters.search || '');

    const apply = (patch) => router.get(route('evidence.index'), { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    const remove = (e) => {
        if (confirm(`Remove evidence "${e.title}"? This cannot be undone.`)) {
            router.delete(route('evidence.destroy', e.id), { preserveScroll: true });
        }
    };

    const cards = [
        ['All evidence', stats.total, null, 'text-[#2D3748]'],
        ['Approved', stats.approved, 'approved', 'text-[#2D7D46]'],
        ['Pending review', stats.pending, 'pending', 'text-[#B7791F]'],
        ['Rejected', stats.rejected, 'rejected', 'text-[#C53030]'],
        ['Expired', stats.expired, 'expired', 'text-[#718096]'],
    ];

    return (
        <AuthenticatedLayout header="Evidence Repository">
            <Head title="Evidence" />

            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <p className="text-sm text-[#718096]">Evidence supporting control operation, assessment results and gap remediation. Files are stored privately and served only to your organisation.</p>
                {can.create && (
                    <button onClick={() => setUploading(true)} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] shrink-0">
                        <ArrowUpTrayIcon className="w-4 h-4" /> Upload Evidence
                    </button>
                )}
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4">
                {cards.map(([label, value, key, tone]) => (
                    <button key={label} onClick={() => apply({ status: key || undefined, page: undefined })}
                        className={`text-left bg-white rounded-xl border p-3 shadow-sm hover:shadow ${(filters.status || null) === key ? 'border-[#1A365D] ring-1 ring-[#1A365D]/20' : 'border-gray-100'}`}>
                        <p className="text-xs text-[#718096] uppercase">{label}</p>
                        <p className={`text-2xl font-bold font-mono-data mt-1 ${tone}`}>{value}</p>
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 flex flex-wrap gap-2">
                    <form onSubmit={(e) => { e.preventDefault(); apply({ search: search || undefined, page: undefined }); }} className="relative flex-1 min-w-[220px]">
                        <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search title, description or file name…"
                            className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg" />
                    </form>
                    <select value={filters.type || ''} onChange={(e) => apply({ type: e.target.value || undefined, page: undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All types</option>
                        {Object.entries(EVIDENCE_TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <select value={filters.subject || ''} onChange={(e) => apply({ subject: e.target.value || undefined, page: undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">Attached to anything</option>
                        <option value="control">Controls</option>
                        <option value="compliance_result">Assessment results</option>
                        <option value="gap">Gaps</option>
                    </select>
                    {(filters.type || filters.subject || filters.status || filters.search) && (
                        <button onClick={() => { setSearch(''); router.get(route('evidence.index')); }} className="text-xs text-[#1A365D] underline px-2">Clear</button>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="bg-gray-50/50 border-y border-gray-100 text-left text-[#718096]">
                            <th className="px-4 py-3 font-medium">Evidence</th>
                            <th className="px-4 py-3 font-medium">Supports</th>
                            <th className="px-4 py-3 font-medium">Status</th>
                            <th className="px-4 py-3 font-medium">Uploaded</th>
                            <th className="px-4 py-3 font-medium">Valid until</th>
                            <th className="px-4 py-3 font-medium text-right">Actions</th>
                        </tr></thead>
                        <tbody className="divide-y divide-gray-50">
                            {evidence.data.length === 0 ? (
                                <tr><td colSpan={6} className="px-4 py-12 text-center text-[#718096]">No evidence matches these filters.</td></tr>
                            ) : evidence.data.map((e) => {
                                const Icon = typeIcons[e.type] || DocumentIcon;
                                const st = evidenceState(e);
                                return (
                                    <tr key={e.id} className="hover:bg-gray-50/50 align-top">
                                        <td className="px-4 py-3">
                                            <div className="flex items-start gap-2">
                                                <Icon className="w-4 h-4 text-[#718096] mt-0.5 shrink-0" />
                                                <div className="min-w-0">
                                                    <p className="text-[#2D3748] font-medium">{e.title}</p>
                                                    <p className="text-xs text-[#718096]">{EVIDENCE_TYPES[e.type] || e.type}{e.file_name ? ` · ${e.file_name}` : ''}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-xs max-w-xs">
                                            {e.subject ? (
                                                <>
                                                    <span className="text-[#A0AEC0] uppercase text-[10px] tracking-wide">{e.subject.kind}</span>
                                                    {e.subject.href ? <Link href={e.subject.href} className="block text-[#1A365D] hover:underline truncate">{e.subject.label}</Link> : <span className="block truncate">{e.subject.label}</span>}
                                                </>
                                            ) : <span className="text-[#A0AEC0]">—</span>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Chip className={st.chip}>{st.label}</Chip>
                                            {e.status === 'rejected' && e.review_notes && <p className="text-[11px] text-red-600 mt-1 max-w-[200px]">{e.review_notes}</p>}
                                            {e.reviewer && <p className="text-[11px] text-[#A0AEC0] mt-0.5">by {e.reviewer.name}</p>}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-[#718096]">{e.uploader?.name}<br />{formatDate(e.created_at)}</td>
                                        <td className={`px-4 py-3 text-xs ${e.is_expired ? 'text-red-600 font-medium' : 'text-[#718096]'}`}>{formatDate(e.valid_until)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                {(e.has_file || e.url) && (
                                                    <a href={route('evidence.download', e.id)} target="_blank" rel="noreferrer" title={e.type === 'url' ? 'Open link' : 'Download'} className="p-1 text-[#718096] hover:text-[#1A365D]">
                                                        <ArrowDownTrayIcon className="w-4 h-4" />
                                                    </a>
                                                )}
                                                {can.review && e.status === 'pending' && (
                                                    <button onClick={() => setReviewing(e)} className="text-xs px-2 py-1 rounded-lg bg-[#1A365D] text-white hover:bg-[#2D4A7A]">Review</button>
                                                )}
                                                {can.delete && (
                                                    <button onClick={() => remove(e)} title="Remove" className="p-1 text-[#A0AEC0] hover:text-red-600"><TrashIcon className="w-4 h-4" /></button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={evidence.links} /></div>
            </div>

            <EvidenceUploadModal show={uploading} onClose={() => setUploading(false)} subjectOptions={subjects} />
            <EvidenceReviewModal evidence={reviewing} onClose={() => setReviewing(null)} />
        </AuthenticatedLayout>
    );
}

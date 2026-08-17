import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

const gradeTone = (g) => ({ A: 'green', B: 'green', C: 'amber', D: 'red', F: 'red' })[String(g).toUpperCase()] || 'amber';

export default function TprmRatings({ vendors = [], ratings = {}, breaches = [] }) {
    return (
        <AuthenticatedLayout header="Vendor Security Ratings">
            <Head title="Security Ratings" />
            <PageHeader
                breadcrumbs={[{ label: 'Vendor Management' }, { label: 'Security Ratings' }]}
                title="TPRM — Continuous Security Ratings"
                subtitle="SecurityScorecard + Bitsight ratings refreshed daily; breach-news watch on every vendor."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Vendor</th>
                            <th className="px-3 py-2">Category</th>
                            <th className="px-3 py-2">Grade</th>
                            <th className="px-3 py-2">Score</th>
                            <th className="px-3 py-2">Provider</th>
                            <th className="px-3 py-2">Captured</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {vendors.map((v) => {
                            const r = (ratings[v.id] && ratings[v.id][0]) || {};
                            return (
                                <tr key={v.id}>
                                    <td className="px-3 py-2 font-medium text-[#0A1F44]">{v.name}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{v.vendor_type || v.service_category}</td>
                                    <td className="px-3 py-2">{r.grade ? <StatusBadge status={gradeTone(r.grade)} label={r.grade} /> : '—'}</td>
                                    <td className="px-3 py-2">{r.rating_value || '—'}</td>
                                    <td className="px-3 py-2 text-xs">{r.provider || '—'}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{r.captured_at || '—'}</td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {breaches.length > 0 && (
                <div className="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Breach-news watch</h3>
                    <ul className="divide-y divide-gray-100">
                        {breaches.map((b) => (
                            <li key={b.id} className="p-3 text-sm">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium text-[#2D3748]">{b.headline}</p>
                                        <p className="text-xs text-[#718096]">{b.event_type} · {b.discovered_at}</p>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

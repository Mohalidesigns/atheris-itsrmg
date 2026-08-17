import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function KriIndex({ kris = [], readingsByKri = {}, breaches = [] }) {
    const latestFor = (id) => (readingsByKri[id] && readingsByKri[id][0]) || null;
    return (
        <AuthenticatedLayout header="KRI Dashboard">
            <Head title="KRI Dashboard" />
            <PageHeader
                breadcrumbs={[{ label: 'KRIs & Dashboards' }, { label: 'KRI Dashboard' }]}
                title="Nigerian KRI Pack"
                subtitle="30 KRIs covering channel availability, fraud, patching, privileged access, policy attestation and more — CBN IT Standards aligned."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {kris.map((k) => {
                    const r = latestFor(k.id);
                    return (
                        <div key={k.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-xs text-[#718096] font-mono">{k.code}</p>
                                    <h3 className="text-sm font-semibold text-[#2D3748]">{k.name}</h3>
                                </div>
                                {r && <StatusBadge status={r.status} />}
                            </div>
                            <p className="text-xs text-[#718096] mt-1">{k.category}</p>
                            <div className="mt-3">
                                <p className="text-2xl font-bold text-[#0A1F44]">
                                    {r ? `${r.value} ${k.unit}` : '—'}
                                </p>
                                <p className="text-[10px] text-[#718096] mt-1">
                                    Green ≤ {k.threshold_green} · Amber ≤ {k.threshold_amber} · Red ≤ {k.threshold_red}
                                </p>
                            </div>
                            {readingsByKri[k.id] && (
                                <div className="mt-3 flex items-end gap-1 h-10">
                                    {readingsByKri[k.id].slice(0, 12).reverse().map((rd) => (
                                        <div key={rd.id}
                                             title={`${rd.period}: ${rd.value}`}
                                             className="flex-1 rounded-t"
                                             style={{
                                                 height: `${Math.min(100, Number(rd.value) * 2)}%`,
                                                 background: rd.status === 'red' ? '#B3261E' : rd.status === 'amber' ? '#E5A100' : '#2D7D46',
                                             }} />
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
            {breaches.length > 0 && (
                <div className="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Recent breaches</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">KRI</th>
                                <th className="px-3 py-2">Level</th>
                                <th className="px-3 py-2">Occurred</th>
                                <th className="px-3 py-2">Resolved</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {breaches.map((b) => (
                                <tr key={b.id}>
                                    <td className="px-3 py-2">{b.kri?.name || b.kri_id}</td>
                                    <td className="px-3 py-2"><StatusBadge status={b.level} /></td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{b.occurred_at}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{b.resolved_at || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

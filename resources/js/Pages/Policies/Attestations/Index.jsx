import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import KpiCard from '@/Components/KpiCard';
import { PolicyStatus, fmtDate, isPast } from '@/Components/Policies/PolicyBadges';
import { Head, Link, router } from '@inertiajs/react';

export default function AttestationsIndex({ attestations, stats = {}, policies = [], users = [], departments = [], filters = {}, statuses = [] }) {
    const apply = (key, value) => router.get(route('policy-attestations.index'), { ...filters, [key]: value || undefined, page: undefined }, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header="Policy Attestations">
            <Head title="Policy Attestations" />
            <div className="mb-4">
                <h2 className="text-lg font-semibold text-[#2D3748]">Policy Attestations</h2>
                <p className="text-sm text-[#718096]">Staff acknowledgements of the policies in force. Requests go out when a mandatory policy is published; staff attest from My Attestations.</p>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
                <KpiCard label="Attested" value={stats.rate == null ? '—' : `${stats.rate}%`} sublabel={`${stats.completed ?? 0} of ${stats.requested ?? 0} requests`} tone="navy" />
                <KpiCard label="Pending" value={stats.pending ?? 0} tone="white" />
                <KpiCard label="Overdue" value={stats.overdue ?? 0} tone={stats.overdue ? 'red' : 'white'} />
                <KpiCard label="Waived" value={stats.waived ?? 0} tone="white" />
                <KpiCard label="Policies in force" value={policies.length} tone="white" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 flex flex-wrap gap-3">
                    <select value={filters.status || ''} onChange={(e) => apply('status', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All statuses</option>
                        {statuses.map((s) => <option key={s} value={s}>{s.replace(/^./, (c) => c.toUpperCase())}</option>)}
                    </select>
                    <select value={filters.policy_id || ''} onChange={(e) => apply('policy_id', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All policies in force</option>
                        {policies.map((p) => <option key={p.id} value={p.id}>{p.policy_code} — {p.title}</option>)}
                    </select>
                    <select value={filters.department || ''} onChange={(e) => apply('department', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All departments</option>
                        {departments.map((d) => <option key={d} value={d}>{d}</option>)}
                    </select>
                    <select value={filters.user_id || ''} onChange={(e) => apply('user_id', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All staff</option>
                        {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                    {Object.values(filters).some(Boolean) && <button type="button" onClick={() => router.get(route('policy-attestations.index'))} className="text-xs text-[#0A1F44] underline">Clear all</button>}
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50 text-left text-[#718096]">
                                <th className="px-4 py-3 font-medium">Policy</th>
                                <th className="px-4 py-3 font-medium">Staff member</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Version</th>
                                <th className="px-4 py-3 font-medium">Due</th>
                                <th className="px-4 py-3 font-medium">Attested</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {attestations.data.length === 0 && <tr><td colSpan={6} className="px-4 py-12 text-center text-[#718096]">No attestations match these filters.</td></tr>}
                            {attestations.data.map((a) => {
                                const overdue = a.status === 'pending' && isPast(a.due_date);
                                return (
                                    <tr key={a.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3">
                                            <Link href={route('policy-attestations.show', a.id)} className="font-mono-data text-xs text-[#1A365D] hover:underline">{a.policy?.policy_code}</Link>
                                            <p className="text-[#2D3748]">{a.policy?.title}</p>
                                        </td>
                                        <td className="px-4 py-3">{a.user?.name}<p className="text-[11px] text-[#718096]">{a.user?.department || '—'}</p></td>
                                        <td className="px-4 py-3"><PolicyStatus status={overdue ? 'overdue' : a.status} /></td>
                                        <td className="px-4 py-3 font-mono-data text-xs">{a.version_number ? `v${a.version_number}` : '—'}</td>
                                        <td className={`px-4 py-3 text-xs ${overdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>{fmtDate(a.due_date) || '—'}</td>
                                        <td className="px-4 py-3 text-xs text-[#718096]">{fmtDate(a.acknowledged_at) || '—'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                    <p className="text-xs text-[#718096]">{attestations.total} matching</p>
                    <Pagination links={attestations.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

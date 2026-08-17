import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { HandThumbUpIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function PolicyAttestationsIndex({ attestations, stats = {}, policies = [], users = [], filters = {}, statuses = [] }) {
    const applyFilter = (key, value) => {
        router.get(route('policy-attestations.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Policy Attestations">
            <Head title="Policy Attestations" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Policy Attestations</h2>
                    <p className="text-sm text-[#718096] mt-1">
                        Attestation records are created when policies are published; staff acknowledge them from the policy page.
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Total</p>
                    <p className="mt-1 text-2xl font-bold font-mono-data text-[#2D3748]">{stats.total ?? 0}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Acknowledged</p>
                    <p className="mt-1 text-2xl font-bold font-mono-data text-[#2D7D46]">{stats.acknowledged ?? 0}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Pending</p>
                    <p className="mt-1 text-2xl font-bold font-mono-data text-[#D4AF37]">{stats.pending ?? 0}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Overdue</p>
                    <p className="mt-1 text-2xl font-bold font-mono-data text-[#C53030]">{stats.overdue ?? 0}</p>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 flex flex-wrap gap-3">
                    <select value={filters.policy_id || ''} onChange={e => applyFilter('policy_id', e.target.value)}
                        className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                        <option value="">All Policies</option>
                        {policies.map(p => <option key={p.id} value={p.id}>{p.title}</option>)}
                    </select>
                    <select value={filters.user_id || ''} onChange={e => applyFilter('user_id', e.target.value)}
                        className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                        <option value="">All Users</option>
                        {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                    <select value={filters.status || ''} onChange={e => applyFilter('status', e.target.value)}
                        className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                        <option value="">All Statuses</option>
                        {statuses.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                    </select>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Policy</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">User</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Department</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Due</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Acknowledged</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {attestations.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12 text-center text-[#718096]">
                                        <HandThumbUpIcon className="w-10 h-10 text-gray-300 mx-auto mb-2" />
                                        No attestation records found. Publish a policy to generate attestation requests.
                                    </td>
                                </tr>
                            ) : attestations.data.map(a => {
                                const overdue = a.status === 'pending' && a.due_date && new Date(a.due_date) < new Date();
                                return (
                                    <tr key={a.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('policy-attestations.show', a.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {a.policy?.title || '--'}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-[#2D3748]">{a.user?.name || '--'}</td>
                                        <td className="px-4 py-3 text-[#718096]">{a.user?.department || '--'}</td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={overdue ? 'fail' : a.status} label={overdue ? 'Overdue' : cap(a.status)} />
                                        </td>
                                        <td className="px-4 py-3 text-[#718096] text-xs">{a.due_date ? new Date(a.due_date).toLocaleDateString() : '--'}</td>
                                        <td className="px-4 py-3 text-[#718096] text-xs">{a.acknowledged_at ? new Date(a.acknowledged_at).toLocaleString() : '--'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={attestations.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

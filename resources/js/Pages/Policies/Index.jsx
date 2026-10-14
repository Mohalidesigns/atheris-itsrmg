import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import { PolicyStatus, fmtDate, isPast } from '@/Components/Policies/PolicyBadges';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, ChevronUpDownIcon, ArrowUpIcon } from '@/Components/Material/Symbols';

function Tile({ label, value, active, onClick, alert }) {
    return (
        <button type="button" onClick={onClick}
            className={`rounded-xl border px-3 py-2 text-left shadow-sm transition ${active ? 'border-[#0A1F44] bg-[#0A1F44]/5' : 'border-gray-100 bg-white hover:bg-gray-50'}`}>
            <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{value ?? '—'}</p>
            <p className="text-[11px] text-[#718096]">{label}</p>
        </button>
    );
}

export default function PoliciesIndex({ policies, metrics = {}, filters = {}, statuses = [], categories = {} }) {
    const { auth } = usePage().props;
    const can = (p) => auth?.user?.permissions?.includes(p) || auth?.user?.roles?.includes('Super Admin');
    const [search, setSearch] = useState(filters.search || '');

    const visit = (params) => router.get(route('policies.index'), params, { preserveState: true, preserveScroll: true });
    const apply = (key, value) => visit({ ...filters, [key]: value || undefined, page: undefined });
    const toggle = (key, value) => apply(key, filters[key] === value ? '' : value);
    const sortBy = (field) => visit({ ...filters, sort: field, direction: filters.sort === field && filters.direction !== 'desc' ? 'desc' : 'asc' });

    const SortHead = ({ field, children }) => (
        <th className="px-4 py-3 text-left font-medium text-[#718096]">
            <button type="button" onClick={() => sortBy(field)} className="inline-flex items-center gap-1 hover:text-[#0A1F44]">
                {children}
                {filters.sort === field ? <ArrowUpIcon className={`h-3 w-3 ${filters.direction === 'desc' ? 'rotate-180' : ''}`} /> : <ChevronUpDownIcon className="h-3 w-3 opacity-50" />}
            </button>
        </th>
    );

    return (
        <AuthenticatedLayout header="Policies">
            <Head title="Policies" />

            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Policy Register</h2>
                    <p className="text-sm text-[#718096]">
                        {metrics.active} in force · {metrics.attestation_rate == null ? 'no attestation campaigns' : `${metrics.attestation_rate}% attested`} · {metrics.exceptions_live} live exceptions
                    </p>
                </div>
                {can('create policies') && (
                    <Link href={route('policies.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                        <PlusIcon className="w-4 h-4" /> New Policy
                    </Link>
                )}
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7 mb-4">
                <Tile label="Drafts" value={metrics.by_status?.draft} active={filters.status === 'draft'} onClick={() => toggle('status', 'draft')} />
                <Tile label="Awaiting approval" value={metrics.awaiting_approval} active={filters.status === 'in_review'} onClick={() => toggle('status', 'in_review')} />
                <Tile label="Approved, not published" value={metrics.by_status?.approved} active={filters.status === 'approved'} onClick={() => toggle('status', 'approved')} />
                <Tile label="In force" value={metrics.active} active={filters.status === 'active'} onClick={() => toggle('status', 'active')} />
                <Tile label="Review overdue" value={metrics.review_overdue} alert={metrics.review_overdue > 0} active={filters.flag === 'review_overdue'} onClick={() => toggle('flag', 'review_overdue')} />
                <Tile label="In force, no control mapped" value={metrics.unmapped_active} alert={metrics.unmapped_active > 0} active={filters.flag === 'unmapped'} onClick={() => toggle('flag', 'unmapped')} />
                <Tile label="Attestations overdue" value={metrics.attestations_overdue} alert={metrics.attestations_overdue > 0} onClick={() => router.get(route('policy-attestations.index'), { status: 'overdue' })} />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 space-y-3">
                    <form onSubmit={(e) => { e.preventDefault(); apply('search', search); }} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search policies by title or code…"
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>
                    <div className="flex flex-wrap gap-3">
                        <select value={filters.category || ''} onChange={(e) => apply('category', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                            <option value="">All categories</option>
                            {Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </select>
                        <select value={filters.status || ''} onChange={(e) => apply('status', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                            <option value="">Current (not retired)</option>
                            {statuses.map((s) => <option key={s} value={s}>{s === 'active' ? 'In force' : s.replace('_', ' ').replace(/^./, (c) => c.toUpperCase())}</option>)}
                        </select>
                        {Object.values(filters).some(Boolean) && <button type="button" onClick={() => { setSearch(''); visit({}); }} className="text-xs text-[#0A1F44] underline">Clear all</button>}
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <SortHead field="policy_code">Code</SortHead>
                                <SortHead field="title">Policy</SortHead>
                                <SortHead field="status">Status</SortHead>
                                <SortHead field="version_number">Version</SortHead>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Attested</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Controls</th>
                                <SortHead field="review_date">Next review</SortHead>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {policies.data.length === 0 && <tr><td colSpan={8} className="px-4 py-12 text-center text-[#718096]">No policies match these filters.</td></tr>}
                            {policies.data.map((p) => {
                                const overdue = p.status === 'active' && isPast(p.review_date);
                                const pct = p.requested_count ? Math.round((p.attested_count / p.requested_count) * 100) : null;
                                return (
                                    <tr key={p.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3 whitespace-nowrap"><Link href={route('policies.show', p.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">{p.policy_code}</Link></td>
                                        <td className="px-4 py-3">
                                            <Link href={route('policies.show', p.id)} className="font-medium text-[#2D3748] hover:text-[#1A365D]">{p.title}</Link>
                                            <p className="text-[11px] text-[#718096]">{categories[p.category] || p.category || 'Uncategorised'}{p.is_mandatory ? ' · mandatory' : ''}</p>
                                        </td>
                                        <td className="px-4 py-3"><PolicyStatus status={p.status} /></td>
                                        <td className="px-4 py-3 font-mono-data text-xs">v{p.version_number}</td>
                                        <td className={`px-4 py-3 text-xs ${p.owner ? 'text-[#718096]' : 'text-[#B3261E]'}`}>{p.owner?.name || 'Unassigned'}</td>
                                        <td className="px-4 py-3 text-xs">
                                            {pct == null ? <span className="text-[#718096]">—</span> : (
                                                <div className="flex items-center gap-2">
                                                    <div className="h-1.5 w-16 rounded bg-gray-100"><div className={`h-1.5 rounded ${pct >= 90 ? 'bg-[#2D7D46]' : pct >= 70 ? 'bg-[#E5A100]' : 'bg-[#B3261E]'}`} style={{ width: `${pct}%` }} /></div>
                                                    <span>{pct}%</span>
                                                </div>
                                            )}
                                        </td>
                                        <td className={`px-4 py-3 text-center text-xs ${p.status === 'active' && !p.controls_count ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>{p.controls_count}</td>
                                        <td className={`px-4 py-3 text-xs whitespace-nowrap ${overdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>{fmtDate(p.review_date) || '--'}{overdue && ' · overdue'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                    <p className="text-xs text-[#718096]">{policies.total} matching</p>
                    <Pagination links={policies.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

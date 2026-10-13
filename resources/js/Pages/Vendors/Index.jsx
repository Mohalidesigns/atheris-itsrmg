import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Pagination from '@/Components/Pagination';
import StatusBadge from '@/Components/StatusBadge';
import { TierBadge, GradeBadge, fmtDate, isPast } from '@/Components/Vendors/VendorBadges';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, ChevronUpDownIcon, ArrowUpIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

function Tile({ label, value, active, onClick, alert }) {
    return (
        <button type="button" onClick={onClick}
            className={`rounded-xl border px-3 py-2 text-left shadow-sm transition ${active ? 'border-[#0A1F44] bg-[#0A1F44]/5' : 'border-gray-100 bg-white hover:bg-gray-50'}`}>
            <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{value ?? 0}</p>
            <p className="text-[11px] text-[#718096]">{label}</p>
        </button>
    );
}

export default function VendorsIndex({ vendors, metrics = {}, filters = {}, riskLevels = [], statuses = [], tiers = {} }) {
    const { auth } = usePage().props;
    const can = (p) => auth?.user?.permissions?.includes(p) || auth?.user?.roles?.includes('Super Admin');
    const [search, setSearch] = useState(filters.search || '');

    const visit = (params) => router.get(route('vendors.index'), params, { preserveState: true, preserveScroll: true });
    const apply = (key, value) => visit({ ...filters, [key]: value || undefined, page: undefined });
    const toggle = (key, value) => apply(key, String(filters[key] ?? '') === String(value) ? '' : value);
    const sortBy = (field) => visit({ ...filters, sort: field, direction: filters.sort === field && filters.direction !== 'desc' ? 'desc' : 'asc' });

    const SortHead = ({ field, children, className = '' }) => (
        <th className={`px-4 py-3 text-left font-medium text-[#718096] ${className}`}>
            <button type="button" onClick={() => sortBy(field)} className="inline-flex items-center gap-1 hover:text-[#0A1F44]">
                {children}
                {filters.sort === field ? <ArrowUpIcon className={`h-3 w-3 ${filters.direction === 'desc' ? 'rotate-180' : ''}`} /> : <ChevronUpDownIcon className="h-3 w-3 opacity-50" />}
            </button>
        </th>
    );

    return (
        <AuthenticatedLayout header="Vendor Register">
            <Head title="Vendor Register" />

            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Vendor Register</h2>
                    <p className="text-sm text-[#718096]">
                        {metrics.total} active relationships · {metrics.processors} process personal data · {metrics.material_outsourcing} material outsourcing
                    </p>
                </div>
                {can('create vendors') && (
                    <Link href={route('vendors.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                        <PlusIcon className="w-4 h-4" /> Add Vendor
                    </Link>
                )}
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8 mb-4">
                {[1, 2, 3].map((t) => (
                    <Tile key={t} label={tiers[t] || `Tier ${t}`} value={metrics.by_tier?.[t]} active={String(filters.tier) === String(t)} onClick={() => toggle('tier', t)} />
                ))}
                <Tile label="Reviews overdue" value={metrics.review_overdue} alert={metrics.review_overdue > 0} active={filters.flag === 'review_overdue'} onClick={() => toggle('flag', 'review_overdue')} />
                <Tile label="Contracts end ≤ 90 days" value={metrics.contracts_expiring} active={filters.flag === 'contract_expiring'} onClick={() => toggle('flag', 'contract_expiring')} />
                <Tile label="Processors without a DPA" value={metrics.missing_dpa} alert={metrics.missing_dpa > 0} active={filters.flag === 'missing_dpa'} onClick={() => toggle('flag', 'missing_dpa')} />
                <Tile label="Material, CBN not notified" value={metrics.material_not_notified} alert={metrics.material_not_notified > 0} active={filters.flag === 'material_not_notified'} onClick={() => toggle('flag', 'material_not_notified')} />
                <Tile label="No relationship owner" value={metrics.unowned} alert={metrics.unowned > 0} active={filters.flag === 'unowned'} onClick={() => toggle('flag', 'unowned')} />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 space-y-3">
                    <form onSubmit={(e) => { e.preventDefault(); apply('search', search); }} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search vendors by name, code or contact…"
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>
                    <div className="flex flex-wrap gap-3">
                        <select value={filters.risk_level || ''} onChange={(e) => apply('risk_level', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                            <option value="">All risk ratings</option>
                            {riskLevels.map((r) => <option key={r} value={r}>{cap(r)}</option>)}
                        </select>
                        <select value={filters.status || ''} onChange={(e) => apply('status', e.target.value)} className="text-sm border-gray-200 rounded-lg">
                            <option value="">Current relationships</option>
                            {statuses.map((s) => <option key={s} value={s}>{cap(s)}</option>)}
                        </select>
                        {Object.values(filters).some(Boolean) && <button type="button" onClick={() => { setSearch(''); visit({}); }} className="text-xs text-[#0A1F44] underline">Clear all</button>}
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <SortHead field="vendor_code">Code</SortHead>
                                <SortHead field="name">Vendor</SortHead>
                                <SortHead field="tier">Tier</SortHead>
                                <SortHead field="risk_level">Risk</SortHead>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Rating</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                                <SortHead field="contract_end">Contract end</SortHead>
                                <SortHead field="next_review_date">Next review</SortHead>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {vendors.data.length === 0 && <tr><td colSpan={8} className="px-4 py-12 text-center text-[#718096]">No vendors match these filters.</td></tr>}
                            {vendors.data.map((v) => {
                                const overdue = isPast(v.next_review_date);
                                return (
                                    <tr key={v.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <Link href={route('vendors.show', v.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">{v.vendor_code}</Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('vendors.show', v.id)} className="font-medium text-[#2D3748] hover:text-[#1A365D]">{v.name}</Link>
                                            <p className="text-[11px] text-[#718096]">
                                                {v.category}{v.status !== 'active' ? ` · ${cap(v.status)}` : ''}
                                                {v.processes_personal_data && !v.dpa_signed_at && <span className="ml-1 text-[#B3261E]">· no DPA</span>}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3"><TierBadge tier={v.tier} /></td>
                                        <td className="px-4 py-3"><StatusBadge status={v.risk_level} label={cap(v.risk_level)} /></td>
                                        <td className="px-4 py-3"><GradeBadge grade={v.latest_rating?.grade} value={v.latest_rating?.rating_value} /></td>
                                        <td className={`px-4 py-3 text-xs ${v.owner ? 'text-[#718096]' : 'text-[#B3261E]'}`}>{v.owner?.name || 'Unassigned'}</td>
                                        <td className="px-4 py-3 text-xs text-[#718096] whitespace-nowrap">{fmtDate(v.contract_end) || '--'}</td>
                                        <td className={`px-4 py-3 text-xs whitespace-nowrap ${overdue ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>
                                            {fmtDate(v.next_review_date) || '--'}{overdue && ' · overdue'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                    <p className="text-xs text-[#718096]">{vendors.total} matching</p>
                    <Pagination links={vendors.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

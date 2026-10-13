import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon, ArrowDownTrayIcon, ChevronUpDownIcon, ArrowUpIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';
import { CriticalityBadge, AssetStatusBadge, typeLabels, sourceLabels } from '@/Components/Assets/AssetBadges';

const flags = [
    ['unowned', 'Unowned'],
    ['unclassified', 'Unclassified'],
    ['eol_passed', 'Past end of life'],
    ['eol_soon', 'End of life ≤ 90 days'],
    ['unmapped', 'Not mapped to a service'],
    ['stale', 'Not seen by its connector (30 d)'],
];

function Tile({ label, value, active, onClick, alert }) {
    return (
        <button type="button" onClick={onClick}
            className={`rounded-xl border px-3 py-2 text-left shadow-sm transition ${active ? 'border-[#0A1F44] bg-[#0A1F44]/5' : 'border-gray-100 bg-white hover:bg-gray-50'}`}>
            <p className={`text-xl font-bold ${alert ? 'text-[#B3261E]' : 'text-[#0A1F44]'}`}>{value ?? 0}</p>
            <p className="text-[11px] text-[#718096]">{label}</p>
        </button>
    );
}

export default function AssetsIndex({ assets, metrics, filters, assetTypes, criticalities, statuses, sources }) {
    const { auth } = usePage().props;
    const can = (p) => auth?.user?.permissions?.includes(p) || auth?.user?.roles?.includes('Super Admin');
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(Boolean(filters.asset_type || filters.status || filters.source));

    const visit = (params) => router.get(route('assets.index'), params, { preserveState: true, preserveScroll: true });
    const applyFilter = (key, value) => visit({ ...filters, [key]: value || undefined, page: undefined });
    const toggleFlag = (flag) => applyFilter('flag', filters.flag === flag ? '' : flag);
    const sortBy = (field) => visit({ ...filters, sort: field, direction: filters.sort === field && filters.direction !== 'desc' ? 'desc' : 'asc' });

    const SortHead = ({ field, children, className = '' }) => (
        <th className={`px-4 py-3 text-left font-medium text-[#718096] ${className}`}>
            <button type="button" onClick={() => sortBy(field)} className="inline-flex items-center gap-1 hover:text-[#0A1F44]">
                {children}
                {filters.sort === field
                    ? <ArrowUpIcon className={`h-3 w-3 ${filters.direction === 'desc' ? 'rotate-180' : ''}`} />
                    : <ChevronUpDownIcon className="h-3 w-3 opacity-50" />}
            </button>
        </th>
    );

    const m = metrics || {};

    return (
        <AuthenticatedLayout header="Asset Register">
            <Head title="Asset Register" />

            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <h2 className="text-lg font-semibold text-[#2D3748]">Asset Register</h2>
                    <p className="text-sm text-[#718096]">
                        {m.total} live assets · {m.discovered} found by discovery connectors · {m.inherited_uplift} rated up by the business services they support
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <button onClick={() => setShowFilters(!showFilters)}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        <FunnelIcon className="w-4 h-4" /> Filters
                    </button>
                    {can('export assets') && (
                        <a href={route('assets.export', filters)}
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                            <ArrowDownTrayIcon className="w-4 h-4" /> Export CSV
                        </a>
                    )}
                    {can('create assets') && (
                        <Link href={route('assets.create')}
                            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54] transition-colors">
                            <PlusIcon className="w-4 h-4" /> Add Asset
                        </Link>
                    )}
                </div>
            </div>

            {/* Register hygiene: each tile filters the table. */}
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8 mb-4">
                {criticalities.map((c) => (
                    <Tile key={c} label={`${c.charAt(0).toUpperCase() + c.slice(1)} (effective)`} value={m.by_criticality?.[c]}
                        active={filters.criticality === c} onClick={() => applyFilter('criticality', filters.criticality === c ? '' : c)} />
                ))}
                <Tile label="Unowned" value={m.unowned} alert={m.unowned > 0} active={filters.flag === 'unowned'} onClick={() => toggleFlag('unowned')} />
                <Tile label="Past end of life" value={m.eol_passed} alert={m.eol_passed > 0} active={filters.flag === 'eol_passed'} onClick={() => toggleFlag('eol_passed')} />
                <Tile label="EOL ≤ 90 days" value={m.eol_soon} active={filters.flag === 'eol_soon'} onClick={() => toggleFlag('eol_soon')} />
                <Tile label="Crit/high without a service" value={m.important_unmapped} alert={m.important_unmapped > 0} active={filters.flag === 'unmapped'} onClick={() => toggleFlag('unmapped')} />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
                <div className="p-4">
                    <form onSubmit={(e) => { e.preventDefault(); applyFilter('search', search); }} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Search assets by name, ID, hostname, or IP..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]" />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">Search</button>
                    </form>

                    {showFilters && (
                        <div className="flex flex-wrap gap-3 mt-3 pt-3 border-t border-gray-100">
                            <select value={filters.asset_type || ''} onChange={e => applyFilter('asset_type', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                                <option value="">All types</option>
                                {assetTypes.map(t => <option key={t} value={t}>{typeLabels[t] || t}</option>)}
                            </select>
                            <select value={filters.status || ''} onChange={e => applyFilter('status', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                                <option value="">All statuses</option>
                                {statuses.map(s => <option key={s} value={s}>{s.replace('_', ' ').replace(/^./, (c) => c.toUpperCase())}</option>)}
                            </select>
                            <select value={filters.source || ''} onChange={e => applyFilter('source', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                                <option value="">All sources</option>
                                {sources.map(s => <option key={s} value={s}>{sourceLabels[s] || s}</option>)}
                            </select>
                            <select value={filters.flag || ''} onChange={e => applyFilter('flag', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]">
                                <option value="">No hygiene flag</option>
                                {flags.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                            </select>
                            {Object.values(filters).some(Boolean) && (
                                <button type="button" onClick={() => { setSearch(''); visit({}); }} className="text-xs text-[#0A1F44] underline">Clear all</button>
                            )}
                        </div>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <SortHead field="asset_id_code">Asset ID</SortHead>
                                <SortHead field="name">Name</SortHead>
                                <SortHead field="asset_type">Type</SortHead>
                                <SortHead field="criticality" className="text-center">Criticality</SortHead>
                                <SortHead field="status">Status</SortHead>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                                <SortHead field="department">Department</SortHead>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Services</th>
                                <SortHead field="end_of_life">End of life</SortHead>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {assets.data.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="px-4 py-12 text-center text-[#718096]">
                                        No assets match these filters.
                                    </td>
                                </tr>
                            ) : assets.data.map(asset => {
                                const eol = asset.end_of_life ? new Date(asset.end_of_life) : null;
                                const eolPast = eol && eol < new Date();
                                return (
                                    <tr key={asset.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <Link href={route('assets.show', asset.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                                {asset.asset_id_code}
                                            </Link>
                                            {asset.source && asset.source !== 'manual' && (
                                                <span className="ml-1 rounded bg-gray-100 px-1 text-[10px] uppercase text-[#718096]" title={`Source: ${sourceLabels[asset.source] || asset.source}`}>{asset.source}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('assets.show', asset.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">{asset.name}</Link>
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">{typeLabels[asset.asset_type] || asset.asset_type}</td>
                                        <td className="px-4 py-3 text-center whitespace-nowrap">
                                            <CriticalityBadge criticality={asset.effective_criticality} />
                                            {asset.effective_criticality !== asset.criticality && (
                                                <span className="ml-1 text-[10px] text-[#8A6400]" title={`Rated ${asset.criticality} by its owner; inherits ${asset.criticality_inherited} from a business service it supports`}>↑ inherited</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3"><AssetStatusBadge status={asset.status} /></td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {asset.owner?.name || <span className="text-[#B3261E]">Unowned</span>}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">{asset.department || '--'}</td>
                                        <td className="px-4 py-3 text-center text-[#718096]">{asset.business_services_count || '--'}</td>
                                        <td className={`px-4 py-3 whitespace-nowrap ${eolPast ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}`}>
                                            {eol ? eol.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '--'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
                    <p className="text-xs text-[#718096]">{assets.total} matching</p>
                    <Pagination links={assets.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

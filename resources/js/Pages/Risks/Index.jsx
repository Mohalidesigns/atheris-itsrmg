import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon, TableCellsIcon, Squares2X2Icon, XMarkIcon } from '@heroicons/react/24/outline';
import { RatingBadge, StatusBadge, ScoreDisplay } from '@/Components/Risk/RiskBadge';
import { APPETITE_LABELS, IMPACT_LABELS, LIKELIHOOD_LABELS, humanize, ratingColor } from '@/Utils/risk';
import Pagination from '@/Components/Pagination';

// Rows = likelihood (high at top), columns = impact — same orientation as the dashboard heat map.
// A 3×3 / 4×4 view buckets the 1–5 scale into fewer bands; colours come from the shared rating scale.
function InlineHeatMap({ risks = [], size = 5, onCellClick }) {
    const bucket = (v) => Math.min(size, Math.max(1, Math.ceil((v / 5) * size)));
    const matrix = Array.from({ length: size }, () => Array.from({ length: size }, () => []));
    risks.forEach((r) => {
        if (!r.likelihood || !r.impact) return;
        matrix[size - bucket(r.likelihood)][bucket(r.impact) - 1].push(r);
    });
    // Representative 5×5 score for a bucket = its upper bound on each axis.
    const upper = (b) => Math.round((b / size) * 5);
    return (
        <div className="overflow-x-auto">
            <div className="inline-flex">
                <div className="grid gap-1 mr-2" style={{ gridTemplateRows: `repeat(${size}, 80px)` }}>
                    {Array.from({ length: size }).map((_, rowIdx) => (
                        <span key={rowIdx} className="flex items-center justify-end text-[10px] text-[#718096] w-16 text-right">
                            {size === 5 ? LIKELIHOOD_LABELS[5 - rowIdx] : `L band ${size - rowIdx}`}
                        </span>
                    ))}
                </div>
                <div>
                    <div className="grid gap-1" style={{ gridTemplateColumns: `repeat(${size}, 88px)` }}>
                        {Array.from({ length: size }).map((_, rowIdx) => {
                            const lBand = size - rowIdx;
                            return Array.from({ length: size }).map((_, colIdx) => {
                                const iBand = colIdx + 1;
                                const items = matrix[rowIdx][colIdx];
                                const color = ratingColor(upper(lBand) * upper(iBand));
                                return (
                                    <div key={`${rowIdx}-${colIdx}`}
                                        onClick={() => size === 5 && items.length > 0 && onCellClick?.(lBand, iBand)}
                                        className={`relative rounded-md h-20 p-1 ${size === 5 && items.length ? 'cursor-pointer hover:ring-2 hover:ring-[#0A1F44]' : ''}`}
                                        style={{ backgroundColor: color + (items.length ? 'CC' : '40') }}>
                                        <span className="text-white text-[11px] font-bold absolute top-1 right-1.5">{items.length || ''}</span>
                                        <div className="flex flex-wrap gap-0.5 pt-4">
                                            {items.slice(0, 12).map((r) => (
                                                <Link key={r.id} href={route('risks.show', r.id)} title={`${r.risk_id_code} — ${r.title}`}
                                                    onClick={(e) => e.stopPropagation()}
                                                    className="w-2.5 h-2.5 rounded-full bg-white/90 hover:bg-white hover:scale-125 transition-transform" />
                                            ))}
                                        </div>
                                    </div>
                                );
                            });
                        })}
                    </div>
                    <div className="grid gap-1 mt-1" style={{ gridTemplateColumns: `repeat(${size}, 88px)` }}>
                        {Array.from({ length: size }).map((_, i) => (
                            <span key={i} className="text-[10px] text-[#718096] text-center">{size === 5 ? IMPACT_LABELS[i + 1] : `I band ${i + 1}`}</span>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

function SortHeader({ label, field, filters, onSort, className = '' }) {
    const active = filters.sort === field;
    const arrow = active ? (filters.direction === 'asc' ? ' ▲' : ' ▼') : '';
    return (
        <th className={`px-4 py-3 font-medium text-[#718096] ${className}`}>
            <button type="button" onClick={() => onSort(field)} className={`hover:text-[#1A365D] ${active ? 'text-[#1A365D]' : ''}`}>
                {label}{arrow}
            </button>
        </th>
    );
}

const FILTER_KEYS = ['status', 'rating', 'category_id', 'owner_id', 'appetite', 'search', 'likelihood', 'impact'];

export default function RisksIndex({ risks, heatMapRisks = [], categories, users, filters, statuses, ratings, appetites = [] }) {
    const [search, setSearch] = useState(filters.search || '');
    const hasFilters = FILTER_KEYS.some((k) => filters[k]);
    const [showFilters, setShowFilters] = useState(['status', 'rating', 'category_id', 'owner_id', 'appetite'].some((k) => filters[k]));
    const [view, setView] = useState(() => (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('view') === 'heatmap' ? 'heatmap' : 'table'));
    const [matrixSize, setMatrixSize] = useState(5);
    const basis = filters.basis === 'residual' ? 'residual' : 'inherent';

    const visit = (params) => router.get(route('risks.index'), params, { preserveState: true, preserveScroll: true, replace: true });

    const handleSearch = (e) => {
        e.preventDefault();
        visit({ ...filters, search: search || undefined, page: undefined });
    };

    const applyFilter = (key, value) => visit({ ...filters, [key]: value || undefined });

    const onSort = (field) => visit({
        ...filters,
        sort: field,
        direction: filters.sort === field && filters.direction !== 'asc' ? 'asc' : 'desc',
    });

    const clearFilters = () => {
        setSearch('');
        router.get(route('risks.index'), {}, { preserveState: false });
    };

    const drillCell = (likelihood, impact) => visit({ ...filters, likelihood, impact, basis });

    return (
        <AuthenticatedLayout header="Risk Register">
            <Head title="Risk Register" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Risk Management' }, { label: 'Risk Register' }]}
                title="Risk Register"
                subtitle={`${risks.total} risk${risks.total !== 1 ? 's' : ''} ${hasFilters ? 'match the current filters' : 'registered'} · CBN-aligned taxonomy (Cyber, Tech, Ops-Tech, TPRM, Data Protection, Fraud, Physical, Regulatory)`}
                actions={<>
                    <div className="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                        <button onClick={() => setView('table')} className={`px-2 py-1.5 text-xs flex items-center gap-1 ${view === 'table' ? 'bg-[#0A1F44] text-white' : 'bg-white text-[#2D3748]'}`}>
                            <TableCellsIcon className="w-4 h-4" /> Register
                        </button>
                        <button onClick={() => setView('heatmap')} className={`px-2 py-1.5 text-xs flex items-center gap-1 ${view === 'heatmap' ? 'bg-[#0A1F44] text-white' : 'bg-white text-[#2D3748]'}`}>
                            <Squares2X2Icon className="w-4 h-4" /> Heat Map
                        </button>
                    </div>
                    <Link href={route('risks.create')}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-white bg-[#0A1F44] rounded-lg hover:bg-[#1A2F54]">
                        <PlusIcon className="w-4 h-4" /> Add Risk
                    </Link>
                </>}
            />

            {view === 'heatmap' && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
                    <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h3 className="text-sm font-semibold text-[#2D3748]">Configurable Risk Heat Map</h3>
                            <p className="text-xs text-[#718096]">
                                {heatMapRisks.length} {basis} scored risk{heatMapRisks.length === 1 ? '' : 's'} matching the filters · hover a dot for detail · {matrixSize === 5 ? 'click a cell to filter the register' : 'switch to 5×5 to drill into a cell'}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <div className="inline-flex rounded-lg border border-gray-200 overflow-hidden text-xs">
                                {['inherent', 'residual'].map((b) => (
                                    <button key={b} onClick={() => applyFilter('basis', b === 'inherent' ? '' : b)}
                                        className={`px-2 py-1 capitalize ${basis === b ? 'bg-[#0A1F44] text-white' : 'bg-white text-[#2D3748]'}`}>
                                        {b}
                                    </button>
                                ))}
                            </div>
                            <div className="inline-flex rounded-lg border border-gray-200 overflow-hidden text-xs">
                                {[3, 4, 5].map((s) => (
                                    <button key={s} onClick={() => setMatrixSize(s)}
                                        className={`px-2 py-1 ${matrixSize === s ? 'bg-[#C9A86A] text-[#0A1F44] font-semibold' : 'bg-white text-[#2D3748]'}`}>
                                        {s}×{s}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                    <InlineHeatMap risks={heatMapRisks} size={matrixSize} onCellClick={drillCell} />
                    <p className="text-[10px] text-[#718096] mt-3">Likelihood (rows) × Impact (columns)</p>
                </div>
            )}

            {/* Header Bar (legacy) */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <p className="text-sm text-[#718096]">
                        Viewing page {risks.current_page} of {risks.last_page} · {risks.per_page} per page
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {hasFilters && (
                        <button onClick={clearFilters} className="inline-flex items-center gap-1 px-3 py-2 text-sm text-[#C53030] hover:bg-red-50 rounded-lg">
                            <XMarkIcon className="w-4 h-4" /> Clear filters
                        </button>
                    )}
                    <button
                        onClick={() => setShowFilters(!showFilters)}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                    >
                        <FunnelIcon className="w-4 h-4" />
                        Filters
                    </button>
                </div>
            </div>

            {/* Search & Filters */}
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm mb-6">
                <div className="p-4">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Search risks by title, ID, or description..."
                                className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            />
                        </div>
                        <button type="submit" className="px-4 py-2 text-sm bg-gray-100 rounded-lg hover:bg-gray-200 text-[#2D3748]">
                            Search
                        </button>
                    </form>

                    {showFilters && (
                        <div className="flex flex-wrap gap-3 mt-3 pt-3 border-t border-gray-100">
                            <select
                                value={filters.status || ''}
                                onChange={e => applyFilter('status', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Statuses</option>
                                <option value="active">Active (not closed / archived)</option>
                                {statuses.map(s => (
                                    <option key={s} value={s}>{humanize(s)}</option>
                                ))}
                            </select>
                            <select
                                value={filters.rating || ''}
                                onChange={e => applyFilter('rating', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Ratings</option>
                                {ratings.map(r => (
                                    <option key={r} value={r}>{humanize(r)}</option>
                                ))}
                            </select>
                            <select
                                value={filters.category_id || ''}
                                onChange={e => applyFilter('category_id', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Categories</option>
                                {categories.map(c => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                            <select
                                value={filters.owner_id || ''}
                                onChange={e => applyFilter('owner_id', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Owners</option>
                                {users.map(u => (
                                    <option key={u.id} value={u.id}>{u.name}</option>
                                ))}
                            </select>
                            <select
                                value={filters.appetite || ''}
                                onChange={e => applyFilter('appetite', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">Any Appetite</option>
                                {appetites.map(a => (
                                    <option key={a} value={a}>{APPETITE_LABELS[a] || humanize(a)}</option>
                                ))}
                            </select>
                        </div>
                    )}

                    {filters.likelihood && filters.impact && (
                        <div className="mt-3 inline-flex items-center gap-2 text-xs bg-[#0A1F44]/5 text-[#0A1F44] px-3 py-1.5 rounded-full">
                            Heat-map cell: {humanize(basis)} likelihood {filters.likelihood} ({LIKELIHOOD_LABELS[filters.likelihood]}) × impact {filters.impact} ({IMPACT_LABELS[filters.impact]})
                            <button onClick={() => visit({ ...filters, likelihood: undefined, impact: undefined, basis: undefined })} aria-label="Remove heat-map cell filter">
                                <XMarkIcon className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <SortHeader label="Risk ID" field="risk_id_code" filters={filters} onSort={onSort} className="text-left" />
                                <SortHeader label="Title" field="title" filters={filters} onSort={onSort} className="text-left" />
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Category</th>
                                <SortHeader label="Inherent" field="inherent_score" filters={filters} onSort={onSort} className="text-center" />
                                <SortHeader label="Residual" field="residual_score" filters={filters} onSort={onSort} className="text-center" />
                                <SortHeader label="Status" field="status" filters={filters} onSort={onSort} className="text-left" />
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Appetite</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {risks.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-12 text-center text-[#718096]">
                                        {hasFilters ? 'No risks match these filters.' : 'No risks found. Create your first risk to get started.'}
                                    </td>
                                </tr>
                            ) : (
                                risks.data.map(risk => (
                                    <tr key={risk.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <Link href={route('risks.show', risk.id)} className="font-mono-data text-[#1A365D] font-medium hover:underline">
                                                {risk.risk_id_code}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('risks.show', risk.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">
                                                {risk.title}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            {risk.category ? (
                                                <span className="inline-flex items-center gap-1.5 text-xs">
                                                    <span className="w-2 h-2 rounded-full" style={{ backgroundColor: risk.category.color }} />
                                                    {risk.category.name}
                                                </span>
                                            ) : <span className="text-gray-400">--</span>}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <ScoreDisplay score={risk.inherent_score} />
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <ScoreDisplay score={risk.residual_score} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={risk.status} />
                                        </td>
                                        <td className="px-4 py-3 text-xs">
                                            {risk.risk_appetite ? (
                                                <span className={risk.risk_appetite === 'above' ? 'text-[#C53030] font-medium' : 'text-[#718096]'}>
                                                    {APPETITE_LABELS[risk.risk_appetite] || humanize(risk.risk_appetite)}
                                                </span>
                                            ) : <span className="text-gray-400">--</span>}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096]">
                                            {risk.owner?.name || '--'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="px-4 py-3 border-t border-gray-100">
                    <Pagination links={risks.links} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

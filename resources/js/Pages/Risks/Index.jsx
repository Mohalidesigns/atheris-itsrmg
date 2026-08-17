import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, FunnelIcon, MagnifyingGlassIcon, TableCellsIcon, Squares2X2Icon } from '@heroicons/react/24/outline';
import { RatingBadge, StatusBadge, ScoreDisplay } from '@/Components/Risk/RiskBadge';
import Pagination from '@/Components/Pagination';

function InlineHeatMap({ risks = [], size = 5 }) {
    const matrix = Array.from({ length: size }, () => Array.from({ length: size }, () => []));
    risks.forEach((r) => {
        const scale = (v) => Math.min(size, Math.max(1, Math.round((v / 5) * size)));
        const l = scale(r.inherent_likelihood || 3);
        const i = scale(r.inherent_impact || 3);
        matrix[size - i][l - 1].push(r);
    });
    const cellColor = (l, i) => {
        const score = (l / size) * (i / size);
        if (score >= 0.8) return 'bg-[#B3261E]/90';
        if (score >= 0.5) return 'bg-[#E5A100]/85';
        if (score >= 0.25) return 'bg-[#FFCD3C]/70';
        return 'bg-[#2D7D46]/75';
    };
    return (
        <div className="inline-block">
            <div className="grid gap-1" style={{ gridTemplateColumns: `repeat(${size}, 80px)` }}>
                {Array.from({ length: size }).map((_, rowIdx) => {
                    const impact = size - rowIdx;
                    return Array.from({ length: size }).map((_, colIdx) => {
                        const l = colIdx + 1;
                        const items = matrix[rowIdx][colIdx];
                        return (
                            <div key={`${rowIdx}-${colIdx}`} className={`relative rounded-md h-20 ${cellColor(l, impact)} p-1`}>
                                <span className="text-white/90 text-[10px] font-bold absolute top-1 right-1">{items.length}</span>
                                <div className="flex flex-wrap gap-0.5 pt-4">
                                    {items.slice(0, 6).map((r) => (
                                        <Link key={r.id} href={route('risks.show', r.id)} title={`${r.risk_id_code} — ${r.title}`}
                                            className="w-2 h-2 rounded-full bg-white/90 hover:bg-white hover:scale-125 transition-transform" />
                                    ))}
                                </div>
                            </div>
                        );
                    });
                })}
            </div>
        </div>
    );
}

export default function RisksIndex({ risks, categories, users, filters, statuses, ratings }) {
    const [search, setSearch] = useState(filters.search || '');
    const [showFilters, setShowFilters] = useState(false);
    const [view, setView] = useState('table');
    const [matrixSize, setMatrixSize] = useState(5);
    const allRisks = risks.data || [];

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('risks.index'), { ...filters, search }, { preserveState: true });
    };

    const applyFilter = (key, value) => {
        router.get(route('risks.index'), { ...filters, [key]: value || undefined }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Risk Register">
            <Head title="Risk Register" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Risk Management' }, { label: 'Risk Register' }]}
                title="Risk Register"
                subtitle={`${risks.total} risk${risks.total !== 1 ? 's' : ''} registered · CBN-aligned taxonomy (Cyber, Tech, Ops-Tech, TPRM, Data Protection, Fraud, Physical, Regulatory)`}
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
                    <div className="flex items-center justify-between mb-4">
                        <div>
                            <h3 className="text-sm font-semibold text-[#2D3748]">Configurable Risk Heat Map</h3>
                            <p className="text-xs text-[#718096]">Toggle matrix size · hover dots for risk detail · click to drill through</p>
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
                    <InlineHeatMap risks={allRisks} size={matrixSize} />
                    <p className="text-[10px] text-[#718096] mt-3">Likelihood (columns) × Impact (rows)</p>
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
                                {statuses.map(s => (
                                    <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                                ))}
                            </select>
                            <select
                                value={filters.rating || ''}
                                onChange={e => applyFilter('rating', e.target.value)}
                                className="text-sm border-gray-200 rounded-lg focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            >
                                <option value="">All Ratings</option>
                                {ratings.map(r => (
                                    <option key={r} value={r}>{r.replace('_', ' ').toUpperCase()}</option>
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
                        </div>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-t border-gray-100 bg-gray-50/50">
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Risk ID</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Title</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Category</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Inherent</th>
                                <th className="px-4 py-3 text-center font-medium text-[#718096]">Residual</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Status</th>
                                <th className="px-4 py-3 text-left font-medium text-[#718096]">Owner</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50">
                            {risks.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-12 text-center text-[#718096]">
                                        No risks found. Create your first risk to get started.
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

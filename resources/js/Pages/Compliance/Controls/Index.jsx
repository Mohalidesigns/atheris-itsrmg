import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlusIcon, MagnifyingGlassIcon, ChevronUpIcon, ChevronDownIcon } from '@heroicons/react/24/outline';
import Pagination from '@/Components/Pagination';
import Chip from '@/Components/Compliance/Chip';
import { CONTROL_STATUS, EFFECTIVENESS, effectivenessOf, humanize, formatDate, isOverdue } from '@/Utils/compliance';

export default function ControlsIndex({ controls, filters, domains, options, summary, can }) {
    const [search, setSearch] = useState(filters.search || '');
    const apply = (patch) => router.get(route('controls.index'), { ...filters, ...patch, page: undefined }, { preserveState: true, preserveScroll: true, replace: true });
    const toggle = (key) => apply({ [key]: filters[key] ? undefined : 1 });

    const sortBy = (col) => apply({ sort: col, direction: filters.sort === col && filters.direction !== 'desc' ? 'desc' : 'asc' });
    const Th = ({ col, children, className = '' }) => (
        <th className={`px-4 py-3 font-medium ${className}`}>
            <button onClick={() => sortBy(col)} className="inline-flex items-center gap-0.5 hover:text-[#1A365D]">
                {children}
                {filters.sort === col && (filters.direction === 'desc' ? <ChevronDownIcon className="w-3 h-3" /> : <ChevronUpIcon className="w-3 h-3" />)}
            </button>
        </th>
    );

    const chips = [
        ['Key controls', summary.key, 'key'],
        ['Not mapped to a framework', summary.unmapped, 'unmapped'],
        ['Review due ≤ 30 days', summary.review_due, 'review_due'],
    ];

    return (
        <AuthenticatedLayout header="Control Library">
            <Head title="Control Library" />

            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-[#718096] mr-1">{controls.total} of {summary.total} controls</span>
                    {chips.map(([label, n, key]) => (
                        <button key={key} onClick={() => toggle(key)} className={`text-xs px-2.5 py-1 rounded-full border ${filters[key] ? 'bg-[#1A365D] text-white border-[#1A365D]' : 'bg-white border-gray-200 text-[#4A5568]'}`}>
                            {label} · {n}
                        </button>
                    ))}
                </div>
                {can.create && (
                    <Link href={route('controls.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] shrink-0">
                        <PlusIcon className="w-4 h-4" /> Add Control
                    </Link>
                )}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 flex flex-wrap gap-2">
                    <form onSubmit={(e) => { e.preventDefault(); apply({ search: search || undefined }); }} className="relative flex-1 min-w-[220px]">
                        <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search code, title or description…"
                            className="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-[#1A365D]/30" />
                    </form>
                    <select value={filters.domain || ''} onChange={(e) => apply({ domain: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All domains</option>
                        {domains.map((d) => <option key={d} value={d}>{d}</option>)}
                    </select>
                    <select value={filters.status || ''} onChange={(e) => apply({ status: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All statuses</option>
                        {options.statuses.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select>
                    <select value={filters.type || ''} onChange={(e) => apply({ type: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All types</option>
                        {options.types.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}
                    </select>
                    <select value={filters.effectiveness || ''} onChange={(e) => apply({ effectiveness: e.target.value || undefined })} className="text-sm border-gray-200 rounded-lg">
                        <option value="">All effectiveness</option>
                        {options.effectiveness.map((e) => <option key={e} value={e}>{EFFECTIVENESS[e]?.label || humanize(e)}</option>)}
                    </select>
                    {Object.values(filters).some(Boolean) && <button onClick={() => { setSearch(''); router.get(route('controls.index')); }} className="text-xs text-[#1A365D] underline px-2">Clear</button>}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="border-y border-gray-100 bg-gray-50/50 text-left text-[#718096]">
                            <Th col="control_code">Code</Th>
                            <Th col="title">Title</Th>
                            <Th col="domain">Domain</Th>
                            <Th col="status">Status</Th>
                            <Th col="effectiveness">Effectiveness</Th>
                            <Th col="framework_requirements_count" className="text-center">Mappings</Th>
                            <th className="px-4 py-3 font-medium text-center">Risks · Evidence</th>
                            <th className="px-4 py-3 font-medium">Owner</th>
                            <Th col="next_review_date">Next review</Th>
                        </tr></thead>
                        <tbody className="divide-y divide-gray-50">
                            {controls.data.length === 0 ? (
                                <tr><td colSpan={9} className="px-4 py-12 text-center text-[#718096]">No controls match these filters.</td></tr>
                            ) : controls.data.map((c) => {
                                const eff = effectivenessOf(c.effectiveness);
                                const due = isOverdue(c.next_review_date);
                                return (
                                    <tr key={c.id} className="hover:bg-gray-50/50">
                                        <td className="px-4 py-3 whitespace-nowrap"><Link href={route('controls.show', c.id)} className="font-mono-data text-[#1A365D] text-xs font-medium hover:underline">{c.control_code}</Link></td>
                                        <td className="px-4 py-3">
                                            <Link href={route('controls.show', c.id)} className="text-[#2D3748] font-medium hover:text-[#1A365D]">{c.title}</Link>
                                            {c.is_key_control && <span className="ml-1 text-[9px] bg-[#D4AF37]/10 text-[#B7791F] px-1.5 py-0.5 rounded font-semibold">KEY</span>}
                                        </td>
                                        <td className="px-4 py-3 text-[#718096] text-xs">{c.domain || '--'}</td>
                                        <td className="px-4 py-3"><Chip className={CONTROL_STATUS[c.status] || 'bg-gray-100'}>{humanize(c.status)}</Chip></td>
                                        <td className="px-4 py-3"><Chip className={eff.chip}>{eff.label}</Chip></td>
                                        <td className="px-4 py-3 text-center">
                                            {c.framework_requirements_count ? <span className="font-mono-data text-xs text-[#2D3748]">{c.framework_requirements_count}</span> : <span className="text-[10px] text-[#B7791F] font-medium">UNMAPPED</span>}
                                        </td>
                                        <td className="px-4 py-3 text-center font-mono-data text-xs text-[#718096]">{c.risks_count} · {c.evidence_count}</td>
                                        <td className="px-4 py-3 text-[#718096] text-xs">{c.owner?.name || <span className="text-[#B7791F]">Unassigned</span>}</td>
                                        <td className={`px-4 py-3 text-xs whitespace-nowrap ${due ? 'text-red-600 font-semibold' : 'text-[#718096]'}`}>{formatDate(c.next_review_date)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="px-4 py-3 border-t border-gray-100"><Pagination links={controls.links} /></div>
            </div>
        </AuthenticatedLayout>
    );
}

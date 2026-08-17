import { useMemo, useState } from 'react';
import {
    ArrowDownTrayIcon,
    MagnifyingGlassIcon,
    PlusIcon,
} from '@heroicons/react/24/outline';

/**
 * EaIndexToolbar — the "same four affordances on every workspace" required by
 * ATH-EAR-002 §5.5: a filter/search bar, a create action, and an export.
 * Consistency is what makes 8 workspaces learnable where 40 links were not.
 *
 * Filtering is client-side. EA catalogues are bounded (a large Nigerian bank
 * runs a few hundred applications, not a few hundred thousand), the controller
 * already returns the full collection, and doing it here keeps the interaction
 * instant on the 3G connectivity §10 calls out.
 */

export function useEaFilter(rows, { searchKeys = [], filters = {} } = {}) {
    const [query, setQuery] = useState('');
    const [active, setActive] = useState({});

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return (rows || []).filter((row) => {
            if (q) {
                const hit = searchKeys.some((k) => {
                    const v = k.split('.').reduce((o, part) => (o ? o[part] : undefined), row);
                    return v !== null && v !== undefined && String(v).toLowerCase().includes(q);
                });
                if (!hit) return false;
            }
            return Object.entries(active).every(([key, value]) => {
                if (!value) return true;
                const def = filters[key];
                if (def?.predicate) return def.predicate(row, value);
                const v = key.split('.').reduce((o, part) => (o ? o[part] : undefined), row);
                return String(v ?? '') === String(value);
            });
        });
    }, [rows, query, active, searchKeys, filters]);

    return {
        query,
        setQuery,
        active,
        setFilter: (key, value) => setActive((a) => ({ ...a, [key]: value })),
        reset: () => {
            setQuery('');
            setActive({});
        },
        filtered,
        isFiltered: Boolean(query.trim()) || Object.values(active).some(Boolean),
    };
}

/** Build and download a CSV from the currently visible rows. */
export function exportCsv(filename, columns, rows) {
    const escape = (v) => {
        if (v === null || v === undefined) return '';
        const s = typeof v === 'object' ? JSON.stringify(v) : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const header = columns.map((c) => escape(c.label)).join(',');
    const body = rows
        .map((r) =>
            columns
                .map((c) =>
                    escape(
                        typeof c.value === 'function'
                            ? c.value(r)
                            : c.key.split('.').reduce((o, p) => (o ? o[p] : undefined), r),
                    ),
                )
                .join(','),
        )
        .join('\n');

    const blob = new Blob([`${header}\n${body}`], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

export default function EaIndexToolbar({
    search,
    filters = [],
    onCreate,
    createLabel = 'New',
    canCreate = true,
    onExport,
    canExport = true,
    total,
    shown,
    extra,
}) {
    return (
        <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
            {search && (
                <div className="relative min-w-[220px] flex-1">
                    <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#718096]" />
                    <input
                        type="search"
                        value={search.value}
                        onChange={(e) => search.onChange(e.target.value)}
                        placeholder={search.placeholder || 'Search…'}
                        className="w-full rounded-lg border-gray-300 py-1.5 pl-9 text-sm text-[#2D3748] shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                    />
                </div>
            )}

            {filters.map((f) => (
                <select
                    key={f.key}
                    value={f.value || ''}
                    onChange={(e) => f.onChange(e.target.value)}
                    className="rounded-lg border-gray-300 py-1.5 text-sm text-[#2D3748] shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30"
                >
                    <option value="">{f.label}</option>
                    {f.options.map((o) => {
                        const opt = typeof o === 'object' ? o : { value: o, label: String(o) };
                        return (
                            <option key={opt.value} value={opt.value}>
                                {opt.label}
                            </option>
                        );
                    })}
                </select>
            ))}

            {extra}

            <div className="ml-auto flex items-center gap-2">
                {typeof total === 'number' && (
                    <span className="text-xs text-[#718096]">
                        {shown !== undefined && shown !== total ? `${shown} of ${total}` : `${total}`} record
                        {total === 1 ? '' : 's'}
                    </span>
                )}
                {onExport && canExport && (
                    <button
                        type="button"
                        onClick={onExport}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-[#2D3748] hover:bg-gray-50"
                    >
                        <ArrowDownTrayIcon className="h-4 w-4" />
                        Export CSV
                    </button>
                )}
                {onCreate && canCreate && (
                    <button
                        type="button"
                        onClick={onCreate}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#1A365D]"
                    >
                        <PlusIcon className="h-4 w-4" />
                        {createLabel}
                    </button>
                )}
            </div>
        </div>
    );
}

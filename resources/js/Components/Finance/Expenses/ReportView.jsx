import Chip from '@/Components/Material/Chip';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/** Import report body: counts, preview sample and row errors. */
export function ReportBody({ report }) {
    const [open, setOpen] = useState(true);
    const errors = (report.errors || []).slice(0, 50);
    const sample = report.sample || [];
    const cols = sample[0] ? Object.keys(sample[0]) : [];
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-medium text-[#2D3748]">{report.mode === 'preview' ? 'Preview (nothing posted)' : 'Import result'}{report.file_name ? `: ${report.file_name}` : ''}</p>
                <Chip tone="neutral">{report.rows ?? 0} rows</Chip>
                <Chip tone="success">{report.accepted ?? 0} accepted</Chip>
                <Chip tone={report.rejected > 0 ? 'error' : 'muted'}>{report.rejected ?? 0} rejected</Chip>
                {report.duplicates > 0 && <Chip tone="warning">{report.duplicates} duplicates</Chip>}
                {(report.matched !== undefined && report.type === 'actuals') && <><Chip tone="info">{report.matched} auto-matched</Chip><Chip tone={report.unmatched > 0 ? 'warning' : 'muted'}>{report.unmatched} to reconcile</Chip></>}
                {errors.length > 0 && <button type="button" onClick={() => setOpen(!open)} className="ml-auto text-xs text-[#0A1F44] underline">{open ? 'Hide errors' : 'Show errors'}</button>}
            </div>
            {sample.length > 0 && (
                <div className="mt-3 overflow-x-auto">
                    <p className="mb-1 text-xs text-[#718096]">Sample of accepted rows</p>
                    <table className="min-w-full text-left text-xs">
                        <thead className="text-[#718096]"><tr>{cols.map((c) => <th key={c} className="py-1 pr-3 font-medium">{c}</th>)}</tr></thead>
                        <tbody className="divide-y divide-gray-50">{sample.map((r, i) => <tr key={i}>{cols.map((c) => <td key={c} className="py-1 pr-3">{typeof r[c] === 'object' ? JSON.stringify(r[c]) : String(r[c] ?? '')}</td>)}</tr>)}</tbody>
                    </table>
                </div>
            )}
            {errors.length > 0 && open && (
                <div className="mt-3 overflow-x-auto">
                    <table className="min-w-full text-left text-xs">
                        <thead className="text-[#718096]"><tr><th className="w-16 py-1 pr-3 font-medium">Row</th><th className="w-32 py-1 pr-3 font-medium">Field</th><th className="py-1 font-medium">Message</th></tr></thead>
                        <tbody className="divide-y divide-gray-50">{errors.map((e, i) => <tr key={i}><td className="py-1 pr-3 font-mono">{e.row}</td><td className="py-1 pr-3">{e.field}</td><td className="py-1 text-[#B3261E]">{e.message}</td></tr>)}</tbody>
                    </table>
                    {(report.errors || []).length > 50 && <p className="mt-1 text-[11px] text-[#718096]">Showing the first 50 errors.</p>}
                </div>
            )}
        </div>
    );
}

/** Shows flash.import_report when it matches `type`. */
export default function FlashReport({ type }) {
    const report = usePage().props.flash?.import_report;
    if (!report || (type && report.type !== type)) return null;
    return <ReportBody report={report} />;
}

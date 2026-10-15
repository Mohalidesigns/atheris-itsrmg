import Chip from '@/Components/Material/Chip';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/** Shows flash.import_report when its type matches the current tab. */
export default function ImportReport({ type }) {
    const report = usePage().props.flash?.import_report;
    const [open, setOpen] = useState(true);
    if (!report || report.type !== type) return null;
    const errors = (report.errors || []).slice(0, 50);
    return (
        <div className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-medium text-[#2D3748]">Import result{report.file_name ? `: ${report.file_name}` : ''}</p>
                <Chip tone="neutral">{report.row_count} rows</Chip>
                <Chip tone="success">{report.accepted_count} accepted</Chip>
                <Chip tone={report.rejected_count > 0 ? 'error' : 'muted'}>{report.rejected_count} rejected</Chip>
                {errors.length > 0 && <button type="button" onClick={() => setOpen(!open)} className="ml-auto text-xs text-[#0A1F44] underline">{open ? 'Hide errors' : 'Show errors'}</button>}
            </div>
            {errors.length > 0 && open && (
                <div className="mt-3 overflow-x-auto">
                    <table className="min-w-full text-left text-xs">
                        <thead className="text-[#718096]"><tr><th className="w-16 py-1 pr-3 font-medium">Row</th><th className="py-1 font-medium">Message</th></tr></thead>
                        <tbody className="divide-y divide-gray-50">
                            {errors.map((e, i) => <tr key={i}><td className="py-1 pr-3 font-mono">{e.row}</td><td className="py-1 text-[#B3261E]">{e.message}</td></tr>)}
                        </tbody>
                    </table>
                    {(report.errors || []).length > 50 && <p className="mt-1 text-[11px] text-[#718096]">Showing the first 50 of {report.errors.length} errors.</p>}
                </div>
            )}
        </div>
    );
}

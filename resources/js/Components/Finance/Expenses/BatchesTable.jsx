import { Empty, Table } from '@/Components/Finance/Settings/ui';
import StatusChip from './StatusChip';
import { humanize, localDate } from '@/Utils/finance';
import { Link } from '@inertiajs/react';

export default function BatchesTable({ batches, showType = false }) {
    return (
        <Table head={['Batch', ...(showType ? ['Type'] : []), 'File', 'Status', 'Rows', 'Accepted', 'Rejected', 'Duplicates', 'Matched', 'Unmatched', 'Imported by', 'Date']} empty={batches.length === 0 && <Empty>No imports yet.</Empty>}>
            {batches.map((b) => (
                <tr key={b.id}>
                    <td className="px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] underline" href={route('finance.imports.show', b.id)}>#{b.id}</Link></td>
                    {showType && <td className="px-4 py-2 text-xs">{humanize(b.type)}</td>}
                    <td className="max-w-[14rem] truncate px-4 py-2 text-xs text-[#2D3748]" title={b.file_name}>{b.file_name}</td>
                    <td className="px-4 py-2"><StatusChip kind="batch" status={b.status} /></td>
                    {['row_count', 'accepted_count', 'rejected_count', 'duplicate_count', 'matched_count', 'unmatched_count'].map((k) => <td key={k} className="px-4 py-2 text-right text-xs tabular-nums">{b[k]}</td>)}
                    <td className="whitespace-nowrap px-4 py-2 text-xs">{b.imported_by?.name ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(b.created_at)}</td>
                </tr>
            ))}
        </Table>
    );
}

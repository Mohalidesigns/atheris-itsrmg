import { formatDate } from '@/Utils/risk';
import { Link } from '@inertiajs/react';
import { Card, Note } from './shared';

const Ref = ({ r }) => (
    <li className="flex items-center justify-between gap-2 text-xs">
        <Link href={route('issues.show', r.id)} className="min-w-0 truncate text-[#0A1F44] underline"><span className="font-mono">{r.issue_code}</span> · {r.title}</Link>
        <span className="shrink-0 text-[10px] text-[#718096]">{formatDate(r.created_at)}</span>
    </li>
);

export default function RecurrenceCard({ recurrence }) {
    const of = recurrence?.of;
    const later = recurrence?.later || [];
    if (!of && later.length === 0) return null;
    return (
        <Card title="Recurrence" icon="replay">
            {of && (
                <div className="mb-2">
                    <p className="mb-1 text-[11px] text-[#718096]">Recurrence of</p>
                    <ul><Ref r={of} /></ul>
                </div>
            )}
            {later.length > 0 && (
                <div>
                    <Note tone="error" icon="warning">This problem recurred {later.length} time{later.length > 1 ? 's' : ''}. The fix may not be effective.</Note>
                    <ul className="mt-2 space-y-1.5">{later.map((r) => <Ref key={r.id} r={r} />)}</ul>
                </div>
            )}
        </Card>
    );
}

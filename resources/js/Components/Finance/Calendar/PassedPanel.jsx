import Chip from '@/Components/Material/Chip';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { localDate } from '@/Utils/finance';
import { Link } from '@inertiajs/react';

/** Contracts whose notice or end date passed with no decision, as ordered by the server (most overdue first). */
export default function PassedPanel({ passed }) {
    return (
        <Card title={<span className="flex items-center gap-2">Passed <Chip size="xs" tone={passed.length ? 'error' : 'neutral'}>{passed.length}</Chip></span>}>
            {passed.length === 0 ? <Empty>No passed deadlines. Every contract past its notice or end date has a decision.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {passed.map((p) => (
                        <li key={`${p.id}-${p.which}`}>
                            <Link href={p.href} className="block px-4 py-2 hover:bg-gray-50">
                                <div className="flex items-start justify-between gap-2">
                                    <span className="min-w-0 text-sm text-[#2D3748]"><span className="font-mono text-xs text-[#718096]">{p.contract_code}</span> {p.title}</span>
                                    <span className="shrink-0 text-xs font-semibold text-[#B3261E]">{p.days_overdue} days overdue</span>
                                </div>
                                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-[#718096]">
                                    <Chip size="xs" tone="error">{p.which === 'notice' ? 'Notice' : 'End'}</Chip>
                                    {(p.approved_unexecuted || p.status === 'approved_unexecuted') && <Chip size="xs" tone="error" icon="running_with_errors">Approved, not executed</Chip>}
                                    {p.auto_renew && <Chip size="xs" tone="info" icon="autorenew">Auto-renews</Chip>}
                                    <span>{p.vendor ?? ''}</span><span>{localDate(p.date)}</span>{p.owner && <span>· {p.owner}</span>}
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

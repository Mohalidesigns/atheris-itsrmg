import Chip from '@/Components/Material/Chip';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { localDate, stageLabel } from '@/Utils/finance';
import { Link } from '@inertiajs/react';

/** Investments past their planned end and value reviews that are overdue (separate from the contract "Passed" list). */
export default function PassedInvestmentsPanel({ passed = [] }) {
    return (
        <Card title={<span className="flex items-center gap-2">Investments past their date <Chip size="xs" tone={passed.length ? 'error' : 'neutral'}>{passed.length}</Chip></span>}>
            {passed.length === 0 ? <Empty>No late investments or overdue value reviews.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {passed.map((p) => (
                        <li key={`${p.id}-${p.which}`}>
                            <Link href={p.href} className="block px-4 py-2 hover:bg-gray-50">
                                <div className="flex items-start justify-between gap-2">
                                    <span className="min-w-0 text-sm text-[#2D3748]"><span className="font-mono text-xs text-[#718096]">{p.investment_code}</span> {p.title}</span>
                                    <span className="shrink-0 text-xs font-semibold text-[#B3261E]">{p.days_overdue} days overdue</span>
                                </div>
                                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-[#718096]">
                                    <Chip size="xs" tone="error">{p.which === 'value_review' ? 'Value review' : 'Planned end'}</Chip>
                                    <span>{stageLabel(p.stage)}</span><span>{localDate(p.date)}</span>{p.manager && <span>· {p.manager}</span>}
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

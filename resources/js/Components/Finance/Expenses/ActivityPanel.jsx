import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { formatDate, humanize } from '@/Utils/risk';

export default function ActivityPanel({ activity }) {
    return (
        <Card title="Activity">
            {activity.length === 0 ? <Empty>No activity yet.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {activity.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2 text-sm">
                            <span className="text-[#2D3748]">{a.description || humanize(a.event)}</span>
                            <span className="text-xs text-[#718096]">{a.causer?.name ?? 'System'} · {formatDate(a.at, true)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

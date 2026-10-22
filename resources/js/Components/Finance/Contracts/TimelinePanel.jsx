import Icon from '@/Components/Material/Icon';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { formatDate } from '@/Utils/risk';

const ICONS = {
    created: 'add_circle', notified: 'notifications', escalated: 'priority_high', window_opened: 'event_upcoming', notice_deadline_passed: 'alarm', expired: 'event_busy',
    decision_recorded: 'gavel', renewed: 'autorenew', terminated: 'cancel', document_added: 'description', status_changed: 'sync_alt', renewal_opened: 'play_circle',
    renewal_submitted: 'send', renewal_rejected: 'block', renewal_withdrawn: 'undo', team_changed: 'group', link_changed: 'link',
    termination_requested: 'cancel_schedule_send', termination_approved: 'task_alt', termination_rejected: 'block', termination_withdrawn: 'undo', approved_unexecuted_alert: 'running_with_errors',
};
const TINT = { escalated: 'text-[#B3261E]', notice_deadline_passed: 'text-[#B3261E]', expired: 'text-[#B3261E]', window_opened: 'text-[#8A6400]', renewed: 'text-[#2D7D46]', termination_approved: 'text-[#2D7D46]', termination_rejected: 'text-[#B3261E]', approved_unexecuted_alert: 'text-[#B3261E]', decision_recorded: 'text-[#1D4ED8]' };

function detail(e) {
    const p = e.payload || {};
    if (e.type === 'notified') return `${p.series === 'notice' ? 'Notice deadline' : 'End date'} reminder, T-${p.offset}${Array.isArray(p.recipients) ? `, ${p.recipients.length} recipient${p.recipients.length === 1 ? '' : 's'}` : ''}`;
    if (e.type === 'status_changed' && p.from) return `${p.from} to ${p.to}`.replace(/_/g, ' ');
    if (typeof p.reason === 'string') return p.reason;
    return null;
}

export default function TimelinePanel({ events }) {
    return (
        <Card title="Timeline">
            {events.length === 0 ? <Empty>No events yet.</Empty> : (
                <ol className="px-5 py-3">
                    {events.map((e, i) => (
                        <li key={e.id} className="relative flex gap-3 pb-4 last:pb-0">
                            {i < events.length - 1 && <span className="absolute left-[11px] top-6 h-full w-px bg-gray-200" aria-hidden />}
                            <span className={`relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 ${TINT[e.type] ?? 'text-[#43474E]'}`}><Icon name={ICONS[e.type] ?? 'circle'} size={16} /></span>
                            <div className="min-w-0 text-sm">
                                <p className="text-[#2D3748]">{e.label}</p>
                                {detail(e) && <p className="text-xs text-[#4A5568]">{detail(e)}</p>}
                                <p className="text-xs text-[#718096]">{e.user?.name ?? 'System'} · {formatDate(e.occurred_at, true)}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </Card>
    );
}

import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { fmtDateTime } from '@/Components/Monitoring/format';

const stepTone = { pending: ['warning', 'hourglass_top'], approved: ['success', 'check_circle'], rejected: ['error', 'cancel'] };

/**
 * Where a record's approval stands under its Workflow Studio configuration (ATH-VAL-016): the
 * configured stages before it starts, then each step with who decided it. It re-reads itself after
 * every successful action on the page.
 */
export default function ApprovalTrail({ process, id, version }) {
    const [trail, setTrail] = useState(null);
    useEffect(() => {
        const load = () => window.axios.get(route('workflows.trail', { process, id })).then((r) => setTrail(r.data)).catch(() => setTrail(null));
        load();
        // Re-read after any approve / return on the page.
        return router.on('success', load);
    }, [process, id, version]);
    if (!trail) return null;
    const req = trail.request;
    return (
        <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-1 text-sm font-semibold text-[#2D3748]"><Icon name="account_tree" size={18} />Approval workflow</h3>
                {req && <Chip tone={{ pending: 'warning', approved: 'success', rejected: 'error', withdrawn: 'muted' }[req.status]} size="xs">{req.status}</Chip>}
            </div>
            {!trail.configured && !req && <p className="text-xs text-[#718096]">{trail.default}</p>}
            {!req && trail.configured && (
                <ol className="space-y-1 text-xs text-[#4A5568]">{trail.stages.map((s, i) => <li key={i}>{i + 1}. <b>{s.name}</b>: {s.who}{s.condition ? ` (${s.condition})` : ''}</li>)}</ol>
            )}
            {req && (
                <ol className="space-y-2">
                    {req.steps.map((s, i) => { const [tone, icon] = stepTone[s.status] || ['muted', null]; return (
                        <li key={i} className="text-xs">
                            <div className="flex items-center justify-between gap-2"><span className="font-semibold text-[#0A1F44]">{i + 1}. {s.name}</span><Chip tone={tone} icon={icon} size="xs">{s.status}</Chip></div>
                            <p className="text-[#718096]">{s.who}{s.min_approvals > 1 ? ` — ${s.min_approvals} approvals` : ''}{s.status === 'pending' && s.due_at ? ` · due ${fmtDateTime(s.due_at)}` : ''}</p>
                            {s.decisions.map((d, j) => <p key={j} className="text-[#4A5568]">{d.decision === 'approved' ? '✓' : '✕'} {d.user}, {fmtDateTime(d.at)}{d.comment ? `: ${d.comment}` : ''}</p>)}
                            {s.can_decide && <p className="mt-0.5 font-medium text-[#8A6400]">Awaiting your decision — use this page's approve action.</p>}
                        </li>
                    ); })}
                </ol>
            )}
            {req?.initiator && <p className="mt-2 text-[11px] text-[#A0AEC0]">Started by {req.initiator}</p>}
        </div>
    );
}

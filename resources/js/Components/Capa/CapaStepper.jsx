import Icon from '@/Components/Material/Icon';
import InputError from '@/Components/InputError';
import { formatDate } from '@/Utils/risk';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Note, primaryBtn } from './shared';

const STAGE_ICON = {
    identification: 'search', containment: 'shield', rca: 'account_tree', action_planning: 'checklist',
    implementation: 'build', verification: 'fact_check', closed: 'task_alt',
};

/** Six-stage stepper (+ Closed) with the gate blockers and the Advance control. */
export default function CapaStepper({ issue, capa, canWork, errors = {} }) {
    const [note, setNote] = useState('');
    const blocked = issue.status === 'blocked';
    const gate = capa.gate || { can_advance: false, blockers: [] };
    const next = (capa.stages || []).find((s) => s.key === gate.next_stage);
    const isClosed = capa.stage === 'closed';
    const reasons = [...(gate.blockers || []), ...(blocked ? ['The CAPA is blocked; unblock it before advancing.'] : [])];
    const disabled = !canWork || !gate.can_advance || blocked;

    const advance = () => router.post(route('issues.capa.stage', issue.id), { note }, { preserveScroll: true, onSuccess: () => setNote('') });

    return (
        <Card>
            <ol className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-2" aria-label="CAPA stages">
                {(capa.stages || []).map((s, idx) => {
                    const done = s.status === 'done';
                    const current = s.status === 'current';
                    return (
                        <li key={s.key} className="flex min-w-[116px] flex-1 items-start gap-1" aria-current={current ? 'step' : undefined}>
                            <div className="flex min-w-0 flex-1 flex-col items-center text-center">
                                <span className={`flex h-9 w-9 items-center justify-center rounded-xl ${done ? 'bg-[#2D7D46] text-white' : current ? 'bg-[#0A1F44] text-white ring-4 ring-[#C9A86A]/40' : 'bg-gray-100 text-[#A0AEC0]'}`}>
                                    <Icon name={done ? 'check' : STAGE_ICON[s.key] || 'circle'} size={20} fill={current} />
                                </span>
                                <span className={`mt-1.5 text-[11px] leading-tight ${current ? 'font-semibold text-[#0A1F44]' : done ? 'text-[#2D3748]' : 'text-[#718096]'}`}>{s.label}</span>
                                {s.completed_at && <span className="text-[10px] text-[#718096]">{formatDate(s.completed_at)}</span>}
                            </div>
                            {idx < capa.stages.length - 1 && <span className={`mt-4 h-0.5 w-3 shrink-0 rounded ${done ? 'bg-[#2D7D46]' : 'bg-gray-200'}`} />}
                        </li>
                    );
                })}
            </ol>

            {!isClosed && (
                <div className="mt-2 space-y-2 border-t border-gray-100 pt-3">
                    {errors.capa && <Note tone="error" icon="error">{errors.capa}</Note>}
                    {reasons.length > 0 && (
                        <Note tone="warning" icon="lock">
                            <p className="font-medium">{next ? `Before moving to "${next.label}":` : 'Cannot advance yet:'}</p>
                            <ul className="mt-1 list-disc space-y-0.5 pl-4">{reasons.map((r) => <li key={r}>{r}</li>)}</ul>
                        </Note>
                    )}
                    {canWork && capa.stage !== 'verification' && (
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Optional note for the stage log"
                                className="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                            <button type="button" onClick={advance} disabled={disabled} className={primaryBtn}
                                title={disabled && reasons.length ? reasons.join(' ') : undefined}>
                                {next ? `Advance to ${next.label}` : 'Advance'}<Icon name="arrow_forward" size={16} />
                            </button>
                        </div>
                    )}
                    {capa.stage === 'verification' && <p className="text-xs text-[#718096]">This stage ends with the independent verification below, not with Advance.</p>}
                    <InputError message={errors.note} />
                </div>
            )}
        </Card>
    );
}

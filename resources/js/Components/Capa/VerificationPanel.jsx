import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import InputError from '@/Components/InputError';
import { formatDate } from '@/Utils/risk';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Note, countdown, dangerBtn, inputCls, labelCls, primaryBtn } from './shared';

export default function VerificationPanel({ issue, capa, errors = {} }) {
    const criteria = capa.verification_criteria || [];
    const [checked, setChecked] = useState(() => criteria.reduce((a, c) => ({ ...a, [c.key]: c.checked }), {}));
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const stage = capa.stage;
    const inVerification = stage === 'verification';
    const closed = stage === 'closed';
    if (!inVerification && !closed && !capa.implemented_at) {
        return (
            <Card title="Independent verification" icon="fact_check">
                <p className="text-xs text-[#718096]">Opens after implementation. A {capa.settling_days ?? 30}-day settling period then runs before an independent auditor verifies effectiveness.</p>
            </Card>
        );
    }

    const left = countdown(capa.verification_due_at);
    const allChecked = criteria.length > 0 && criteria.every((c) => checked[c.key]);
    const submit = (outcome) => {
        setBusy(true);
        router.post(route('issues.capa.verify', issue.id), { outcome, criteria: checked, note }, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => setNote('') });
    };

    return (
        <Card title="Independent verification" icon="fact_check"
            aside={closed ? <Chip tone="success" icon="verified">Verified</Chip> : capa.verification_open ? <Chip tone="success" icon="lock_open">Open for verification</Chip> : <Chip tone="warning" icon="hourglass_top">Settling</Chip>}>
            {capa.verification_due_at && (
                <div className={`mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg p-3 text-sm ${left?.past || closed ? 'bg-[#2D7D46]/10' : 'bg-[#E5A100]/10'}`}>
                    <span className="flex items-center gap-1.5 font-medium text-[#2D3748]"><Icon name="timer" size={18} />{closed || left?.past ? 'Settling period ended' : `Settling: ${left?.label}`}</span>
                    <span className="text-xs text-[#4A5568]">Implemented {formatDate(capa.implemented_at)} · verifiable from {formatDate(capa.verification_due_at, true)} ({capa.settling_days} days)</span>
                </div>
            )}

            <h4 className="text-xs font-semibold uppercase text-[#718096]">Effectiveness criteria</h4>
            <ul className="mt-2 space-y-1.5">
                {criteria.map((c) => (
                    <li key={c.key}>
                        <label className="flex cursor-pointer items-start gap-2 text-sm text-[#2D3748]">
                            <input type="checkbox" checked={!!checked[c.key]} disabled={!inVerification || !capa.can_verify} onChange={(e) => setChecked({ ...checked, [c.key]: e.target.checked })}
                                className="mt-0.5 rounded border-gray-300 text-[#0A1F44] focus:ring-[#C9A86A]" />
                            {c.label}
                        </label>
                    </li>
                ))}
            </ul>

            {inVerification && capa.can_verify && (
                <div className="mt-3 space-y-2 border-t border-gray-100 pt-3">
                    <div>
                        <label className={labelCls}>Verification note (required to fail)</label>
                        <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} className={inputCls} placeholder="Evidence reviewed, or what failed" />
                        <InputError message={errors.note} className="mt-1" />
                    </div>
                    <InputError message={errors.capa || errors.criteria} />
                    {!capa.verification_open && <p className="text-[11px] text-[#8A6400]">Pass is available after the settling period ends. A failure can be recorded at any time.</p>}
                    {capa.verification_open && !allChecked && <p className="text-[11px] text-[#8A6400]">Tick every criterion to pass.</p>}
                    <div className="flex flex-wrap justify-end gap-2">
                        <button type="button" disabled={busy || !note.trim()} onClick={() => submit('fail')} className={dangerBtn} title={note.trim() ? undefined : 'Add a note explaining the failure'}>
                            <Icon name="close" size={16} />Fail, return to implementation
                        </button>
                        <button type="button" disabled={busy || !capa.verification_open || !allChecked} onClick={() => submit('pass')} className={primaryBtn}>
                            <Icon name="verified" size={16} />Pass and close CAPA
                        </button>
                    </div>
                </div>
            )}

            {inVerification && !capa.can_verify && (
                <div className="mt-3 space-y-2 border-t border-gray-100 pt-3">
                    <Note tone="info" icon="balance">
                        Verification must be done by an Internal IT Auditor who is independent of the work: not the issue owner, not the implementer and not the owner of any corrective action.
                    </Note>
                    {(capa.verifier_blockers || []).length > 0 && (
                        <Note tone="warning" icon="block">
                            <p className="font-medium">You cannot verify this CAPA:</p>
                            <ul className="mt-1 list-disc space-y-0.5 pl-4">{capa.verifier_blockers.map((b) => <li key={b}>{b}</li>)}</ul>
                        </Note>
                    )}
                </div>
            )}
            {closed && issue.verified_at && <p className="mt-3 text-xs text-[#718096]">Verified {formatDate(issue.verified_at, true)}{issue.verifier ? ` by ${issue.verifier.name}` : ''}.</p>}
        </Card>
    );
}

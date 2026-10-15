import Chip from '@/Components/Material/Chip';
import InputError from '@/Components/InputError';
import { formatDate } from '@/Utils/risk';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Note, RACI_TONE, RACI_WORD, primaryBtn } from './shared';

/** Who is A/R/C/I for the current stage, recorded sign-offs, and the sign-off action. */
export default function SignoffCard({ issue, capa, config, errors = {} }) {
    const [note, setNote] = useState('');
    const stage = capa.stage;
    if (!stage || stage === 'closed') return null;
    const row = (capa.raci || []).find((r) => r.stage === stage);
    const roles = config.raciRoles || [];
    const existing = capa.signoffs?.[stage];
    const accountable = roles.find((r) => r.key === capa.accountable);
    const sign = () => router.post(route('issues.capa.signoff', issue.id), { note }, { preserveScroll: true, onSuccess: () => setNote('') });

    return (
        <Card title="Stage sign-off" icon="draw">
            <p className="text-xs text-[#718096]">RACI for <strong className="text-[#2D3748]">{capa.stage_label}</strong></p>
            <ul className="mt-2 space-y-1.5">
                {roles.map((r) => {
                    const letter = row?.assignments?.[r.key];
                    return (
                        <li key={r.key} className="flex items-center justify-between gap-2 text-xs">
                            <span className={`min-w-0 truncate ${(capa.my_raci_roles || []).includes(r.key) ? 'font-semibold text-[#0A1F44]' : 'text-[#2D3748]'}`}>
                                {r.label}{(capa.my_raci_roles || []).includes(r.key) && ' (you)'}
                            </span>
                            {letter && <span title={RACI_WORD[letter]} className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-[11px] font-bold ${RACI_TONE[letter]}`}>{letter}</span>}
                        </li>
                    );
                })}
            </ul>

            <div className="mt-3 space-y-2 border-t border-gray-100 pt-3">
                {!accountable && <p className="text-[11px] text-[#718096]">No role is Accountable for this stage; it needs no sign-off.</p>}
                {existing && (
                    <Note tone="success" icon="verified">
                        Signed by <strong>{existing.name}</strong> ({roles.find((r) => r.key === existing.raci_role)?.label || existing.raci_role}) on {formatDate(existing.at, true)}.
                        {existing.note && <span className="mt-0.5 block">{existing.note}</span>}
                    </Note>
                )}
                {accountable && !existing && capa.signoff_required && <Note tone="warning" icon="draw">Needs sign-off by {accountable.label} before this stage can close.</Note>}
                {accountable && !existing && !capa.signoff_required && <p className="text-[11px] text-[#718096]">{accountable.label} is Accountable; sign-off is optional here.</p>}
                {capa.can_sign_off && !existing && (
                    <>
                        <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Sign-off note (optional)" className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm" />
                        <button type="button" onClick={sign} className={`${primaryBtn} w-full justify-center`}>Sign off {capa.stage_label}</button>
                    </>
                )}
                {accountable && !capa.can_sign_off && !existing && <p className="text-[11px] text-[#718096]">You do not hold the {accountable.label} role.</p>}
                <InputError message={errors.capa} />
                {Object.keys(capa.signoffs || {}).length > 0 && (
                    <div className="flex flex-wrap gap-1.5 pt-1">
                        {Object.entries(capa.signoffs).filter(([k]) => k !== stage).map(([k, s]) => (
                            <Chip key={k} tone="success" size="xs" icon="check" title={`${s.name}, ${formatDate(s.at)}`}>{(config.stages || []).find((x) => x.key === k)?.label || k}</Chip>
                        ))}
                    </div>
                )}
            </div>
        </Card>
    );
}

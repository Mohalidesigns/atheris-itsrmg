import InputError from '@/Components/InputError';
import Icon from '@/Components/Material/Icon';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, stageLabel } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Gate checklist for the next stage. Auto criteria are evaluated by the server (read-only ticks);
 * manual criteria must be ticked here. Approval gates go to the Workflow Studio engine instead of passing at once.
 * Posts to `routeName` (default: advance-gate; the business-case Submit button passes its own route).
 */
export default function AdvanceGateModal({ show, onClose, investment, nextGate, routeName = 'finance.investments.advance-gate', routeParams, errors = {} }) {
    const [ticked, setTicked] = useState([]);
    const [note, setNote] = useState('');
    const [endsOn, setEndsOn] = useState('');
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (show) { setTicked([]); setNote(''); setEndsOn(''); } }, [show]);
    if (!nextGate) return null;
    const criteria = nextGate.criteria ?? [];
    const manual = criteria.filter((c) => !c.auto);
    const delivered = nextGate.to_stage === 'delivered';
    const blocked = criteria.some((c) => c.auto && !c.met) || manual.some((c) => !ticked.includes(c.key)) || (delivered && !endsOn);
    const toggle = (k) => setTicked(ticked.includes(k) ? ticked.filter((x) => x !== k) : [...ticked, k]);
    const go = () => {
        setBusy(true);
        router.post(route(routeName, routeParams ?? investment.id), {
            to_stage: nextGate.to_stage, ticked, note: note || null, payload: delivered ? { actual_ends_on: endsOn } : {},
        }, { preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) });
    };
    return (
        <FormModal show={show} onClose={onClose} onSubmit={go} processing={busy || blocked} maxWidth="2xl"
            title={`Move to ${stageLabel(nextGate.to_stage)}`} submitLabel={nextGate.requires_approval ? 'Request approval' : 'Advance'}>
            <p className="text-sm text-[#4A5568]">{nextGate.requires_approval ? 'This gate needs approval. The stage changes only after the final approver decides.' : 'Every criterion must be met before the investment moves on.'}</p>
            <ul className="divide-y divide-gray-50 rounded-lg border border-gray-100">
                {criteria.map((c) => (
                    <li key={c.key} className="flex items-start gap-2 px-3 py-2 text-sm">
                        {c.auto ? (
                            <Icon name={c.met ? 'check_circle' : 'cancel'} size={20} className={c.met ? 'text-[#2D7D46]' : 'text-[#B3261E]'} />
                        ) : (
                            <input id={`gc-${c.key}`} type="checkbox" checked={ticked.includes(c.key)} onChange={() => toggle(c.key)} className="mt-0.5 h-4 w-4 rounded border-gray-300" />
                        )}
                        <label htmlFor={c.auto ? undefined : `gc-${c.key}`} className="min-w-0 flex-1 text-[#2D3748]">{c.label}
                            {c.auto && !c.met && c.detail && <span className="block text-xs text-[#B3261E]">{c.detail}</span>}
                            {c.auto && c.met && c.detail && <span className="block text-xs text-[#718096]">{c.detail}</span>}
                            {!c.auto && <span className="block text-xs text-[#718096]">Confirm manually</span>}
                        </label>
                    </li>
                ))}
            </ul>
            {delivered && <div><label className={LABEL} htmlFor="gc-end">Actual end date</label><input id="gc-end" type="date" value={endsOn} onChange={(e) => setEndsOn(e.target.value)} className={FIELD} /><InputError message={errors['payload.actual_ends_on']} /></div>}
            <div><label className={LABEL} htmlFor="gc-note">Note (optional)</label><textarea id="gc-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} className={FIELD} /><InputError message={errors.note} /></div>
            {(errors.gate || errors.ticked || errors.to_stage) && <p role="alert" className="text-sm text-[#B3261E]">{errors.gate || errors.ticked || errors.to_stage}</p>}
        </FormModal>
    );
}

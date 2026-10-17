import InputError from '@/Components/InputError';
import { FormModal } from '@/Components/Finance/Settings/ui';
import { Check } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, formatMoney } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Match one unreconciled actual to a budget line. Suggestions come from finance.actuals.candidates; any line can be picked. */
export default function MatchActualModal({ actual, budgetLines, baseCurrency, money, errors = {}, onClose }) {
    const [cands, setCands] = useState(null);
    const [line, setLine] = useState('');
    const [remember, setRemember] = useState(false);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        if (!actual) return;
        setCands(null); setLine(''); setRemember(false);
        window.axios.get(route('finance.actuals.candidates', actual.id)).then((r) => setCands(r.data.candidates ?? [])).catch(() => setCands([]));
    }, [actual?.id]);
    if (!actual) return null;
    const go = () => {
        setBusy(true);
        router.post(route('finance.actuals.match', actual.id), { budget_line_id: line, remember }, { preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) });
    };
    return (
        <FormModal show onClose={onClose} onSubmit={go} processing={busy || !line} title="Match actual to a budget line" submitLabel="Match">
            <p className="text-sm text-[#4A5568]">{actual.description} · {formatMoney(actual.base_amount, baseCurrency)}</p>
            <div>
                <p className={LABEL}>Suggestions</p>
                {cands === null ? <p className="text-xs text-[#718096]">Looking for candidates…</p> : cands.length === 0 ? <p className="text-xs text-[#718096]">No suggestion scored high enough. Pick a line below.</p> : (
                    <ul className="mt-1 space-y-1">
                        {cands.map((c) => (
                            <li key={c.line_id}><label className="flex items-start gap-2 rounded-lg border border-gray-100 px-3 py-2 text-sm"><input type="radio" name="cand" checked={String(line) === String(c.line_id)} onChange={() => setLine(String(c.line_id))} className="mt-1" />
                                <span className="min-w-0"><span className="block text-[#2D3748]">{c.budget_code} #{c.line_no} {c.label}</span><span className="block text-xs text-[#718096]">{c.why} · score {c.score}{money && c.current_base_amount ? ` · ${formatMoney(c.current_base_amount, baseCurrency, { decimals: 0 })}` : ''}</span></span></label></li>
                        ))}
                    </ul>
                )}
            </div>
            <div><label className={LABEL} htmlFor="ma-line">Or any budget line</label>
                <select id="ma-line" value={line} onChange={(e) => setLine(e.target.value)} className={FIELD}><option value="">Select…</option>{budgetLines.map((l) => <option key={l.id} value={l.id}>{l.budget_code} #{l.line_no} {l.label}</option>)}</select>
                <InputError message={errors.budget_line_id} /></div>
            <Check label="Remember this: create a match rule for similar actuals" checked={remember} onChange={setRemember} />
        </FormModal>
    );
}

import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import InputError from '@/Components/InputError';
import { useForm } from '@inertiajs/react';
import { Card, Note, inputCls, labelCls, primaryBtn } from './shared';

const CATEGORY_LABEL = { people: 'People', process: 'Process', technology: 'Technology', governance: 'Governance' };
const CATEGORY_ICON = { people: 'group', process: 'schema', technology: 'memory', governance: 'account_balance' };

export default function RcaPanel({ issue, capa, rootCause, config, canWork }) {
    const categories = config.fishboneCategories || Object.keys(CATEGORY_LABEL);
    const minWhys = config.minWhys || 3;
    const editable = canWork && ['identification', 'containment', 'rca'].includes(capa.stage);
    const blankBone = () => categories.reduce((a, c) => ({ ...a, [c]: [] }), {});
    const form = useForm({
        method: rootCause?.method || 'five_whys',
        five_whys: rootCause?.five_whys?.length ? rootCause.five_whys : Array(minWhys).fill(''),
        fishbone: { ...blankBone(), ...(rootCause?.fishbone || {}) },
        root_cause_summary: rootCause?.root_cause_summary || '',
    });
    const d = form.data;
    const useWhys = d.method !== 'fishbone';
    const useBone = d.method !== 'five_whys';
    const filledWhys = d.five_whys.filter((w) => w.trim()).length;

    const setWhy = (idx, v) => form.setData('five_whys', d.five_whys.map((w, i) => (i === idx ? v : w)));
    const setCause = (cat, idx, v) => form.setData('fishbone', { ...d.fishbone, [cat]: d.fishbone[cat].map((c, i) => (i === idx ? v : c)) });
    const addCause = (cat) => form.setData('fishbone', { ...d.fishbone, [cat]: [...d.fishbone[cat], ''] });
    const removeCause = (cat, idx) => form.setData('fishbone', { ...d.fishbone, [cat]: d.fishbone[cat].filter((_, i) => i !== idx) });

    const save = (e) => { e.preventDefault(); form.put(route('issues.capa.root-cause', issue.id), { preserveScroll: true }); };

    return (
        <Card title="Root cause analysis" icon="account_tree"
            aside={rootCause && <Chip tone={rootCause.complete ? 'success' : 'warning'} icon={rootCause.complete ? 'check_circle' : 'edit_note'}>{rootCause.complete ? 'Complete' : 'Draft'}</Chip>}>
            {capa.rca_focus && <Note tone="info" icon="lightbulb"><strong>{capa.framework_label} focus:</strong> {capa.rca_focus}</Note>}
            <form onSubmit={save} className="mt-3 space-y-4">
                <div className="max-w-xs">
                    <label className={labelCls}>Method</label>
                    <select value={d.method} disabled={!editable} onChange={(e) => form.setData('method', e.target.value)} className={inputCls}>
                        {(config.rcaMethods || ['five_whys', 'fishbone', 'both']).map((m) => <option key={m} value={m}>{{ five_whys: '5 Whys', fishbone: 'Fishbone (Ishikawa)', both: '5 Whys and Fishbone' }[m] || m}</option>)}
                    </select>
                    <InputError message={form.errors.method} className="mt-1" />
                </div>

                {useWhys && (
                    <div>
                        <div className="flex items-center justify-between">
                            <h4 className="text-xs font-semibold uppercase text-[#718096]">5 Whys</h4>
                            <span className={`text-[11px] ${filledWhys >= minWhys ? 'text-[#2D7D46]' : 'text-[#8A6400]'}`}>{filledWhys} of at least {minWhys} levels</span>
                        </div>
                        <ol className="mt-2 space-y-2">
                            {d.five_whys.map((w, idx) => (
                                <li key={idx} className="flex items-center gap-2">
                                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[#0A1F44] text-xs text-white">{idx + 1}</span>
                                    <input value={w} disabled={!editable} onChange={(e) => setWhy(idx, e.target.value)}
                                        placeholder={idx === 0 ? 'Why did the problem occur?' : 'Why did that happen?'} className="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-50" />
                                    {editable && d.five_whys.length > 1 && (
                                        <button type="button" onClick={() => form.setData('five_whys', d.five_whys.filter((_, i) => i !== idx))} aria-label={`Remove why ${idx + 1}`} className="rounded-lg p-1 text-[#B3261E] hover:bg-[#B3261E]/10"><Icon name="close" size={18} /></button>
                                    )}
                                </li>
                            ))}
                        </ol>
                        {editable && d.five_whys.length < 10 && (
                            <button type="button" onClick={() => form.setData('five_whys', [...d.five_whys, ''])} className="mt-2 inline-flex items-center gap-1 text-xs text-[#0A1F44] underline"><Icon name="add" size={14} />Add a level</button>
                        )}
                    </div>
                )}

                {useBone && (
                    <div>
                        <h4 className="text-xs font-semibold uppercase text-[#718096]">Fishbone categories</h4>
                        <div className="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                            {categories.map((cat) => (
                                <div key={cat} className="rounded-lg border border-gray-200 p-3">
                                    <p className="flex items-center gap-1.5 text-xs font-semibold text-[#0A1F44]"><Icon name={CATEGORY_ICON[cat] || 'label'} size={16} fill />{CATEGORY_LABEL[cat] || cat}</p>
                                    <ul className="mt-2 space-y-1.5">
                                        {(d.fishbone[cat] || []).map((c, idx) => (
                                            <li key={idx} className="flex items-center gap-1">
                                                <input value={c} disabled={!editable} onChange={(e) => setCause(cat, idx, e.target.value)} placeholder="Contributing cause" className="min-w-0 flex-1 rounded-lg border border-gray-200 px-2 py-1.5 text-xs disabled:bg-gray-50" />
                                                {editable && <button type="button" onClick={() => removeCause(cat, idx)} aria-label="Remove cause" className="rounded-lg p-1 text-[#B3261E] hover:bg-[#B3261E]/10"><Icon name="close" size={16} /></button>}
                                            </li>
                                        ))}
                                        {(d.fishbone[cat] || []).length === 0 && <li className="text-[11px] italic text-[#A0AEC0]">No causes recorded.</li>}
                                    </ul>
                                    {editable && <button type="button" onClick={() => addCause(cat)} className="mt-2 inline-flex items-center gap-1 text-xs text-[#0A1F44] underline"><Icon name="add" size={14} />Add cause</button>}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div>
                    <label className={labelCls}>Root cause summary</label>
                    <textarea value={d.root_cause_summary} disabled={!editable} onChange={(e) => form.setData('root_cause_summary', e.target.value)} rows={3} className={inputCls}
                        placeholder="The underlying cause the corrective actions must remove" />
                    <InputError message={form.errors.root_cause_summary} className="mt-1" />
                </div>
                <p className="text-[11px] text-[#718096]">Complete means at least {minWhys} whys or one fishbone cause, plus a summary. {rootCause?.completed_by && `Last saved by ${rootCause.completed_by.name}.`}</p>
                <InputError message={form.errors.capa} />
                {editable ? (
                    <div className="flex justify-end"><button disabled={form.processing} className={primaryBtn}>Save analysis</button></div>
                ) : <p className="text-[11px] text-[#718096]">The analysis is locked once the CAPA moves past root cause analysis.</p>}
            </form>
        </Card>
    );
}

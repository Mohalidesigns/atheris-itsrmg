import Chip from '@/Components/Material/Chip';
import InputError from '@/Components/InputError';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import CapaFields from './CapaFields';
import { Card, Note, inputCls, labelCls, primaryBtn, secondaryBtn } from './shared';

/** Framework path, classification, clause reference, CBN provisions and correlation. Also converts a legacy issue. */
export default function IdentificationPanel({ issue, capa, config, canWork, convert = false }) {
    const [editing, setEditing] = useState(convert);
    const inIdentification = convert || capa.stage === 'identification';
    const closed = capa.stage === 'closed';
    const settling = config.settling || { default: 30, min: 30, max: 90 };
    const form = useForm({
        capa_framework: capa.framework || '',
        capa_classification: capa.classification || '',
        framework_reference: capa.framework_reference || '',
        cbn_provisions: capa.cbn_provisions || [],
        cbn_correlation: capa.cbn_correlation || '',
        settling_days: capa.settling_days ?? settling.default,
    });
    const provs = (config.cbnProvisions || []).filter((p) => (capa.cbn_provisions || []).includes(p.key));

    const submit = (e) => {
        e.preventDefault();
        // Framework, classification and reference lock after identification: send only what can still change.
        form.transform((d) => (inIdentification ? d : { cbn_provisions: d.cbn_provisions, cbn_correlation: d.cbn_correlation }));
        form.put(route('issues.capa.update', issue.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    if (!editing) {
        return (
            <Card title="Identification & classification" icon="search"
                aside={canWork && !closed && <button type="button" onClick={() => setEditing(true)} className={secondaryBtn}>{inIdentification ? 'Edit' : 'Edit CBN mapping'}</button>}>
                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                    {[['Framework', capa.framework_label], ['Classification', capa.classification_label], ['Reference', capa.framework_reference_label]].map(([k, v]) => (
                        <div key={k}><dt className="text-xs text-[#718096]">{k}</dt><dd className="text-[#2D3748]">{v || '—'}</dd></div>
                    ))}
                </dl>
                <div className="mt-3">
                    <p className="text-xs text-[#718096]">CBN provisions</p>
                    <div className="mt-1 flex flex-wrap gap-1.5">
                        {provs.length ? provs.map((p) => (
                            <Chip key={p.key} tone="tertiary" icon="gavel" title={p.description}>{p.label}{!p.verified && ' (unverified)'}</Chip>
                        )) : <span className="text-xs italic text-[#B3261E]">None selected: required before leaving identification.</span>}
                    </div>
                </div>
                <div className="mt-3">
                    <p className="text-xs text-[#718096]">CBN correlation</p>
                    <p className={`mt-0.5 whitespace-pre-line text-sm ${capa.cbn_correlation ? 'text-[#2D3748]' : 'italic text-[#A0AEC0]'}`}>{capa.cbn_correlation || 'Not recorded yet.'}</p>
                </div>
                <p className="mt-3 text-[11px] text-[#718096]">
                    Settling period {capa.settling_days ?? settling.default} days before effectiveness can be verified.
                    {!inIdentification && ' Framework, classification and reference are locked once identification ends.'}
                </p>
            </Card>
        );
    }

    return (
        <Card title={convert ? 'Convert to a CAPA' : 'Identification & classification'} icon="search">
            <form onSubmit={submit} className="space-y-3">
                {convert && <Note tone="info" icon="info">Choosing a framework path moves this issue onto the six-stage CAPA workflow. It cannot be undone.</Note>}
                <CapaFields config={config} data={form.data} setData={form.setData} errors={form.errors} lockFramework={!inIdentification} />
                {!convert && (
                    <div>
                        <label className={labelCls}>CBN correlation</label>
                        <textarea value={form.data.cbn_correlation} onChange={(e) => form.setData('cbn_correlation', e.target.value)} rows={3}
                            placeholder="How this corrective action satisfies the selected CBN provisions" className={inputCls} />
                        <InputError message={form.errors.cbn_correlation} className="mt-1" />
                    </div>
                )}
                {inIdentification && (
                    <div className="max-w-xs">
                        <label className={labelCls}>Settling period (days)</label>
                        <input type="number" min={settling.min} max={settling.max} value={form.data.settling_days ?? ''} onChange={(e) => form.setData('settling_days', e.target.value)} className={inputCls} />
                        <p className="mt-1 text-[10px] text-[#718096]">{settling.min} to {settling.max} days between implementation and effectiveness verification.</p>
                        <InputError message={form.errors.settling_days} className="mt-1" />
                    </div>
                )}
                <InputError message={form.errors.capa} />
                <div className="flex justify-end gap-2">
                    {!convert && <button type="button" onClick={() => { form.reset(); setEditing(false); }} className={secondaryBtn}>Cancel</button>}
                    <button disabled={form.processing || (convert && !form.data.capa_framework)} className={primaryBtn}>{convert ? 'Convert to CAPA' : 'Save'}</button>
                </div>
            </form>
        </Card>
    );
}

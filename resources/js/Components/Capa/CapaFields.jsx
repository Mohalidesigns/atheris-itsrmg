import Chip from '@/Components/Material/Chip';
import InputError from '@/Components/InputError';
import { inputCls, labelCls } from './shared';

/**
 * Framework path + classification + clause reference + mandatory CBN provisions.
 * Shared by the create form, the Identification panel and "convert to CAPA".
 * `data`/`setData` are a useForm pair holding capa_framework, capa_classification,
 * framework_reference and cbn_provisions.
 */
export default function CapaFields({ config, data, setData, errors = {}, lockFramework = false, allowNone = false, provisionsRequired = true }) {
    const fw = (config.frameworks || []).find((f) => f.key === data.capa_framework);

    const pickFramework = (key) => {
        const next = (config.frameworks || []).find((f) => f.key === key);
        setData((d) => ({
            ...d,
            capa_framework: key,
            capa_classification: next && next.classifications.length === 1 ? next.classifications[0].key : '',
            framework_reference: '',
        }));
    };
    const toggleProvision = (key) => setData('cbn_provisions', data.cbn_provisions.includes(key)
        ? data.cbn_provisions.filter((k) => k !== key) : [...data.cbn_provisions, key]);

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label className={labelCls}>Framework path</label>
                    <select value={data.capa_framework || ''} disabled={lockFramework} onChange={(e) => pickFramework(e.target.value)} className={inputCls}>
                        {allowNone ? <option value="">Not a CAPA (legacy issue workflow)</option> : <option value="">Select a framework…</option>}
                        {(config.frameworks || []).map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
                    </select>
                    <InputError message={errors.capa_framework} className="mt-1" />
                </div>
                <div>
                    <label className={labelCls}>Classification</label>
                    <select value={data.capa_classification || ''} disabled={lockFramework || !fw} onChange={(e) => setData('capa_classification', e.target.value)} className={inputCls}>
                        <option value="">{fw ? 'Select…' : 'Choose a framework first'}</option>
                        {(fw?.classifications || []).map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                    </select>
                    <InputError message={errors.capa_classification} className="mt-1" />
                </div>
                <div>
                    <label className={labelCls}>Framework reference</label>
                    <select value={data.framework_reference || ''} disabled={lockFramework || !fw} onChange={(e) => setData('framework_reference', e.target.value)} className={inputCls}>
                        <option value="">{fw ? 'Select a clause / practice…' : 'Choose a framework first'}</option>
                        {(fw?.references || []).map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
                    </select>
                    <InputError message={errors.framework_reference} className="mt-1" />
                </div>
            </div>
            {fw && <p className="text-[11px] text-[#718096]"><strong>{fw.focus}.</strong> {fw.classification_hint}</p>}

            {(fw || !allowNone) && (
                <fieldset>
                    <legend className={labelCls}>CBN regulatory provisions{provisionsRequired ? ' (select at least one)' : ''}</legend>
                    <div className="mt-1 grid grid-cols-1 gap-2 md:grid-cols-2">
                        {(config.cbnProvisions || []).map((p) => {
                            const on = data.cbn_provisions.includes(p.key);
                            return (
                                <label key={p.key} className={`flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-xs ${on ? 'border-[#0A1F44] bg-[#0A1F44]/5' : 'border-gray-200'}`}>
                                    <input type="checkbox" checked={on} onChange={() => toggleProvision(p.key)} className="mt-0.5 rounded border-gray-300 text-[#0A1F44] focus:ring-[#C9A86A]" />
                                    <span className="min-w-0">
                                        <span className="flex flex-wrap items-center gap-1.5 font-medium text-[#2D3748]">
                                            {p.label}
                                            {!p.verified && <Chip tone="warning" size="xs" icon="help" title="Cited from the policy document; not yet checked against the issued CBN text">reference unverified</Chip>}
                                        </span>
                                        <span className="mt-0.5 block text-[#718096]">{p.description}</span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    <InputError message={errors.cbn_provisions || errors['cbn_provisions.0']} className="mt-1" />
                </fieldset>
            )}
        </div>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { APPETITE_LABELS, SOURCE_LABELS, humanize, ratingColor, toDateInput } from '@/Utils/risk';

const selectClass = 'mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm';

function ScorePreview({ likelihood, impact }) {
    const score = likelihood && impact ? likelihood * impact : null;
    if (!score) return <span className="text-gray-400 text-sm">--</span>;
    return (
        <span className="inline-flex items-center justify-center w-8 h-8 rounded-lg font-bold font-mono-data text-white text-sm" style={{ backgroundColor: ratingColor(score) }}>
            {score}
        </span>
    );
}

function ScoreBlock({ title, prefix, data, setData, errors, likelihoodLabels, impactLabels, hint }) {
    return (
        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
            <div className="flex items-center justify-between mb-3">
                <div>
                    <h4 className="text-sm font-semibold text-[#2D3748]">{title}</h4>
                    {hint && <p className="text-xs text-[#718096]">{hint}</p>}
                </div>
                <ScorePreview likelihood={data[`${prefix}_likelihood`]} impact={data[`${prefix}_impact`]} />
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel value="Likelihood" />
                    <select value={data[`${prefix}_likelihood`]} onChange={e => setData(`${prefix}_likelihood`, parseInt(e.target.value) || '')} className={selectClass}>
                        <option value="">Not scored</option>
                        {Object.entries(likelihoodLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                    </select>
                    <InputError message={errors[`${prefix}_likelihood`]} className="mt-1" />
                </div>
                <div>
                    <InputLabel value="Impact" />
                    <select value={data[`${prefix}_impact`]} onChange={e => setData(`${prefix}_impact`, parseInt(e.target.value) || '')} className={selectClass}>
                        <option value="">Not scored</option>
                        {Object.entries(impactLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                    </select>
                    <InputError message={errors[`${prefix}_impact`]} className="mt-1" />
                </div>
            </div>
        </div>
    );
}

export default function EditRisk({ risk, categories, users, assets = [], linkedAssetIds = [], likelihoodLabels, impactLabels, statuses = [], appetites = [], sources = [], strategies = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        title: risk.title || '',
        description: risk.description || '',
        category_id: risk.category_id || '',
        risk_owner_id: risk.risk_owner_id || '',
        status: risk.status || 'identified',
        source: risk.source || '',
        risk_appetite: risk.risk_appetite || '',
        treatment_strategy: risk.treatment_strategy || '',
        treatment_due_date: toDateInput(risk.treatment_due_date),
        review_date: toDateInput(risk.review_date),
        inherent_likelihood: risk.inherent_likelihood || '',
        inherent_impact: risk.inherent_impact || '',
        residual_likelihood: risk.residual_likelihood || '',
        residual_impact: risk.residual_impact || '',
        change_reason: '',
        asset_ids: linkedAssetIds || [],
    });

    const scoresChanged = ['inherent_likelihood', 'inherent_impact', 'residual_likelihood', 'residual_impact']
        .some((k) => String(data[k] || '') !== String(risk[k] || ''));

    const toggleAsset = (id) => {
        setData('asset_ids', data.asset_ids.includes(id)
            ? data.asset_ids.filter(a => a !== id)
            : [...data.asset_ids, id]);
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('risks.update', risk.id), { preserveScroll: true });
    };

    const errorCount = Object.keys(errors).length;

    return (
        <AuthenticatedLayout header={`Edit ${risk.risk_id_code}`}>
            <Head title={`Edit ${risk.risk_id_code}`} />

            <div className="max-w-3xl">
                {errorCount > 0 && (
                    <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
                        The risk could not be saved — fix the {errorCount} highlighted field{errorCount > 1 ? 's' : ''} below.
                    </div>
                )}
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Risk Title *" />
                            <TextInput id="title" value={data.title} className="mt-1 block w-full" onChange={e => setData('title', e.target.value)} required />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea id="description" value={data.description} onChange={e => setData('description', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel htmlFor="category_id" value="Category" />
                                <select id="category_id" value={data.category_id} onChange={e => setData('category_id', e.target.value)} className={selectClass}>
                                    <option value="">None</option>
                                    {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <InputError message={errors.category_id} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="risk_owner_id" value="Owner" />
                                <select id="risk_owner_id" value={data.risk_owner_id} onChange={e => setData('risk_owner_id', e.target.value)} className={selectClass}>
                                    <option value="">Unassigned</option>
                                    {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                                <InputError message={errors.risk_owner_id} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="status" value="Status *" />
                                <select id="status" value={data.status} onChange={e => setData('status', e.target.value)} className={selectClass}>
                                    {statuses.map(s => <option key={s} value={s}>{humanize(s)}</option>)}
                                </select>
                                <InputError message={errors.status} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel htmlFor="source" value="Source" />
                                <select id="source" value={data.source} onChange={e => setData('source', e.target.value)} className={selectClass}>
                                    <option value="">Not recorded</option>
                                    {sources.map(s => <option key={s} value={s}>{SOURCE_LABELS[s] || humanize(s)}</option>)}
                                </select>
                                <InputError message={errors.source} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="risk_appetite" value="Position vs. Appetite" />
                                <select id="risk_appetite" value={data.risk_appetite} onChange={e => setData('risk_appetite', e.target.value)} className={selectClass}>
                                    <option value="">Not assessed</option>
                                    {appetites.map(a => <option key={a} value={a}>{APPETITE_LABELS[a] || humanize(a)}</option>)}
                                </select>
                                <InputError message={errors.risk_appetite} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="review_date" value="Next Review" />
                                <TextInput id="review_date" type="date" value={data.review_date} className="mt-1 block w-full" onChange={e => setData('review_date', e.target.value)} />
                                <InputError message={errors.review_date} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="treatment_strategy" value="Treatment Strategy" />
                                <select id="treatment_strategy" value={data.treatment_strategy} onChange={e => setData('treatment_strategy', e.target.value)} className={selectClass}>
                                    <option value="">Not decided</option>
                                    {strategies.map(s => <option key={s} value={s}>{humanize(s)}</option>)}
                                </select>
                                <InputError message={errors.treatment_strategy} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="treatment_due_date" value="Treatment Due" />
                                <TextInput id="treatment_due_date" type="date" value={data.treatment_due_date} className="mt-1 block w-full" onChange={e => setData('treatment_due_date', e.target.value)} />
                                <InputError message={errors.treatment_due_date} className="mt-1" />
                            </div>
                        </div>

                        <ScoreBlock title="Inherent Risk" prefix="inherent" hint="Before controls" data={data} setData={setData} errors={errors} likelihoodLabels={likelihoodLabels} impactLabels={impactLabels} />
                        <ScoreBlock title="Residual Risk" prefix="residual" hint="After current controls" data={data} setData={setData} errors={errors} likelihoodLabels={likelihoodLabels} impactLabels={impactLabels} />

                        {scoresChanged && (
                            <div>
                                <InputLabel htmlFor="change_reason" value="Reason for score change" />
                                <TextInput id="change_reason" value={data.change_reason} className="mt-1 block w-full"
                                    placeholder="e.g. MFA rolled out to all privileged accounts"
                                    onChange={e => setData('change_reason', e.target.value)} />
                                <p className="text-xs text-[#718096] mt-1">Recorded in the risk's score history.</p>
                            </div>
                        )}

                        {assets.length > 0 && (
                            <div>
                                <InputLabel value={`Linked Assets (${data.asset_ids.length} selected)`} />
                                <div className="mt-1 max-h-44 overflow-y-auto border border-gray-200 rounded-lg divide-y divide-gray-50">
                                    {assets.map(a => (
                                        <label key={a.id} className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={data.asset_ids.includes(a.id)}
                                                onChange={() => toggleAsset(a.id)}
                                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30"
                                            />
                                            <span className="font-mono-data text-xs text-[#1A365D]">{a.asset_id_code}</span>
                                            <span className="text-[#2D3748]">{a.name}</span>
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.asset_ids || errors['asset_ids.0']} className="mt-1" />
                            </div>
                        )}

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('risks.show', risk.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>{processing ? 'Saving…' : 'Update Risk'}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

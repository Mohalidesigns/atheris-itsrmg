import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function EditRisk({ risk, categories, users, assets = [], linkedAssetIds = [], likelihoodLabels, impactLabels }) {
    const { data, setData, put, processing, errors } = useForm({
        title: risk.title || '',
        description: risk.description || '',
        category_id: risk.category_id || '',
        risk_owner_id: risk.risk_owner_id || '',
        status: risk.status || 'identified',
        source: risk.source || '',
        risk_appetite: risk.risk_appetite || '',
        treatment_strategy: risk.treatment_strategy || '',
        treatment_due_date: risk.treatment_due_date || '',
        review_date: risk.review_date || '',
        inherent_likelihood: risk.inherent_likelihood || '',
        inherent_impact: risk.inherent_impact || '',
        residual_likelihood: risk.residual_likelihood || '',
        residual_impact: risk.residual_impact || '',
        asset_ids: linkedAssetIds || [],
    });

    const toggleAsset = (id) => {
        setData('asset_ids', data.asset_ids.includes(id)
            ? data.asset_ids.filter(a => a !== id)
            : [...data.asset_ids, id]);
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('risks.update', risk.id));
    };

    const ScorePreview = ({ likelihood, impact }) => {
        const score = likelihood && impact ? likelihood * impact : null;
        if (!score) return <span className="text-gray-400 text-sm">--</span>;
        const color = score >= 20 ? '#C53030' : score >= 15 ? '#DD6B20' : score >= 8 ? '#D4AF37' : score >= 4 ? '#2D7D46' : '#319795';
        return (
            <span className="inline-flex items-center justify-center w-8 h-8 rounded-lg font-bold font-mono-data text-white text-sm" style={{ backgroundColor: color }}>
                {score}
            </span>
        );
    };

    return (
        <AuthenticatedLayout header={`Edit ${risk.risk_id_code}`}>
            <Head title={`Edit ${risk.risk_id_code}`} />

            <div className="max-w-3xl">
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
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Category" />
                                <select value={data.category_id} onChange={e => setData('category_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">None</option>
                                    {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Owner" />
                                <select value={data.risk_owner_id} onChange={e => setData('risk_owner_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="">None</option>
                                    {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Status" />
                                <select value={data.status} onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {['identified','assessed','treating','accepted','closed','archived'].map(s =>
                                        <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                                    )}
                                </select>
                            </div>
                        </div>

                        {/* Inherent Scoring */}
                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <div className="flex items-center justify-between mb-3">
                                <h4 className="text-sm font-semibold text-[#2D3748]">Inherent Risk</h4>
                                <ScorePreview likelihood={data.inherent_likelihood} impact={data.inherent_impact} />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel value="Likelihood" />
                                    <select value={data.inherent_likelihood} onChange={e => setData('inherent_likelihood', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">Select...</option>
                                        {Object.entries(likelihoodLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Impact" />
                                    <select value={data.inherent_impact} onChange={e => setData('inherent_impact', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">Select...</option>
                                        {Object.entries(impactLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Residual Scoring */}
                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <div className="flex items-center justify-between mb-3">
                                <h4 className="text-sm font-semibold text-[#2D3748]">Residual Risk</h4>
                                <ScorePreview likelihood={data.residual_likelihood} impact={data.residual_impact} />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel value="Likelihood" />
                                    <select value={data.residual_likelihood} onChange={e => setData('residual_likelihood', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">Select...</option>
                                        {Object.entries(likelihoodLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Impact" />
                                    <select value={data.residual_impact} onChange={e => setData('residual_impact', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">Select...</option>
                                        {Object.entries(impactLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                    </select>
                                </div>
                            </div>
                        </div>

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
                                <InputError message={errors.asset_ids} className="mt-1" />
                            </div>
                        )}

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('risks.show', risk.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Risk</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

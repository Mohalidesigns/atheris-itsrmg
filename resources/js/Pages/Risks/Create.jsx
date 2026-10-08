import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { APPETITE_LABELS, RATING_COLORS, SOURCE_LABELS, humanize, ratingFor } from '@/Utils/risk';

export default function CreateRisk({ categories, users, assets = [], nextCode, likelihoodLabels, impactLabels, appetites = [], sources = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        category_id: '',
        risk_owner_id: '',
        source: '',
        risk_appetite: '',
        inherent_likelihood: '',
        inherent_impact: '',
        tags: [],
        asset_ids: [],
    });

    const toggleAsset = (id) => {
        setData('asset_ids', data.asset_ids.includes(id)
            ? data.asset_ids.filter(a => a !== id)
            : [...data.asset_ids, id]);
    };

    const score = data.inherent_likelihood && data.inherent_impact
        ? data.inherent_likelihood * data.inherent_impact
        : null;

    const getRating = ratingFor;
    const ratingColors = RATING_COLORS;

    const submit = (e) => {
        e.preventDefault();
        post(route('risks.store'));
    };

    return (
        <AuthenticatedLayout header="Register New Risk">
            <Head title="New Risk" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">
                            {nextCode}
                        </span>
                        <h3 className="text-lg font-semibold text-[#2D3748]">Risk Details</h3>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Risk Title *" />
                            <TextInput
                                id="title"
                                value={data.title}
                                className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)}
                                required
                                placeholder="e.g. Unauthorized access to customer database"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Describe the risk, its context, and potential consequences..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="category_id" value="Category" />
                                <select
                                    id="category_id"
                                    value={data.category_id}
                                    onChange={e => setData('category_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select category...</option>
                                    {categories.map(c => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.category_id} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="risk_owner_id" value="Risk Owner" />
                                <select
                                    id="risk_owner_id"
                                    value={data.risk_owner_id}
                                    onChange={e => setData('risk_owner_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select owner...</option>
                                    {users.map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.risk_owner_id} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="source" value="Source" />
                                <select
                                    id="source"
                                    value={data.source}
                                    onChange={e => setData('source', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select source...</option>
                                    {sources.map(v => <option key={v} value={v}>{SOURCE_LABELS[v] || humanize(v)}</option>)}
                                </select>
                                <InputError message={errors.source} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="risk_appetite" value="Position vs. Appetite" />
                                <select
                                    id="risk_appetite"
                                    value={data.risk_appetite}
                                    onChange={e => setData('risk_appetite', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Not yet assessed</option>
                                    {appetites.map(v => <option key={v} value={v}>{APPETITE_LABELS[v] || humanize(v)}</option>)}
                                </select>
                                <InputError message={errors.risk_appetite} className="mt-1" />
                            </div>
                        </div>

                        {/* Inherent Risk Scoring */}
                        <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                            <h4 className="text-sm font-semibold text-[#2D3748] mb-3">
                                Inherent Risk Assessment (optional)
                            </h4>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <InputLabel value="Likelihood" />
                                    <select
                                        value={data.inherent_likelihood}
                                        onChange={e => setData('inherent_likelihood', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    >
                                        <option value="">Select...</option>
                                        {Object.entries(likelihoodLabels).map(([val, label]) => (
                                            <option key={val} value={val}>{val} - {label}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.inherent_likelihood} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Impact" />
                                    <select
                                        value={data.inherent_impact}
                                        onChange={e => setData('inherent_impact', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    >
                                        <option value="">Select...</option>
                                        {Object.entries(impactLabels).map(([val, label]) => (
                                            <option key={val} value={val}>{val} - {label}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.inherent_impact} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Score" />
                                    <div className="mt-1 flex items-center gap-2 h-[42px]">
                                        {score ? (
                                            <>
                                                <span
                                                    className="inline-flex items-center justify-center w-10 h-10 rounded-lg font-bold font-mono-data text-white text-lg"
                                                    style={{ backgroundColor: ratingColors[getRating(score)] }}
                                                >
                                                    {score}
                                                </span>
                                                <span className="text-xs font-semibold uppercase" style={{ color: ratingColors[getRating(score)] }}>
                                                    {getRating(score)?.replace('_', ' ')}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-sm text-gray-400">Select likelihood and impact</span>
                                        )}
                                    </div>
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
                            <Link href={route('risks.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Create Risk
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

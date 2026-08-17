import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function EditPolicy({ policy, users, categories }) {
    const { data, setData, put, processing, errors } = useForm({
        title: policy.title || '',
        description: policy.description || '',
        content: policy.content || '',
        category: policy.category || '',
        owner_id: policy.owner_id || '',
        status: policy.status || 'draft',
        is_mandatory: policy.is_mandatory ?? true,
        effective_date: policy.effective_date || '',
        review_date: policy.review_date || '',
        expiry_date: policy.expiry_date || '',
        change_summary: '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('policies.update', policy.id));
    };

    return (
        <AuthenticatedLayout header={`Edit ${policy.policy_code}`}>
            <Head title={`Edit ${policy.policy_code}`} />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <span className="font-mono text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">
                            {policy.policy_code}
                        </span>
                        <span className="font-mono text-xs text-[#718096]">v{policy.version_number}</span>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Policy Title *" />
                            <TextInput
                                id="title"
                                value={data.title}
                                className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)}
                                required
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="content" value="Policy Content" />
                            <textarea
                                id="content"
                                value={data.content}
                                onChange={e => setData('content', e.target.value)}
                                rows={12}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm font-mono"
                            />
                            <InputError message={errors.content} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="change_summary" value="Change Summary" />
                            <TextInput
                                id="change_summary"
                                value={data.change_summary}
                                className="mt-1 block w-full"
                                onChange={e => setData('change_summary', e.target.value)}
                                placeholder="Briefly describe what changed..."
                            />
                            <p className="mt-1 text-xs text-[#718096]">Required when content is modified. A new version will be created automatically.</p>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Category" />
                                <select
                                    value={data.category}
                                    onChange={e => setData('category', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">None</option>
                                    {Object.entries(categories).map(([key, label]) => (
                                        <option key={key} value={key}>{label}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Owner" />
                                <select
                                    value={data.owner_id}
                                    onChange={e => setData('owner_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">None</option>
                                    {users.map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Status" />
                                <select
                                    value={data.status}
                                    onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    {['draft', 'in_review', 'approved', 'published', 'retired'].map(s => (
                                        <option key={s} value={s}>
                                            {s.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel htmlFor="effective_date" value="Effective Date" />
                                <TextInput
                                    id="effective_date"
                                    type="date"
                                    value={data.effective_date}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('effective_date', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="review_date" value="Review Date" />
                                <TextInput
                                    id="review_date"
                                    type="date"
                                    value={data.review_date}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('review_date', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel htmlFor="expiry_date" value="Expiry Date" />
                                <TextInput
                                    id="expiry_date"
                                    type="date"
                                    value={data.expiry_date}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('expiry_date', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="is_mandatory"
                                checked={data.is_mandatory}
                                onChange={e => setData('is_mandatory', e.target.checked)}
                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]/30"
                            />
                            <InputLabel htmlFor="is_mandatory" value="This policy is mandatory for all employees" />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('policies.show', policy.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Update Policy
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function CreatePolicy({ users, categories }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        content: '',
        category: '',
        owner_id: '',
        is_mandatory: true,
        effective_date: '',
        review_date: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('policies.store'));
    };

    return (
        <AuthenticatedLayout header="Create New Policy">
            <Head title="New Policy" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-6">Policy Details</h3>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Policy Title *" />
                            <TextInput
                                id="title"
                                value={data.title}
                                className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)}
                                required
                                placeholder="e.g. Information Security Policy"
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
                                placeholder="Brief summary of the policy purpose..."
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
                                placeholder="Enter the full policy content here..."
                            />
                            <InputError message={errors.content} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="category" value="Category" />
                                <select
                                    id="category"
                                    value={data.category}
                                    onChange={e => setData('category', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select category...</option>
                                    {Object.entries(categories).map(([key, label]) => (
                                        <option key={key} value={key}>{label}</option>
                                    ))}
                                </select>
                                <InputError message={errors.category} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="owner_id" value="Policy Owner" />
                                <select
                                    id="owner_id"
                                    value={data.owner_id}
                                    onChange={e => setData('owner_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select owner...</option>
                                    {users.map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.owner_id} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="effective_date" value="Effective Date" />
                                <TextInput
                                    id="effective_date"
                                    type="date"
                                    value={data.effective_date}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('effective_date', e.target.value)}
                                />
                                <InputError message={errors.effective_date} className="mt-1" />
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
                                <InputError message={errors.review_date} className="mt-1" />
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
                            <Link href={route('policies.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Create Policy
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function CreateVulnerability({ users, assets = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        severity: '',
        cvss_score: '',
        cve_id: '',
        source: '',
        assigned_to: '',
        due_date: '',
        asset_ids: [],
    });

    const toggleAsset = (id) => {
        setData('asset_ids', data.asset_ids.includes(id)
            ? data.asset_ids.filter(a => a !== id)
            : [...data.asset_ids, id]);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('vulnerabilities.store'));
    };

    return (
        <AuthenticatedLayout header="Report New Vulnerability">
            <Head title="New Vulnerability" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-6">Vulnerability Details</h3>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel htmlFor="title" value="Title *" />
                            <TextInput
                                id="title"
                                value={data.title}
                                className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)}
                                required
                                placeholder="e.g. SQL Injection in Login API"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="description" value="Description" />
                            <textarea
                                id="description"
                                value={data.description}
                                onChange={e => setData('description', e.target.value)}
                                rows={4}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Describe the vulnerability, affected systems, and potential impact..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="severity" value="Severity *" />
                                <select
                                    id="severity"
                                    value={data.severity}
                                    onChange={e => setData('severity', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                    required
                                >
                                    <option value="">Select severity...</option>
                                    <option value="critical">Critical</option>
                                    <option value="high">High</option>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                    <option value="info">Info</option>
                                </select>
                                <InputError message={errors.severity} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="cvss_score" value="CVSS Score" />
                                <TextInput
                                    id="cvss_score"
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    max="10"
                                    value={data.cvss_score}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('cvss_score', e.target.value)}
                                    placeholder="0.0 - 10.0"
                                />
                                <InputError message={errors.cvss_score} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="cve_id" value="CVE ID" />
                                <TextInput
                                    id="cve_id"
                                    value={data.cve_id}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('cve_id', e.target.value)}
                                    placeholder="CVE-2024-XXXXX"
                                />
                                <InputError message={errors.cve_id} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="source" value="Source" />
                                <TextInput
                                    id="source"
                                    value={data.source}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('source', e.target.value)}
                                    placeholder="e.g. Penetration Test, Scan, Bug Bounty"
                                />
                                <InputError message={errors.source} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="assigned_to" value="Assign To" />
                                <select
                                    id="assigned_to"
                                    value={data.assigned_to}
                                    onChange={e => setData('assigned_to', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                >
                                    <option value="">Select assignee...</option>
                                    {(users || []).map(u => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.assigned_to} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="due_date" value="Due Date" />
                                <TextInput
                                    id="due_date"
                                    type="date"
                                    value={data.due_date}
                                    className="mt-1 block w-full"
                                    onChange={e => setData('due_date', e.target.value)}
                                />
                                <InputError message={errors.due_date} className="mt-1" />
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
                            <Link href={route('vulnerabilities.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">
                                Cancel
                            </Link>
                            <PrimaryButton disabled={processing}>
                                Create Vulnerability
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function CreateVendorAssessment({ vendors = [], preselectedVendorId, statuses = [], assessmentTypes = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        vendor_id: preselectedVendorId || '',
        title: '',
        assessment_type: 'periodic',
        status: 'pending',
        overall_score: '',
        assessment_date: '',
        findings: '',
        recommendations: '',
        next_review_date: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('vendor-assessments.store'));
    };

    return (
        <AuthenticatedLayout header="New Vendor Assessment">
            <Head title="New Vendor Assessment" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-6">Assessment Details</h3>

                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <InputLabel value="Vendor *" />
                            <select value={data.vendor_id} onChange={e => setData('vendor_id', e.target.value)} required
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                <option value="">Select vendor...</option>
                                {vendors.map(v => <option key={v.id} value={v.id}>{v.vendor_code} — {v.name}</option>)}
                            </select>
                            <InputError message={errors.vendor_id} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="title" value="Title *" />
                            <TextInput id="title" value={data.title} className="mt-1 block w-full"
                                onChange={e => setData('title', e.target.value)} required
                                placeholder="e.g. Annual security review 2026" />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <InputLabel value="Type *" />
                                <select value={data.assessment_type} onChange={e => setData('assessment_type', e.target.value)} required
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {assessmentTypes.map(t => <option key={t} value={t}>{cap(t)}</option>)}
                                </select>
                                <InputError message={errors.assessment_type} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Status" />
                                <select value={data.status} onChange={e => setData('status', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    {statuses.map(s => <option key={s} value={s}>{cap(s)}</option>)}
                                </select>
                                <InputError message={errors.status} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="overall_score" value="Overall Score (0-100)" />
                                <TextInput id="overall_score" type="number" step="0.1" min="0" max="100" value={data.overall_score}
                                    className="mt-1 block w-full font-mono-data"
                                    onChange={e => setData('overall_score', e.target.value)} />
                                <InputError message={errors.overall_score} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="assessment_date" value="Assessment Date" />
                                <TextInput id="assessment_date" type="date" value={data.assessment_date} className="mt-1 block w-full"
                                    onChange={e => setData('assessment_date', e.target.value)} />
                                <InputError message={errors.assessment_date} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="next_review_date" value="Next Review Date" />
                                <TextInput id="next_review_date" type="date" value={data.next_review_date} className="mt-1 block w-full"
                                    onChange={e => setData('next_review_date', e.target.value)} />
                                <InputError message={errors.next_review_date} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="findings" value="Findings" />
                            <textarea id="findings" value={data.findings} onChange={e => setData('findings', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.findings} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="recommendations" value="Recommendations" />
                            <textarea id="recommendations" value={data.recommendations} onChange={e => setData('recommendations', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.recommendations} className="mt-1" />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('vendor-assessments.index')} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Create Assessment</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';

export default function CsatCreate() {
    const { data, setData, post, processing, errors } = useForm({
        assessment_year: new Date().getFullYear(),
        submission_deadline: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('csat.store'));
    };

    return (
        <AuthenticatedLayout header="Create CBN-CSAT Assessment">
            <Head title="New Assessment" />

            <div className="max-w-xl">
                <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                    <h3 className="text-lg font-semibold text-[#2D3748] mb-4">New Assessment Cycle</h3>

                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Assessment Year</label>
                            <input
                                type="number"
                                value={data.assessment_year}
                                onChange={e => setData('assessment_year', parseInt(e.target.value))}
                                min="2020"
                                max={new Date().getFullYear() + 1}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            />
                            {errors.assessment_year && <p className="text-xs text-[#C53030] mt-1">{errors.assessment_year}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">CBN Submission Deadline</label>
                            <input
                                type="date"
                                value={data.submission_deadline}
                                onChange={e => setData('submission_deadline', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                            />
                        </div>

                        <div className="flex gap-3 pt-2">
                            <button
                                type="submit"
                                disabled={processing}
                                className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] disabled:opacity-50"
                            >
                                Create Assessment
                            </button>
                            <Link
                                href={route('csat.index')}
                                className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"
                            >
                                Cancel
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

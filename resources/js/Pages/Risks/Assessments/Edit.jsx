import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function EditRiskAssessment({ assessment, likelihoodLabels = {}, impactLabels = {} }) {
    const { data, setData, put, processing, errors } = useForm({
        likelihood: assessment.likelihood || '',
        impact: assessment.impact || '',
        justification: assessment.justification || '',
        notes: assessment.notes || '',
        next_review_date: assessment.next_review_date ? String(assessment.next_review_date).slice(0, 10) : '',
    });

    const score = data.likelihood && data.impact ? data.likelihood * data.impact : null;

    const submit = (e) => {
        e.preventDefault();
        put(route('risk-assessments.update', assessment.id));
    };

    return (
        <AuthenticatedLayout header="Edit Risk Assessment">
            <Head title="Edit Risk Assessment" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <div className="flex items-center gap-3 mb-6">
                        {assessment.risk && (
                            <span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-3 py-1 rounded-lg font-semibold">
                                {assessment.risk.risk_id_code}
                            </span>
                        )}
                        <h3 className="text-lg font-semibold text-[#2D3748]">
                            {assessment.risk?.title || 'Assessment'} — {assessment.assessment_type} ({assessment.methodology})
                        </h3>
                    </div>

                    <form onSubmit={submit} className="space-y-5">
                        {assessment.methodology === 'qualitative' && (
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <InputLabel value="Likelihood" />
                                    <select value={data.likelihood} onChange={e => setData('likelihood', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">--</option>
                                        {Object.entries(likelihoodLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                    </select>
                                    <InputError message={errors.likelihood} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Impact" />
                                    <select value={data.impact} onChange={e => setData('impact', parseInt(e.target.value) || '')}
                                        className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                        <option value="">--</option>
                                        {Object.entries(impactLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                    </select>
                                    <InputError message={errors.impact} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Score" />
                                    <div className="mt-1 h-[42px] flex items-center">
                                        {score ? (
                                            <span className="inline-flex items-center justify-center w-10 h-10 rounded-lg font-bold font-mono-data text-white text-lg bg-[#0A1F44]">{score}</span>
                                        ) : <span className="text-sm text-gray-400">--</span>}
                                    </div>
                                </div>
                            </div>
                        )}

                        <div>
                            <InputLabel htmlFor="justification" value="Justification" />
                            <textarea id="justification" value={data.justification} onChange={e => setData('justification', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.justification} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="notes" value="Notes" />
                            <textarea id="notes" value={data.notes} onChange={e => setData('notes', e.target.value)} rows={2}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" />
                            <InputError message={errors.notes} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="next_review_date" value="Next Review Date" />
                            <TextInput id="next_review_date" type="date" value={data.next_review_date} className="mt-1 block w-full"
                                onChange={e => setData('next_review_date', e.target.value)} />
                            <InputError message={errors.next_review_date} className="mt-1" />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={route('risk-assessments.show', assessment.id)} className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Update Assessment</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

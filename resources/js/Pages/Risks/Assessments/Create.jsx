import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { useState } from 'react';

export default function CreateAssessment({ risk, risks, likelihoodLabels, impactLabels }) {
    const { data, setData, post, processing, errors } = useForm({
        risk_id: risk?.id || '',
        methodology: 'qualitative',
        assessment_type: 'inherent',
        likelihood: '',
        impact: '',
        impact_financial: '',
        impact_operational: '',
        impact_reputational: '',
        impact_regulatory: '',
        impact_safety: '',
        fair_tef: '',
        fair_vul: '',
        fair_plm: '',
        fair_slm: '',
        fair_currency: 'NGN',
        justification: '',
        notes: '',
        next_review_date: '',
    });

    const score = data.methodology === 'qualitative' && data.likelihood && data.impact
        ? data.likelihood * data.impact : null;

    const submit = (e) => {
        e.preventDefault();
        post(route('risk-assessments.store'));
    };

    return (
        <AuthenticatedLayout header="New Risk Assessment">
            <Head title="New Assessment" />

            <div className="max-w-3xl">
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                    <form onSubmit={submit} className="space-y-5">
                        {!risk && (
                            <div>
                                <InputLabel value="Select Risk *" />
                                <select value={data.risk_id} onChange={e => setData('risk_id', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm" required>
                                    <option value="">Choose a risk...</option>
                                    {risks.map(r => <option key={r.id} value={r.id}>{r.risk_id_code} - {r.title}</option>)}
                                </select>
                                <InputError message={errors.risk_id} className="mt-1" />
                            </div>
                        )}

                        {risk && (
                            <div className="bg-gray-50 rounded-lg p-3">
                                <span className="font-mono-data text-xs text-[#1A365D] font-semibold">{risk.risk_id_code}</span>
                                <p className="text-sm text-[#2D3748] font-medium">{risk.title}</p>
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Methodology *" />
                                <select value={data.methodology} onChange={e => setData('methodology', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="qualitative">Qualitative (Likelihood x Impact)</option>
                                    <option value="fair">FAIR (Quantitative)</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Assessment Type *" />
                                <select value={data.assessment_type} onChange={e => setData('assessment_type', e.target.value)}
                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                    <option value="inherent">Inherent (before controls)</option>
                                    <option value="residual">Residual (after controls)</option>
                                </select>
                            </div>
                        </div>

                        {data.methodology === 'qualitative' && (
                            <div className="border border-gray-200 rounded-xl p-4 bg-gray-50/50">
                                <h4 className="text-sm font-semibold text-[#2D3748] mb-3">Qualitative Scoring</h4>
                                <div className="grid grid-cols-3 gap-4">
                                    <div>
                                        <InputLabel value="Likelihood *" />
                                        <select value={data.likelihood} onChange={e => setData('likelihood', parseInt(e.target.value) || '')}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                            <option value="">Select...</option>
                                            {Object.entries(likelihoodLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                        </select>
                                        <InputError message={errors.likelihood} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Impact *" />
                                        <select value={data.impact} onChange={e => setData('impact', parseInt(e.target.value) || '')}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm">
                                            <option value="">Select...</option>
                                            {Object.entries(impactLabels).map(([v, l]) => <option key={v} value={v}>{v} - {l}</option>)}
                                        </select>
                                        <InputError message={errors.impact} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Score" />
                                        <div className="mt-1 h-[42px] flex items-center">
                                            {score ? (
                                                <span className="font-mono-data text-xl font-bold" style={{
                                                    color: score >= 20 ? '#C53030' : score >= 15 ? '#DD6B20' : score >= 8 ? '#D4AF37' : score >= 4 ? '#2D7D46' : '#319795'
                                                }}>{score}</span>
                                            ) : <span className="text-gray-400 text-sm">--</span>}
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-4">
                                    <h5 className="text-xs font-semibold text-[#718096] uppercase mb-2">Impact Breakdown (Optional)</h5>
                                    <div className="grid grid-cols-5 gap-2">
                                        {['financial', 'operational', 'reputational', 'regulatory', 'safety'].map(dim => (
                                            <div key={dim}>
                                                <label className="text-xs text-[#718096] capitalize">{dim}</label>
                                                <select value={data[`impact_${dim}`]} onChange={e => setData(`impact_${dim}`, parseInt(e.target.value) || '')}
                                                    className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-xs py-1.5">
                                                    <option value="">--</option>
                                                    {[1,2,3,4,5].map(v => <option key={v} value={v}>{v}</option>)}
                                                </select>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}

                        {data.methodology === 'fair' && (
                            <div className="border border-gray-200 rounded-xl p-4 bg-amber-50/30">
                                <h4 className="text-sm font-semibold text-[#2D3748] mb-3">FAIR Quantification</h4>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel value="Threat Event Frequency (annual) *" />
                                        <input type="number" step="0.01" value={data.fair_tef} onChange={e => setData('fair_tef', e.target.value)}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                            placeholder="e.g. 2.5 times/year" />
                                        <InputError message={errors.fair_tef} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Vulnerability (0-1) *" />
                                        <input type="number" step="0.01" min="0" max="1" value={data.fair_vul} onChange={e => setData('fair_vul', e.target.value)}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                            placeholder="e.g. 0.7" />
                                        <InputError message={errors.fair_vul} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Primary Loss Magnitude (NGN) *" />
                                        <input type="number" step="0.01" value={data.fair_plm} onChange={e => setData('fair_plm', e.target.value)}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                            placeholder="e.g. 50000000" />
                                        <InputError message={errors.fair_plm} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Secondary Loss Magnitude (NGN)" />
                                        <input type="number" step="0.01" value={data.fair_slm} onChange={e => setData('fair_slm', e.target.value)}
                                            className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                            placeholder="e.g. 10000000" />
                                    </div>
                                </div>
                                {data.fair_tef && data.fair_vul && data.fair_plm && (
                                    <div className="mt-3 p-3 bg-white rounded-lg border border-amber-200">
                                        <p className="text-xs text-[#718096]">Estimated Annual Loss Expectancy</p>
                                        <p className="text-xl font-bold font-mono-data text-[#2D3748]">
                                            {new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(
                                                data.fair_tef * data.fair_vul * (parseFloat(data.fair_plm) + parseFloat(data.fair_slm || 0))
                                            )}
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}

                        <div>
                            <InputLabel value="Justification / Notes" />
                            <textarea value={data.justification} onChange={e => setData('justification', e.target.value)} rows={3}
                                className="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-[#1A365D] focus:ring-[#1A365D]/30 text-sm"
                                placeholder="Provide reasoning for the assessment scores..." />
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                            <Link href={risk ? route('risks.show', risk.id) : route('risk-assessments.index')}
                                className="px-4 py-2 text-sm text-[#718096] hover:text-[#2D3748]">Cancel</Link>
                            <PrimaryButton disabled={processing}>Submit Assessment</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

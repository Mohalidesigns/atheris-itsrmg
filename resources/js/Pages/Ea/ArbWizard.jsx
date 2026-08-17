import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

const STEPS = ['Subject', 'Impact', 'Principles & Standards', 'Review'];

export default function ArbWizard({ apps = [], standards = [], principles = [], plateaux = [] }) {
    const [step, setStep] = useState(0);
    const form = useForm({
        code: '', title: '', summary: '',
        impact_blast_radius: [], impacted_principles: [], impacted_standards: [],
        meeting_date: '', voters: [], risk_band: 'medium',
    });

    const togglePick = (key, value) => {
        const arr = new Set(form.data[key]);
        arr.has(value) ? arr.delete(value) : arr.add(value);
        form.setData(key, Array.from(arr));
    };

    const submit = () => form.post(route('ea.arb.store'));

    return (
        <AuthenticatedLayout header="ARB Submission Wizard">
            <Head title="ARB Wizard" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Governance' }, { label: 'ARB' }, { label: 'New submission' }]}
                title="ARB Submission Wizard"
                subtitle="Architecture Review Board workflow: subject, blast-radius, impacted principles & standards, then approval flow."
            />

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-3 mb-4">
                <ol className="flex items-center justify-between text-xs">
                    {STEPS.map((s, i) => (
                        <li key={s} className={`flex-1 text-center ${i === step ? 'font-bold text-[#0A1F44]' : 'text-gray-400'}`}>
                            <span className={`inline-block w-6 h-6 rounded-full ${i === step ? 'bg-[#0A1F44] text-white' : 'bg-gray-100'} mr-1`}>{i + 1}</span>
                            {s}
                        </li>
                    ))}
                </ol>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                {step === 0 && (
                    <div className="space-y-3">
                        <input className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Submission code (auto if blank)" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                        <input className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        <textarea rows={6} className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Summary — what is being proposed, why, what changes" value={form.data.summary} onChange={(e) => form.setData('summary', e.target.value)} />
                    </div>
                )}
                {step === 1 && (
                    <div>
                        <h4 className="font-bold text-sm mb-2 text-[#0A1F44]">Applications in the blast radius</h4>
                        <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                            {apps.map((a) => (
                                <label key={a.id} className="flex items-center gap-2 text-xs border border-gray-100 rounded px-2 py-1">
                                    <input type="checkbox" checked={form.data.impact_blast_radius.includes(a.id)} onChange={() => togglePick('impact_blast_radius', a.id)} />
                                    <span>{a.name}{a.criticality === 'critical' ? ' ★' : ''}</span>
                                </label>
                            ))}
                        </div>
                    </div>
                )}
                {step === 2 && (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <h4 className="font-bold text-sm mb-2 text-[#0A1F44]">Impacted principles</h4>
                            <div className="space-y-1 text-xs">
                                {principles.map((p) => (
                                    <label key={p.id} className="flex items-center gap-2 border border-gray-100 rounded px-2 py-1">
                                        <input type="checkbox" checked={form.data.impacted_principles.includes(p.id)} onChange={() => togglePick('impacted_principles', p.id)} />
                                        <span>{p.code} — {p.name}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div>
                            <h4 className="font-bold text-sm mb-2 text-[#0A1F44]">Impacted standards</h4>
                            <div className="space-y-1 text-xs">
                                {standards.map((s) => (
                                    <label key={s.id} className="flex items-center gap-2 border border-gray-100 rounded px-2 py-1">
                                        <input type="checkbox" checked={form.data.impacted_standards.includes(s.id)} onChange={() => togglePick('impacted_standards', s.id)} />
                                        <span>{s.code} — {s.name}</span>
                                    </label>
                                ))}
                            </div>
                        </div>
                    </div>
                )}
                {step === 3 && (
                    <div className="space-y-3 text-sm">
                        <div><b>Title:</b> {form.data.title}</div>
                        <div><b>Summary:</b><div className="text-xs text-gray-600 mt-1">{form.data.summary}</div></div>
                        <div><b>Apps impacted:</b> {form.data.impact_blast_radius.length}</div>
                        <div><b>Principles:</b> {form.data.impacted_principles.length}</div>
                        <div><b>Standards:</b> {form.data.impacted_standards.length}</div>
                        <div className="flex items-center gap-2">
                            <label className="text-xs">Risk band:</label>
                            <select value={form.data.risk_band} onChange={(e) => form.setData('risk_band', e.target.value)} className="border border-gray-200 rounded-lg px-3 py-1 text-sm">
                                <option value="low">low</option><option value="medium">medium</option><option value="high">high</option><option value="critical">critical</option>
                            </select>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-xs">Meeting date:</label>
                            <input type="date" value={form.data.meeting_date} onChange={(e) => form.setData('meeting_date', e.target.value)} className="border border-gray-200 rounded-lg px-3 py-1 text-sm" />
                        </div>
                    </div>
                )}
                <div className="flex items-center justify-between mt-5">
                    <button disabled={step === 0} onClick={() => setStep(step - 1)} className="px-3 py-2 text-sm rounded-lg border border-gray-200">Back</button>
                    {step < STEPS.length - 1 ? (
                        <button onClick={() => setStep(step + 1)} className="px-3 py-2 text-sm rounded-lg bg-[#0A1F44] text-white">Next</button>
                    ) : (
                        <button onClick={submit} disabled={form.processing} className="px-3 py-2 text-sm rounded-lg bg-[#0A1F44] text-white">Submit to ARB</button>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

const NDPA_QUESTIONS = [
    { id: 'q1', text: 'Does the processing involve personal data of children or vulnerable groups?', weight: 2 },
    { id: 'q2', text: 'Is sensitive personal data (health, biometric, financial) processed?', weight: 2 },
    { id: 'q3', text: 'Does the data flow cross Nigeria borders?', weight: 1.5 },
    { id: 'q4', text: 'Is automated decision-making applied?', weight: 1.5 },
    { id: 'q5', text: 'Is large-scale processing (>10,000 data subjects) involved?', weight: 1 },
    { id: 'q6', text: 'Has explicit, unambiguous consent been obtained?', weight: -1.5 },
    { id: 'q7', text: 'Are retention limits documented and enforced?', weight: -1 },
    { id: 'q8', text: 'Is the system penetration-tested against NDPA Article 32 controls?', weight: -1 },
    { id: 'q9', text: 'Is the DPO involved in design reviews?', weight: -1 },
    { id: 'q10', text: 'Are subject rights (access, rectification, erasure) operational?', weight: -1 },
];

export default function Dpia({ assessments = [], consent = [], crossBorder = 0, approved = 0, flows = [], entities = [] }) {
    const [show, setShow] = useState(false);
    const [answers, setAnswers] = useState({});
    const form = useForm({
        subject: '', data_flow_id: '', logical_entity_id: '',
        risk_score: 0, risk_band: 'low', mitigation_plan: '', status: 'draft', answers: {},
    });

    const score = NDPA_QUESTIONS.reduce((s, q) => s + (answers[q.id] ? q.weight : 0), 0);
    const band = score >= 7 ? 'high' : (score >= 4 ? 'medium' : 'low');

    const submit = (e) => {
        e.preventDefault();
        form.setData({ ...form.data, risk_score: score, risk_band: band, answers });
        form.post(route('ea.dpia.store'), { onSuccess: () => { setShow(false); setAnswers({}); } });
    };

    return (
        <AuthenticatedLayout header="DPIA & Privacy">
            <Head title="DPIA & Privacy" />
            <PageHeader
                breadcrumbs={[{ label: 'EA' }, { label: 'Data Privacy' }, { label: 'DPIA' }]}
                title="Data Protection Impact Assessment"
                subtitle="NDPA Schedule wizard, cross-border tracking, consent purpose register and DPO decision workflow."
                actions={<button onClick={() => setShow(true)} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">+ New DPIA</button>}
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.dpia')} current="ea.dpia" />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <KpiCard label="DPIAs" value={assessments.length} tone="navy" />
                <KpiCard label="Approved" value={approved} tone="green" />
                <KpiCard label="Cross-border flows" value={crossBorder} tone="amber" />
                <KpiCard label="Consent purposes" value={consent.length} tone="gold" />
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-6">
                <h3 className="font-bold text-[#0A1F44] mb-3">Assessments</h3>
                <table className="w-full text-sm">
                    <thead className="text-xs text-gray-500 border-b border-gray-100">
                        <tr><th className="text-left py-2">Code</th><th className="text-left">Subject</th><th className="text-left">Band</th><th className="text-left">Status</th><th className="text-left">Score</th></tr>
                    </thead>
                    <tbody>
                        {assessments.map((a) => (
                            <tr key={a.id} className="border-b border-gray-50">
                                <td className="py-2 font-mono text-xs">{a.code}</td>
                                <td>{a.subject}</td>
                                <td><StatusBadge status={a.risk_band === 'high' ? 'critical' : a.risk_band === 'medium' ? 'moderate' : 'low'} label={a.risk_band} /></td>
                                <td><StatusBadge status={a.status === 'approved' ? 'compliant' : 'in_progress'} label={a.status} /></td>
                                <td>{a.risk_score}</td>
                            </tr>
                        ))}
                        {assessments.length === 0 && <tr><td colSpan={5} className="py-6 text-center text-gray-400 text-sm">No DPIAs recorded yet.</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h3 className="font-bold text-[#0A1F44] mb-3">Consent Purpose Register</h3>
                <table className="w-full text-sm">
                    <thead className="text-xs text-gray-500 border-b border-gray-100">
                        <tr><th className="text-left py-2">Purpose</th><th className="text-left">Lawful basis</th><th className="text-left">Retention</th><th className="text-left">Cross-border</th></tr>
                    </thead>
                    <tbody>
                        {consent.map((c) => (
                            <tr key={c.id} className="border-b border-gray-50">
                                <td className="py-2">{c.purpose}</td>
                                <td className="text-xs">{c.lawful_basis}</td>
                                <td className="text-xs">{c.retention_period}</td>
                                <td>{c.cross_border ? <StatusBadge status="moderate" label="Yes" /> : <StatusBadge status="low" label="No" />}</td>
                            </tr>
                        ))}
                        {consent.length === 0 && <tr><td colSpan={4} className="py-6 text-center text-gray-400 text-sm">No consent purposes recorded.</td></tr>}
                    </tbody>
                </table>
            </div>

            {show && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-40 overflow-y-auto p-4">
                    <div className="bg-white rounded-xl shadow-xl w-full max-w-3xl p-5 my-6">
                        <h3 className="font-bold text-[#0A1F44] mb-3">DPIA Wizard (NDPA Schedule, 10 questions)</h3>
                        <form onSubmit={submit} className="space-y-3">
                            <input className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Subject (e.g. Customer onboarding KYC flow)" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                            <div className="grid grid-cols-2 gap-2">
                                <select className="border border-gray-200 rounded-lg px-3 py-2 text-sm" value={form.data.data_flow_id} onChange={(e) => form.setData('data_flow_id', e.target.value)}>
                                    <option value="">— Linked data flow —</option>
                                    {flows.map((f) => <option key={f.id} value={f.id}>{f.name}{f.cross_border ? ' (cross-border)' : ''}</option>)}
                                </select>
                                <select className="border border-gray-200 rounded-lg px-3 py-2 text-sm" value={form.data.logical_entity_id} onChange={(e) => form.setData('logical_entity_id', e.target.value)}>
                                    <option value="">— Linked logical entity —</option>
                                    {entities.map((en) => <option key={en.id} value={en.id}>{en.name}{en.pii_flag ? ' (PII)' : ''}</option>)}
                                </select>
                            </div>
                            <div className="border border-gray-100 rounded-lg divide-y divide-gray-100">
                                {NDPA_QUESTIONS.map((q) => (
                                    <label key={q.id} className="flex items-center gap-2 px-3 py-2 text-sm">
                                        <input type="checkbox" checked={!!answers[q.id]} onChange={(e) => setAnswers({ ...answers, [q.id]: e.target.checked })} />
                                        <span>{q.text}</span>
                                        <span className="ml-auto text-xs text-gray-500">{q.weight > 0 ? '+' : ''}{q.weight}</span>
                                    </label>
                                ))}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="text-sm">Score: <span className="font-bold">{score.toFixed(1)}</span></div>
                                <div className="text-sm">Band: <StatusBadge status={band === 'high' ? 'critical' : band === 'medium' ? 'moderate' : 'low'} label={band} /></div>
                            </div>
                            <textarea className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Mitigation plan" value={form.data.mitigation_plan} onChange={(e) => form.setData('mitigation_plan', e.target.value)} />
                            <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className="border border-gray-200 rounded-lg px-3 py-2 text-sm">
                                <option value="draft">draft</option><option value="in_review">in_review</option><option value="approved">approved</option><option value="rejected">rejected</option>
                            </select>
                            <div className="flex items-center justify-end gap-2">
                                <button type="button" onClick={() => setShow(false)} className="px-3 py-2 text-sm rounded-lg border border-gray-200">Cancel</button>
                                <button type="submit" disabled={form.processing} className="px-3 py-2 text-sm rounded-lg bg-[#0A1F44] text-white">Save DPIA</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const statusColors = {
    compliant: { bg: 'bg-green-50', text: 'text-green-700', border: 'border-green-200' },
    partially_compliant: { bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
    non_compliant: { bg: 'bg-red-50', text: 'text-red-700', border: 'border-red-200' },
    not_applicable: { bg: 'bg-gray-50', text: 'text-gray-500', border: 'border-gray-200' },
    not_assessed: { bg: 'bg-slate-50', text: 'text-slate-400', border: 'border-slate-200' },
};

function ResultRow({ result, resultStatuses }) {
    const [editing, setEditing] = useState(false);
    const [status, setStatus] = useState(result.status);
    const [findings, setFindings] = useState(result.findings || '');

    const save = () => {
        router.patch(route('compliance-results.update', result.id), { status, findings }, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const sc = statusColors[result.status] || statusColors.not_assessed;

    return (
        <div className={`border rounded-lg p-3 ${sc.border} ${sc.bg} transition-all`}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="font-mono-data text-xs font-semibold text-[#1A365D]">{result.requirement?.requirement_code}</span>
                        <span className="text-sm text-[#2D3748]">{result.requirement?.title}</span>
                    </div>
                </div>

                {!editing ? (
                    <div className="flex items-center gap-2 shrink-0">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${sc.text} ${sc.bg}`}>
                            {result.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}
                        </span>
                        <button onClick={() => setEditing(true)} className="text-xs text-[#1A365D] hover:underline">Assess</button>
                    </div>
                ) : (
                    <div className="flex flex-col gap-2 shrink-0 w-64">
                        <select value={status} onChange={e => setStatus(e.target.value)}
                            className="text-xs border-gray-300 rounded-lg shadow-sm w-full">
                            {resultStatuses.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>)}
                        </select>
                        <textarea value={findings} onChange={e => setFindings(e.target.value)} rows={2}
                            className="text-xs border-gray-300 rounded-lg shadow-sm w-full" placeholder="Findings..." />
                        <div className="flex gap-1">
                            <button onClick={save} className="flex-1 text-xs bg-[#1A365D] text-white py-1 rounded-lg hover:bg-[#2D4A7A]">Save</button>
                            <button onClick={() => setEditing(false)} className="flex-1 text-xs bg-gray-100 text-[#718096] py-1 rounded-lg hover:bg-gray-200">Cancel</button>
                        </div>
                    </div>
                )}
            </div>
            {result.findings && !editing && <p className="text-xs text-[#718096] mt-1">{result.findings}</p>}
        </div>
    );
}

export default function AssessmentShow({ assessment, resultStatuses }) {
    const scoreColor = assessment.overall_score >= 80 ? '#2D7D46' : assessment.overall_score >= 60 ? '#D4AF37' : assessment.overall_score >= 40 ? '#DD6B20' : '#C53030';
    const total = assessment.total_requirements;
    const assessed = total - (assessment.results?.filter(r => r.status === 'not_assessed').length || 0);

    return (
        <AuthenticatedLayout header={assessment.title}>
            <Head title={assessment.title} />

            <div className="grid grid-cols-5 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Score</p>
                    <p className="text-2xl font-bold font-mono-data mt-1" style={{ color: assessment.overall_score !== null ? scoreColor : '#718096' }}>
                        {assessment.overall_score !== null ? `${Math.round(assessment.overall_score)}%` : '--'}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Compliant</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D7D46] mt-1">{assessment.compliant_count}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Partial</p>
                    <p className="text-2xl font-bold font-mono-data text-[#D4AF37] mt-1">{assessment.partial_count}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Non-Compliant</p>
                    <p className="text-2xl font-bold font-mono-data text-[#C53030] mt-1">{assessment.non_compliant_count}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Progress</p>
                    <p className="text-2xl font-bold font-mono-data text-[#2D3748] mt-1">{assessed}/{total}</p>
                    <div className="mt-1 bg-gray-200 rounded-full h-1.5">
                        <div className="bg-[#1A365D] rounded-full h-1.5" style={{ width: `${total > 0 ? (assessed/total)*100 : 0}%` }} />
                    </div>
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 border-b border-gray-100">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-base font-semibold text-[#2D3748]">Requirements Assessment</h3>
                            <p className="text-xs text-[#718096]">{assessment.framework?.name} - Click "Assess" on each requirement to record your findings</p>
                        </div>
                        <span className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">{assessment.framework?.short_name}</span>
                    </div>
                </div>
                <div className="p-4 space-y-2">
                    {assessment.results?.map(result => (
                        <ResultRow key={result.id} result={result} resultStatuses={resultStatuses} />
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

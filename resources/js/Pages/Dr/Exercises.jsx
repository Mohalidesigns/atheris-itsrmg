import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { resultTone } from './RunbookShow';

const fmt = (v) => (v ? new Date(v).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

export default function DrExercises({ exercises = [] }) {
    return (
        <AuthenticatedLayout header="DR Exercises">
            <Head title="DR Exercises" />
            <PageHeader
                breadcrumbs={[{ label: 'Business Continuity' }, { label: 'DR Exercises' }]}
                title="DR Exercise Orchestration"
                subtitle="Every DR exercise with the recovery time achieved against the runbook's target. Record new exercises from the runbook."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Runbook</th>
                            <th className="px-3 py-2">Scheduled</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Participants</th>
                            <th className="px-3 py-2">Recovery vs target</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {exercises.map((e) => (
                            <tr key={e.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{e.runbook ? <Link href={route('dr.runbook.show', e.runbook.id)} className="hover:underline">{e.runbook.name}</Link> : '—'}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{fmt(e.started_at || e.scheduled_at)}</td>
                                <td className="px-3 py-2"><StatusBadge status={e.status === 'completed' ? 'active' : e.status} label={e.status} /></td>
                                <td className="px-3 py-2 text-xs">{(e.participants || []).length}</td>
                                <td className="px-3 py-2 text-xs">{e.rto_achieved_min != null ? <span className={`rounded-full px-2 py-0.5 ${resultTone[e.result] || ''}`}>{e.rto_achieved_min} / {e.rto_target_min ?? e.runbook?.estimated_duration_min} min</span> : <span className="text-[#718096]">not measured</span>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

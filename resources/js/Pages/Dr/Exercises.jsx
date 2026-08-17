import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function DrExercises({ exercises = [] }) {
    return (
        <AuthenticatedLayout header="DR Exercises">
            <Head title="DR Exercises" />
            <PageHeader
                breadcrumbs={[{ label: 'Business Continuity' }, { label: 'DR Exercises' }]}
                title="DR Exercise Orchestration"
                subtitle="Schedule exercises, capture timestamped logs, and collect participant attestations."
            />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Runbook</th>
                            <th className="px-3 py-2">Scheduled</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Participants</th>
                            <th className="px-3 py-2">Evidence</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {exercises.map((e) => (
                            <tr key={e.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{e.runbook?.name}</td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{e.scheduled_at}</td>
                                <td className="px-3 py-2"><StatusBadge status={e.status === 'completed' ? 'active' : e.status} label={e.status} /></td>
                                <td className="px-3 py-2 text-xs">{(e.participants || []).length}</td>
                                <td className="px-3 py-2 text-xs">{(e.evidence_ids || []).length} item(s)</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

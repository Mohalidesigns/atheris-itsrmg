import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function RunbookShow({ runbook, exercises = [] }) {
    return (
        <AuthenticatedLayout header="DR Runbook">
            <Head title={runbook.name} />
            <PageHeader
                breadcrumbs={[{ label: 'Business Continuity' }, { label: 'DR Runbooks', href: route('dr.runbooks') }, { label: runbook.name }]}
                title={runbook.name}
                subtitle={`${runbook.category} · Owner: ${runbook.owner_role || '—'} · Estimated ${runbook.estimated_duration_min} minutes`}
                actions={<Link href={route('dr.runbooks')} className="text-sm text-[#0A1F44] underline">← Runbooks</Link>}
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Steps</h3>
                    <ol className="mt-3 space-y-3">
                        {(runbook.steps || []).map((s, i) => (
                            <li key={i} className="flex items-start gap-3">
                                <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white text-xs flex items-center justify-center shrink-0">{i+1}</span>
                                <div>
                                    <p className="text-sm font-medium text-[#2D3748]">{s.title || s}</p>
                                    {s.description && <p className="text-xs text-[#718096] mt-0.5">{s.description}</p>}
                                </div>
                            </li>
                        ))}
                    </ol>
                </div>
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-fit">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Exercises</h3>
                    {exercises.length === 0 && <p className="text-xs text-[#718096] mt-2">No exercises yet.</p>}
                    <ul className="mt-2 divide-y divide-gray-100">
                        {exercises.map((e) => (
                            <li key={e.id} className="py-2 text-sm">
                                <div className="flex items-center justify-between">
                                    <span>{e.scheduled_at}</span>
                                    <StatusBadge status={e.status === 'completed' ? 'active' : 'draft'} label={e.status} />
                                </div>
                                <p className="text-xs text-[#718096] mt-0.5">{(e.participants || []).length} participants</p>
                            </li>
                        ))}
                    </ul>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

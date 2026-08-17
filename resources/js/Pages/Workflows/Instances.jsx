import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';

export default function WorkflowsInstances({ instances = [] }) {
    return (
        <AuthenticatedLayout header="Workflow Instances">
            <Head title="Workflow Instances" />
            <PageHeader
                breadcrumbs={[{ label: 'Workflow Studio', href: route('workflows.index') }, { label: 'Instances' }]}
                title="Active workflow instances" subtitle="Running workflow executions with task queues and SLA timers." />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Workflow</th>
                            <th className="px-3 py-2">Subject</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2">Started</th>
                            <th className="px-3 py-2">Open Tasks</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {instances.map((i) => (
                            <tr key={i.id}>
                                <td className="px-3 py-2 font-medium text-[#0A1F44]">{i.workflow?.name}</td>
                                <td className="px-3 py-2 text-xs">{i.subject_type} #{i.subject_id}</td>
                                <td className="px-3 py-2"><StatusBadge status={i.status === 'completed' ? 'active' : 'in_progress'} label={i.status} /></td>
                                <td className="px-3 py-2 text-xs text-[#718096]">{i.started_at}</td>
                                <td className="px-3 py-2 text-xs">{(i.tasks || []).filter(t => !t.completed_at).length}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

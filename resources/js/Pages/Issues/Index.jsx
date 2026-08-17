import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import KpiCard from '@/Components/KpiCard';
import { Head, Link } from '@inertiajs/react';

export default function IssuesIndex({ issues = [], counts = {}, status }) {
    const columns = ['open', 'in_progress', 'blocked', 'remediated', 'verified', 'closed'];
    const grouped = columns.reduce((acc, c) => { acc[c] = issues.filter(i => i.status === c); return acc; }, {});
    return (
        <AuthenticatedLayout header="Issues Console">
            <Head title="Issues Console" />
            <PageHeader
                breadcrumbs={[{ label: 'Issues & Remediation' }, { label: 'Console' }]}
                title="Unified Issues Console"
                subtitle="Single CAPA record for audit / control test / risk / incident / vulnerability findings with SLA + escalation."
            />
            <div className="grid grid-cols-2 md:grid-cols-6 gap-2 mb-4">
                {columns.map((c) => (
                    <KpiCard key={c} label={c.replace('_', ' ')} value={counts[c] || 0} tone={c === 'open' || c === 'escalated' ? 'red' : c === 'verified' ? 'green' : 'white'} />
                ))}
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-3">
                {columns.map((c) => (
                    <div key={c} className="bg-[#F7FAFC] rounded-xl p-3 min-h-[200px]">
                        <h3 className="text-xs font-semibold uppercase text-[#718096] mb-2">{c.replace('_', ' ')}</h3>
                        <div className="space-y-2">
                            {(grouped[c] || []).map((i) => (
                                <div key={i.id} className="bg-white rounded-lg shadow-sm p-3 border-l-4"
                                     style={{ borderLeftColor: i.severity === 'critical' ? '#B3261E' : i.severity === 'high' ? '#E5A100' : '#0A1F44' }}>
                                    <p className="text-xs font-medium text-[#2D3748] line-clamp-2">{i.title}</p>
                                    <div className="mt-1 flex items-center justify-between">
                                        <StatusBadge status={i.severity} />
                                        <span className="text-[10px] text-[#718096]">{i.due_date}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

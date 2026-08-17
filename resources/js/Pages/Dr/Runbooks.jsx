import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';

export default function DrRunbooks({ runbooks = [] }) {
    return (
        <AuthenticatedLayout header="DR Runbooks">
            <Head title="DR Runbooks" />
            <PageHeader
                breadcrumbs={[{ label: 'Business Continuity' }, { label: 'DR Runbooks' }]}
                title="DR Runbook Library"
                subtitle="Nigerian DR runbooks: NIBSS failover, switch failover, core-banking DR, ATM reroute, USSD channel failover."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {runbooks.map((r) => (
                    <Link key={r.id} href={route('dr.runbook.show', r.id)}
                        className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:border-[#0A1F44]/30">
                        <h3 className="text-sm font-semibold text-[#0A1F44]">{r.name}</h3>
                        <p className="text-xs text-[#718096] mt-1">{r.category} · ~{r.estimated_duration_min} min</p>
                        <p className="text-xs text-[#2D3748] mt-2 line-clamp-2">{r.description}</p>
                        <div className="mt-3 flex items-center justify-between">
                            <span className="text-xs text-[#718096]">{(r.steps || []).length} steps</span>
                            <span className="text-xs text-[#C9A86A]">{r.exercises_count || 0} exercises</span>
                        </div>
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

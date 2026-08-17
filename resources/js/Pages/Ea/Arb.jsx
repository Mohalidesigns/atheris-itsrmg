import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

const cols = ['submitted', 'in_review', 'approved', 'rejected', 'deferred'];

export default function Arb({ submissions = [], buckets = {} }) {
    const grouped = cols.reduce((a, c) => { a[c] = submissions.filter((s) => s.status === c); return a; }, {});
    return (
        <AuthenticatedLayout header="Architecture Review Board">
            <Head title="ARB" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'ARB' }]}
                title="Architecture Review Board (ARB)"
                subtitle="Submissions with auto-computed blast radius, principle impacts, and digitally-signed decisions."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.arb')} current="ea.arb" />
            <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
                {cols.map((c) => (
                    <KpiCard key={c} label={c.replace('_', ' ')} value={buckets[c] || 0}
                        tone={c === 'approved' ? 'green' : c === 'rejected' ? 'red' : c === 'in_review' ? 'amber' : 'white'} />
                ))}
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-5 gap-3">
                {cols.map((col) => (
                    <div key={col} className="bg-[#F7FAFC] rounded-xl p-3 min-h-[200px]">
                        <h3 className="text-xs font-semibold uppercase text-[#718096] mb-2">{col.replace('_', ' ')}</h3>
                        <div className="space-y-2">
                            {(grouped[col] || []).map((s) => (
                                // The card is the only way into ArbShow, which holds the
                                // blast radius, the principle impacts and the decision
                                // form. As a plain div it left that page reachable only
                                // by typing its URL.
                                <Link
                                    key={s.id}
                                    href={route('ea.arb.show', s.id)}
                                    className="block bg-white rounded-lg shadow-sm p-3 border-l-4 border-[#0A1F44] hover:shadow-md hover:border-[#C9A86A] transition"
                                >
                                    <p className="text-xs font-mono text-[#0A1F44]">{s.code}</p>
                                    <p className="text-xs font-medium text-[#2D3748] mt-1 line-clamp-2">{s.title}</p>
                                    {s.decided_at && (
                                        <p className="text-[10px] text-[#718096] mt-1">Decided: {String(s.decided_at).substr(0, 10)}</p>
                                    )}
                                    {s.digital_signature && <StatusBadge status="active" label="Signed" />}
                                </Link>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

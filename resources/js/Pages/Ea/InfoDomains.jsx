import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function InfoDomains({ domains = [] }) {
    return (
        <AuthenticatedLayout header="Information Domains">
            <Head title="Information Domains" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Information Domains' }]}
                title="Information Domains"
                subtitle="Top-level data domains with owners, NDPA classification and linked logical entities."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.information-domains')} current="ea.information-domains" />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {domains.map((d) => (
                    <Link key={d.id} href={route('ea.logical-entities')}
                        className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 hover:border-[#0A1F44]/30 hover:shadow-md transition block">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="text-xs font-mono text-[#0A1F44]">{d.code}</p>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{d.name}</h3>
                            </div>
                            <StatusBadge status={d.classification === 'Sensitive' || d.classification === 'Personal' ? 'critical' : 'moderate'} label={d.classification} />
                        </div>
                        <p className="text-xs text-[#718096] mt-2 line-clamp-2">{d.description}</p>
                        <p className="text-xs text-[#718096] mt-2">Owner: {d.owner_role || '—'} · {d.entities_count || 0} entities</p>
                    </Link>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';

export default function ThreatAdvisories({ advisories = [] }) {
    return (
        <AuthenticatedLayout header="Threat Advisories">
            <Head title="Threat Advisories" />
            <PageHeader
                breadcrumbs={[{ label: 'Security Operations' }, { label: 'Threat Advisories' }]}
                title="Threat Advisories"
                subtitle="ngCERT, NITDA, CISA and curated RSS feeds with one-click 'Am I affected?' pivot to your asset inventory."
            />
            <div className="space-y-3">
                {advisories.map((a) => (
                    <div key={a.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="px-2 py-0.5 rounded bg-[#0A1F44] text-white text-[10px] uppercase">{a.source}</span>
                                    <span className="text-xs text-[#718096]">{a.advisory_id}</span>
                                    <span className="text-xs text-[#718096]">{a.published_at}</span>
                                </div>
                                <h3 className="text-sm font-semibold text-[#2D3748] mt-1">{a.title}</h3>
                                {a.body && <p className="text-xs text-[#718096] mt-1 line-clamp-2">{a.body}</p>}
                            </div>
                            <button className="text-xs px-3 py-1.5 rounded-lg border border-[#0A1F44]/20 text-[#0A1F44] hover:bg-[#0A1F44]/5 whitespace-nowrap">
                                Am I affected?
                            </button>
                        </div>
                        {(a.cves || []).length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">
                                {a.cves.map((c) => (
                                    <span key={c} className="px-2 py-0.5 rounded bg-[#B3261E]/10 text-[#B3261E] text-[10px] font-mono">{c}</span>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

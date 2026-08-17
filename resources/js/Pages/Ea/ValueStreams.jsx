import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import { Head } from '@inertiajs/react';
import { ArrowRightIcon } from '@heroicons/react/24/outline';

export default function ValueStreams({ streams = [] }) {
    return (
        <AuthenticatedLayout header="Value Streams">
            <Head title="Value Streams" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Value Streams' }]}
                title="Value Streams"
                subtitle="End-to-end value streams with stages, participants and linked capabilities."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.value-streams')} current="ea.value-streams" />
            <div className="space-y-4">
                {streams.length === 0 && <p className="text-sm text-[#718096]">No value streams seeded.</p>}
                {streams.map((s) => (
                    <div key={s.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-xs font-mono text-[#0A1F44]">{s.code}</p>
                                <h3 className="text-sm font-semibold text-[#2D3748]">{s.name}</h3>
                            </div>
                            <span className="text-xs text-[#718096]">{(s.participants || []).length} participants</span>
                        </div>
                        {s.description && <p className="text-xs text-[#718096] mt-2">{s.description}</p>}
                        <div className="mt-4 flex items-center gap-2 overflow-x-auto pb-2">
                            {(s.stages || []).map((stage, i, arr) => (
                                <div key={i} className="flex items-center">
                                    <div className="px-3 py-2 rounded-lg bg-[#0A1F44]/5 border border-[#0A1F44]/10 min-w-[140px] text-center">
                                        <p className="text-[10px] font-semibold text-[#0A1F44] uppercase">{stage.name || 'Stage '+(i+1)}</p>
                                        {stage.duration && <p className="text-[10px] text-[#718096]">~{stage.duration}</p>}
                                    </div>
                                    {i < arr.length - 1 && <ArrowRightIcon className="w-4 h-4 text-[#C9A86A] mx-1" />}
                                </div>
                            ))}
                        </div>
                        {(s.participants || []).length > 0 && (
                            <p className="text-xs text-[#718096] mt-2">Participants: {(s.participants || []).join(' · ')}</p>
                        )}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

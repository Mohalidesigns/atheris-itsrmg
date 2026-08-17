import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import KpiCard from '@/Components/KpiCard';
import { Head } from '@inertiajs/react';

const PHASES = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
const PHASE_NAMES = { A: 'Architecture Vision', B: 'Business Architecture', C: 'Information Systems', D: 'Technology Architecture', E: 'Opportunities & Solutions', F: 'Migration Planning', G: 'Implementation Governance', H: 'Architecture Change Mgmt' };

export default function AdmTracker({ initiatives = [], byPhase = {} }) {
    return (
        <AuthenticatedLayout header="ADM Phase Tracker">
            <Head title="ADM Phase Tracker" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'ADM Phase Tracker' }]}
                title="TOGAF 10 ADM Phase Tracker"
                subtitle="Every initiative flows through A–H. Phase gates are Workflow-Studio-driven approvals."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.adm-tracker')} current="ea.adm-tracker" />
            <div className="grid grid-cols-4 md:grid-cols-8 gap-2 mb-6">
                {PHASES.map((p) => (
                    <KpiCard key={p} label={`Phase ${p}`} value={byPhase[p] || 0} sublabel={PHASE_NAMES[p]} tone={p === 'H' ? 'green' : 'white'} />
                ))}
            </div>
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <table className="min-w-full divide-y divide-gray-100 text-sm">
                    <thead className="bg-[#F7FAFC]">
                        <tr className="text-left text-xs uppercase text-[#718096]">
                            <th className="px-3 py-2">Code</th>
                            <th className="px-3 py-2">Initiative</th>
                            <th className="px-3 py-2">ADM Phase</th>
                            <th className="px-3 py-2">Progress</th>
                            <th className="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {initiatives.map((i) => (
                            <tr key={i.id}>
                                <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{i.code}</td>
                                <td className="px-3 py-2 text-[#2D3748]">{i.name}</td>
                                <td className="px-3 py-2">
                                    <div className="flex items-center gap-1">
                                        {PHASES.map((p) => {
                                            const reached = PHASES.indexOf(i.adm_phase) >= PHASES.indexOf(p);
                                            return (
                                                <span key={p} title={PHASE_NAMES[p]}
                                                    className={`w-5 h-5 rounded-full text-[9px] font-bold flex items-center justify-center
                                                        ${reached ? (i.adm_phase === p ? 'bg-[#C9A86A] text-[#0A1F44]' : 'bg-[#0A1F44] text-white') : 'bg-gray-200 text-gray-500'}`}>
                                                    {p}
                                                </span>
                                            );
                                        })}
                                    </div>
                                </td>
                                <td className="px-3 py-2 text-xs">{i.progress_percent}%</td>
                                <td className="px-3 py-2 text-xs capitalize">{i.status.replace('_', ' ')}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}

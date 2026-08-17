import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { DocumentArrowDownIcon } from '@heroicons/react/24/outline';

const statusTone = { proposed: 'draft', approved: 'moderate', in_flight: 'warn', delivered: 'pass', on_hold: 'amber', cancelled: 'fail' };

function monthOffset(start, date) {
    if (!date) return 0;
    const d = new Date(date);
    return (d.getFullYear() - start.getFullYear()) * 12 + (d.getMonth() - start.getMonth());
}

export default function Roadmap({ initiatives = [], plateaux = [] }) {
    const start = new Date(new Date().setDate(1));
    start.setMonth(start.getMonth() - 1);
    const months = Array.from({ length: 18 }, (_, i) => {
        const d = new Date(start); d.setMonth(d.getMonth() + i);
        return d.toLocaleString('default', { month: 'short', year: '2-digit' });
    });
    return (
        <AuthenticatedLayout header="Roadmap">
            <Head title="Roadmap" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Roadmap' }]}
                title="Roadmap — 18-month horizon"
                subtitle="Transition initiatives grouped by plateau. Gantt-style swim lanes with colour-coded status."
                actions={<button className="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-[#C9A86A] text-[#0A1F44] text-sm font-semibold"><DocumentArrowDownIcon className="w-4 h-4" /> Export PPTX</button>}
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.roadmap')} current="ea.roadmap" />
            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                <div className="min-w-[1400px] p-4">
                    <div className="grid gap-1" style={{ gridTemplateColumns: '260px repeat(18, 1fr)' }}>
                        <div className="text-xs font-semibold text-[#718096] uppercase px-2">Initiative</div>
                        {months.map((m) => <div key={m} className="text-[10px] text-[#718096] text-center">{m}</div>)}
                        {initiatives.map((i) => {
                            const startOff = Math.max(0, monthOffset(start, i.start_date));
                            const endOff = Math.min(18, Math.max(startOff + 1, monthOffset(start, i.target_end_date)));
                            const span = endOff - startOff;
                            return (
                                <>
                                    <div key={i.id} className="text-xs text-[#2D3748] px-2 py-1 flex items-center gap-1 truncate">
                                        <span className="font-mono text-[10px] text-[#C9A86A]">{i.code}</span>
                                        <span className="truncate">{i.name}</span>
                                    </div>
                                    {Array.from({ length: 18 }).map((_, colIdx) => {
                                        if (colIdx < startOff) return <div key={colIdx} />;
                                        if (colIdx === startOff) {
                                            const tone = statusTone[i.status] || 'moderate';
                                            const bg = i.status === 'delivered' ? '#2D7D46' : i.status === 'in_flight' ? '#E5A100' : i.status === 'cancelled' ? '#B3261E' : i.status === 'on_hold' ? '#A0AEC0' : '#0A1F44';
                                            return (
                                                <div key={colIdx} className="h-7 rounded flex items-center px-2 text-[9px] text-white font-semibold truncate"
                                                    style={{ gridColumn: `span ${Math.max(1, span)} / span ${Math.max(1, span)}`, background: bg }}>
                                                    {i.adm_phase} · {i.progress_percent}%
                                                </div>
                                            );
                                        }
                                        return null;
                                    })}
                                </>
                            );
                        })}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

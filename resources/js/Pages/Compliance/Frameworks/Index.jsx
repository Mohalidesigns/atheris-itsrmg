import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { scoreColor, formatDate } from '@/Utils/compliance';

export default function FrameworksIndex({ frameworks }) {
    return (
        <AuthenticatedLayout header="Regulatory Frameworks">
            <Head title="Frameworks" />
            <p className="text-sm text-[#718096] mb-6">{frameworks.length} frameworks loaded. Score is the latest completed assessment; coverage is the share of requirements your controls are mapped to.</p>

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {frameworks.map((fw) => {
                    const p = fw.posture || {};
                    return (
                        <Link key={fw.id} href={route('frameworks.show', fw.id)}
                            className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow group">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-base font-semibold text-[#2D3748] group-hover:text-[#1A365D]">{fw.short_name}</h3>
                                    <p className="text-xs text-[#718096] mt-1">{fw.name}</p>
                                </div>
                                <span className={`text-xs px-2 py-0.5 rounded-full shrink-0 ${fw.jurisdiction === 'NG' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'}`}>
                                    {fw.jurisdiction === 'NG' ? 'Nigerian' : 'Global'}
                                </span>
                            </div>
                            <div className="mt-4 grid grid-cols-2 gap-3">
                                <div>
                                    <p className="text-[10px] uppercase text-[#718096]">Compliance score</p>
                                    <p className="text-xl font-bold font-mono-data" style={{ color: scoreColor(p.score) }}>{p.score !== null && p.score !== undefined ? `${Math.round(p.score)}%` : '--'}</p>
                                    <p className="text-[10px] text-[#A0AEC0]">{p.last_assessed ? `Assessed ${formatDate(p.last_assessed)}` : 'No completed assessment'}</p>
                                </div>
                                <div>
                                    <p className="text-[10px] uppercase text-[#718096]">Control coverage</p>
                                    <p className="text-xl font-bold font-mono-data text-[#1A365D]">{p.coverage_percent ?? 0}%</p>
                                    <div className="h-1 bg-gray-100 rounded-full mt-1"><div className="h-1 rounded-full bg-[#1A365D]" style={{ width: `${p.coverage_percent ?? 0}%` }} /></div>
                                    <p className="text-[10px] text-[#A0AEC0] mt-0.5">{p.covered_requirements ?? 0} / {p.assessable_requirements ?? 0} requirements</p>
                                </div>
                            </div>
                            <div className="mt-3 flex items-center gap-3 text-xs text-[#718096]">
                                <span className="capitalize">{fw.category}</span>
                                {fw.version && <span>v{fw.version}</span>}
                                {fw.issuing_body && <span className="truncate">{fw.issuing_body}</span>}
                                {p.in_progress_id && <span className="ml-auto text-blue-700">Assessment in progress</span>}
                            </div>
                        </Link>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}

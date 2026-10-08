import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline';
import Chip from '@/Components/Compliance/Chip';
import { RESULT_STYLES, COVERAGE, effectivenessOf, scoreColor, formatDate } from '@/Utils/compliance';

function RequirementItem({ req, level = 0, mappedControls, latestResults, expandAll }) {
    const [open, setOpen] = useState(false);
    const children = req.children || [];
    const hasChildren = children.length > 0;
    const isOpen = open || expandAll;
    const controls = mappedControls[req.id] || [];
    const result = latestResults[req.id];

    // Domain roll-up: how many of its requirements have at least one control mapped.
    const covered = hasChildren ? children.filter((c) => (mappedControls[c.id] || []).length).length : null;

    return (
        <div>
            <div className={`flex items-start gap-2 py-2.5 px-3 rounded-lg hover:bg-gray-50 ${level > 0 ? 'ml-6 border-l-2 border-gray-200 pl-4' : ''}`}>
                {hasChildren ? (
                    <button onClick={() => setOpen(!open)} className="mt-0.5 shrink-0">
                        {isOpen ? <ChevronDownIcon className="w-4 h-4 text-[#718096]" /> : <ChevronRightIcon className="w-4 h-4 text-[#718096]" />}
                    </button>
                ) : <div className="w-4 shrink-0" />}
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono-data text-xs font-semibold text-[#1A365D] shrink-0">{req.requirement_code}</span>
                        <span className={`text-sm ${hasChildren ? 'font-semibold text-[#2D3748]' : 'text-[#2D3748]'}`}>{req.title}</span>
                    </div>
                    {req.description && <p className="text-xs text-[#718096] mt-0.5 line-clamp-2">{req.description}</p>}
                    {!hasChildren && (
                        <div className="flex flex-wrap gap-1 mt-1">
                            {controls.length === 0 ? <span className="text-[10px] text-[#B7791F] font-medium">NO CONTROL MAPPED</span> : controls.map((c) => (
                                <Link key={c.id} href={route('controls.show', c.id)} title={`${c.title} — ${effectivenessOf(c.effectiveness).label}, ${COVERAGE[c.coverage]?.label || c.coverage} coverage`}
                                    className="inline-flex items-center gap-1 text-[10px] font-mono-data bg-white border border-gray-200 rounded px-1.5 py-0.5 hover:border-[#1A365D]">
                                    <span className="w-1.5 h-1.5 rounded-full" style={{ background: effectivenessOf(c.effectiveness).color }} />{c.control_code}
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
                {hasChildren ? (
                    <span className="text-xs text-[#718096] bg-gray-100 px-1.5 py-0.5 rounded shrink-0">{covered}/{children.length} mapped</span>
                ) : result ? <Chip className={RESULT_STYLES[result]?.chip}>{RESULT_STYLES[result]?.label}</Chip> : null}
            </div>
            {isOpen && hasChildren && (
                <div className="space-y-0.5">
                    {children.map((child) => <RequirementItem key={child.id} req={child} level={level + 1} mappedControls={mappedControls} latestResults={latestResults} expandAll={expandAll} />)}
                </div>
            )}
        </div>
    );
}

export default function FrameworkShow({ framework, mappedControls, latestAssessment, latestResults, posture }) {
    const { auth } = usePage().props;
    const [expandAll, setExpandAll] = useState(false);
    const p = posture || {};

    return (
        <AuthenticatedLayout header={<div className="flex items-center gap-3"><span className={`text-xs px-2 py-0.5 rounded-full ${framework.jurisdiction === 'NG' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'}`}>{framework.jurisdiction === 'NG' ? 'Nigerian' : 'Global'}</span>{framework.name}</div>}>
            <Head title={framework.short_name} />

            <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Version · issuer</p>
                    <p className="text-sm font-semibold text-[#2D3748] mt-1">{framework.version || '--'}</p>
                    <p className="text-xs text-[#718096]">{framework.issuing_body || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Requirements</p>
                    <p className="text-lg font-semibold font-mono-data text-[#2D3748] mt-1">{p.assessable_requirements}</p>
                    <p className="text-xs text-[#718096]">in {framework.top_level_requirements?.length || 0} domains</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Compliance score</p>
                    <p className="text-lg font-bold font-mono-data mt-1" style={{ color: scoreColor(p.score) }}>{p.score != null ? `${Math.round(p.score)}%` : '--'}</p>
                    <p className="text-xs text-[#718096]">{p.last_assessed ? `Completed ${formatDate(p.last_assessed)}` : 'No completed assessment'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Control coverage</p>
                    <p className="text-lg font-bold font-mono-data text-[#1A365D] mt-1">{p.coverage_percent}%</p>
                    <p className="text-xs text-[#718096]">{p.covered_requirements} requirements mapped</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Results shown from</p>
                    {latestAssessment ? <Link href={route('compliance-assessments.show', latestAssessment.id)} className="text-sm text-[#1A365D] hover:underline block mt-1">{latestAssessment.title}</Link> : <p className="text-sm text-[#718096] mt-1">--</p>}
                    {latestAssessment && <p className="text-xs text-[#718096]">{latestAssessment.status === 'completed' ? 'Completed' : 'In progress'}</p>}
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                {auth.user && (
                    <Link href={route('compliance-assessments.create', { framework: framework.id })} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                        Start {framework.short_name} assessment
                    </Link>
                )}
                {p.in_progress_id && <Link href={route('compliance-assessments.show', p.in_progress_id)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg text-[#4A5568] hover:bg-gray-50">Continue assessment in progress</Link>}
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 border-b border-gray-100 flex items-start justify-between gap-3">
                    <div>
                        <h3 className="text-base font-semibold text-[#2D3748]">Requirements</h3>
                        <p className="text-xs text-[#718096]">{framework.description}</p>
                        <p className="text-[11px] text-[#A0AEC0] mt-1">Chips are your mapped controls (dot = effectiveness). The badge is the requirement's result in the assessment above.</p>
                    </div>
                    <button onClick={() => setExpandAll(!expandAll)} className="text-xs text-[#1A365D] underline shrink-0">{expandAll ? 'Collapse all' : 'Expand all'}</button>
                </div>
                <div className="p-4 space-y-0.5">
                    {framework.top_level_requirements?.map((req) => (
                        <RequirementItem key={req.id} req={req} mappedControls={mappedControls} latestResults={latestResults} expandAll={expandAll} />
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

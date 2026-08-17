import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline';

function RequirementItem({ req, level = 0 }) {
    const [open, setOpen] = useState(false);
    const hasChildren = req.children && req.children.length > 0;

    return (
        <div>
            <div className={`flex items-start gap-2 py-2.5 px-3 rounded-lg hover:bg-gray-50 ${level > 0 ? 'ml-6 border-l-2 border-gray-200 pl-4' : ''}`}>
                {hasChildren ? (
                    <button onClick={() => setOpen(!open)} className="mt-0.5 shrink-0">
                        {open ? <ChevronDownIcon className="w-4 h-4 text-[#718096]" /> : <ChevronRightIcon className="w-4 h-4 text-[#718096]" />}
                    </button>
                ) : <div className="w-4 shrink-0" />}
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="font-mono-data text-xs font-semibold text-[#1A365D] shrink-0">{req.requirement_code}</span>
                        <span className="text-sm text-[#2D3748]">{req.title}</span>
                    </div>
                    {req.description && <p className="text-xs text-[#718096] mt-0.5 line-clamp-2">{req.description}</p>}
                </div>
                {hasChildren && (
                    <span className="text-xs text-[#718096] bg-gray-100 px-1.5 py-0.5 rounded shrink-0">{req.children.length}</span>
                )}
            </div>
            {open && hasChildren && (
                <div className="space-y-0.5">
                    {req.children.map(child => <RequirementItem key={child.id} req={child} level={level + 1} />)}
                </div>
            )}
        </div>
    );
}

export default function FrameworkShow({ framework }) {
    return (
        <AuthenticatedLayout header={<div className="flex items-center gap-3"><span className={`text-xs px-2 py-0.5 rounded-full ${framework.jurisdiction === 'NG' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'}`}>{framework.jurisdiction === 'NG' ? 'Nigerian' : 'Global'}</span>{framework.name}</div>}>
            <Head title={framework.short_name} />

            <div className="grid grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Version</p>
                    <p className="text-lg font-semibold text-[#2D3748] mt-1">{framework.version || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Category</p>
                    <p className="text-lg font-semibold text-[#2D3748] mt-1 capitalize">{framework.category}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Domains</p>
                    <p className="text-lg font-semibold font-mono-data text-[#2D3748] mt-1">{framework.top_level_requirements?.length || 0}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Issuing Body</p>
                    <p className="text-sm font-medium text-[#2D3748] mt-1">{framework.issuing_body || '--'}</p>
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                <Link href={route('compliance-assessments.create')} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                    Start Assessment
                </Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="p-4 border-b border-gray-100">
                    <h3 className="text-base font-semibold text-[#2D3748]">Requirements</h3>
                    <p className="text-xs text-[#718096]">{framework.description}</p>
                </div>
                <div className="p-4 space-y-0.5">
                    {framework.top_level_requirements?.map(req => (
                        <RequirementItem key={req.id} req={req} />
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

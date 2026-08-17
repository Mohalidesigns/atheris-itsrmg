import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { PencilIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';

export default function ShowControl({ control }) {
    const [tab, setTab] = useState('details');

    return (
        <AuthenticatedLayout header={<div className="flex items-center gap-3"><span className="font-mono-data text-sm bg-[#1A365D]/5 text-[#1A365D] px-2 py-0.5 rounded font-semibold">{control.control_code}</span>{control.title}</div>}>
            <Head title={control.control_code} />

            <div className="grid grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Status</p>
                    <p className="text-sm font-medium text-[#2D3748] capitalize mt-1">{control.status}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Effectiveness</p>
                    <p className="text-sm font-medium capitalize mt-1" style={{ color: control.effectiveness === 'effective' ? '#2D7D46' : control.effectiveness === 'ineffective' ? '#C53030' : '#D4AF37' }}>
                        {(control.effectiveness || 'Not Assessed').replace(/_/g, ' ')}
                    </p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Type / Nature</p>
                    <p className="text-sm text-[#2D3748] capitalize mt-1">{control.type || '--'} / {control.nature || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase">Framework Mappings</p>
                    <p className="text-2xl font-bold font-mono-data text-[#1A365D] mt-1">{control.framework_requirements?.length || 0}</p>
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                <Link href={route('controls.edit', control.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]"><PencilIcon className="w-4 h-4" /> Edit</Link>
            </div>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm">
                <div className="border-b border-gray-100 px-4 flex gap-1">
                    {['details', 'mappings', 'risks', 'evidence'].map(t => (
                        <button key={t} onClick={() => setTab(t)} className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${tab === t ? 'border-[#D4AF37] text-[#1A365D]' : 'border-transparent text-[#718096] hover:text-[#2D3748]'}`}>
                            {t.charAt(0).toUpperCase() + t.slice(1)}
                        </button>
                    ))}
                </div>
                <div className="p-5">
                    {tab === 'details' && (
                        <div className="space-y-4">
                            <div><h4 className="text-xs text-[#718096] uppercase mb-1">Description</h4><p className="text-sm text-[#2D3748]">{control.description || 'No description.'}</p></div>
                            <div className="grid grid-cols-4 gap-4">
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Domain</h4><p className="text-sm text-[#2D3748]">{control.domain || '--'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Owner</h4><p className="text-sm text-[#2D3748]">{control.owner?.name || '--'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Frequency</h4><p className="text-sm text-[#2D3748] capitalize">{control.frequency || '--'}</p></div>
                                <div><h4 className="text-xs text-[#718096] uppercase mb-1">Key Control</h4><p className="text-sm text-[#2D3748]">{control.is_key_control ? 'Yes' : 'No'}</p></div>
                            </div>
                        </div>
                    )}
                    {tab === 'mappings' && (
                        <div>{control.framework_requirements?.length > 0 ? (
                            <div className="space-y-2">{control.framework_requirements.map(r => (
                                <div key={r.id} className="flex items-center justify-between py-2 border-b border-gray-50">
                                    <div><span className="font-mono-data text-xs text-[#1A365D] font-semibold">{r.requirement_code}</span><p className="text-sm text-[#2D3748]">{r.title}</p></div>
                                    <span className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">{r.framework?.short_name}</span>
                                </div>
                            ))}</div>
                        ) : <p className="text-sm text-[#718096] py-8 text-center">No framework mappings</p>}</div>
                    )}
                    {tab === 'risks' && (
                        <div>{control.risks?.length > 0 ? (
                            <div className="space-y-2">{control.risks.map(r => (
                                <div key={r.id} className="flex items-center justify-between py-2 border-b border-gray-50">
                                    <div><Link href={route('risks.show', r.id)} className="font-mono-data text-xs text-[#1A365D] font-semibold hover:underline">{r.risk_id_code}</Link><p className="text-sm text-[#2D3748]">{r.title}</p></div>
                                </div>
                            ))}</div>
                        ) : <p className="text-sm text-[#718096] py-8 text-center">No linked risks</p>}</div>
                    )}
                    {tab === 'evidence' && (
                        <div>{control.evidence?.length > 0 ? (
                            <div className="space-y-2">{control.evidence.map(e => (
                                <div key={e.id} className="flex items-center justify-between py-2 border-b border-gray-50">
                                    <div><p className="text-sm font-medium text-[#2D3748]">{e.title}</p><p className="text-xs text-[#718096]">{e.type} - by {e.uploader?.name}</p></div>
                                    <span className={`text-xs px-2 py-0.5 rounded-full ${e.status === 'approved' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'}`}>{e.status}</span>
                                </div>
                            ))}</div>
                        ) : <p className="text-sm text-[#718096] py-8 text-center">No evidence attached</p>}</div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

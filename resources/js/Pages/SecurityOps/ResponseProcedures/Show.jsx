import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { PencilIcon, TrashIcon } from '@heroicons/react/24/outline';

const cap = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

export default function ShowResponseProcedure({ procedure }) {
    const destroy = () => {
        if (confirm('Delete this response procedure? This cannot be undone.')) {
            router.delete(route('response-procedures.destroy', procedure.id));
        }
    };

    return (
        <AuthenticatedLayout header={procedure.title}>
            <Head title={procedure.title} />

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Category</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{cap(procedure.category) || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Version</p>
                    <p className="mt-1 text-sm font-medium font-mono-data text-[#2D3748]">{procedure.version || '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Last Reviewed</p>
                    <p className="mt-1 text-sm font-medium text-[#2D3748]">{procedure.last_reviewed ? new Date(procedure.last_reviewed).toLocaleDateString() : '--'}</p>
                </div>
                <div className="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                    <p className="text-xs text-[#718096] uppercase font-medium">Status</p>
                    <div className="mt-1"><StatusBadge status={procedure.is_active ? 'active' : 'disabled'} label={procedure.is_active ? 'Active' : 'Inactive'} /></div>
                </div>
            </div>

            <div className="flex gap-2 mb-4">
                <Link href={route('response-procedures.edit', procedure.id)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                    <PencilIcon className="w-4 h-4" /> Edit
                </Link>
                <button onClick={destroy}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-[#C53030]/30 rounded-lg hover:bg-[#C53030]/5 text-[#C53030]">
                    <TrashIcon className="w-4 h-4" /> Delete
                </button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div className="px-6 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Response Steps</h3>
                    </div>
                    {(procedure.steps || []).length === 0 ? (
                        <p className="px-6 py-8 text-sm text-[#718096] text-center">No steps defined.</p>
                    ) : (
                        <ol className="px-6 py-4 space-y-3">
                            {procedure.steps.map((step, i) => (
                                <li key={i} className="flex gap-3 text-sm">
                                    <span className="w-6 h-6 rounded-full bg-[#0A1F44] text-white flex items-center justify-center text-xs font-bold shrink-0">{i + 1}</span>
                                    <span className="text-[#2D3748] pt-0.5">{step}</span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                        <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Description</h3>
                        <p className="text-sm text-[#2D3748]">{procedure.description || '--'}</p>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                        <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Applicable Incident Types</h3>
                        <div className="flex flex-wrap gap-2">
                            {(procedure.incident_types || []).length === 0 ? (
                                <span className="text-sm text-[#718096]">--</span>
                            ) : procedure.incident_types.map(t => (
                                <span key={t} className="px-2.5 py-1 rounded-full text-xs bg-[#0A1F44]/5 text-[#0A1F44]">{cap(t)}</span>
                            ))}
                        </div>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                        <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Escalation Contacts</h3>
                        {(procedure.escalation_contacts || []).length === 0 ? (
                            <span className="text-sm text-[#718096]">--</span>
                        ) : (
                            <ul className="space-y-1 text-sm text-[#2D3748] list-disc list-inside">
                                {procedure.escalation_contacts.map((c, i) => <li key={i}>{typeof c === 'string' ? c : JSON.stringify(c)}</li>)}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

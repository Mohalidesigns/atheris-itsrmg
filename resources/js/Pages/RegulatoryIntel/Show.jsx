import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';

export default function RegIntelShow({ circular }) {
    return (
        <AuthenticatedLayout header="Regulatory Circular">
            <Head title={circular.title} />
            <PageHeader
                breadcrumbs={[{ label: 'Regulatory Intelligence', href: route('reg-intel.index') }, { label: circular.title }]}
                title={circular.title}
                subtitle={`${circular.regulator_code} · ${circular.circular_number} · Issued ${circular.issued_at}`}
                actions={<StatusBadge status={circular.status} />}
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                    <h3 className="text-sm font-semibold text-[#2D3748]">LLM Summary</h3>
                    <p className="text-sm text-[#2D3748] mt-2 whitespace-pre-wrap">{circular.llm_summary || '—'}</p>
                    <h3 className="text-sm font-semibold text-[#2D3748] mt-6">Full text</h3>
                    <p className="text-sm text-[#718096] mt-2 whitespace-pre-wrap">{circular.plain_text || 'Full text not extracted yet.'}</p>
                </div>
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-fit">
                    <h3 className="text-sm font-semibold text-[#2D3748]">Impact Assessment</h3>
                    {circular.impact_assessment ? (
                        <ul className="mt-3 space-y-2 text-sm text-[#2D3748]">
                            {Object.entries(circular.impact_assessment).map(([k, v]) => (
                                <li key={k}><span className="font-medium">{k}:</span> {Array.isArray(v) ? v.join(', ') : String(v)}</li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-[#718096] mt-2">Impact analysis pending.</p>
                    )}
                    <Link href={route('reg-intel.index')} className="mt-4 block text-sm text-[#0A1F44] underline">← Back to feed</Link>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}

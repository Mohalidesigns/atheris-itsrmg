import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link } from '@inertiajs/react';
import { NewspaperIcon } from '@heroicons/react/24/outline';

export default function RegIntelIndex({ circulars = [], counts = {}, regulator }) {
    const regulators = ['CBN', 'NDPC', 'SEC', 'NCC', 'NAICOM', 'PENCOM', 'NDIC'];
    return (
        <AuthenticatedLayout header="Regulatory Intelligence">
            <Head title="Regulatory Intelligence" />
            <PageHeader
                breadcrumbs={[{ label: 'Regulatory Intelligence' }]}
                title="Regulatory Intelligence" subtitle="Daily circulars from CBN, NDPC, SEC, NCC, NAICOM, PENCOM, NDIC with LLM-drafted impact analysis." />
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <aside className="bg-white rounded-xl border border-gray-100 p-3 h-fit">
                    <h3 className="text-xs font-semibold uppercase text-[#718096] tracking-wider mb-2">Regulators</h3>
                    <ul className="space-y-1">
                        <li>
                            <Link href={route('reg-intel.index')} className={`block px-2 py-1.5 rounded-md text-sm ${!regulator ? 'bg-[#0A1F44]/5 text-[#0A1F44]' : 'text-[#2D3748] hover:bg-gray-50'}`}>
                                All <span className="float-right text-xs text-[#718096]">{Object.values(counts).reduce((a,b)=>a+b,0) || 0}</span>
                            </Link>
                        </li>
                        {regulators.map((r) => (
                            <li key={r}>
                                <Link href={route('reg-intel.index', { regulator: r })}
                                    className={`block px-2 py-1.5 rounded-md text-sm ${regulator === r ? 'bg-[#0A1F44]/5 text-[#0A1F44] font-medium' : 'text-[#2D3748] hover:bg-gray-50'}`}>
                                    {r} <span className="float-right text-xs text-[#718096]">{counts[r] || 0}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </aside>
                <section className="lg:col-span-4 bg-white rounded-xl border border-gray-100 shadow-sm">
                    <div className="p-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Circular feed</h3>
                        <span className="text-xs text-[#718096]">{circulars.length} items</span>
                    </div>
                    {circulars.length === 0 ? (
                        <div className="p-10 text-center">
                            <NewspaperIcon className="w-10 h-10 text-gray-300 mx-auto" />
                            <p className="mt-2 text-sm text-[#718096]">No circulars yet for this filter.</p>
                        </div>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {circulars.map((c) => (
                                <li key={c.id} className="p-4 hover:bg-gray-50">
                                    <Link href={route('reg-intel.show', c.id)} className="block">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded bg-[#0A1F44] text-white text-[11px] font-medium">{c.regulator_code}</span>
                                                    <span className="text-xs text-[#718096]">{c.circular_number}</span>
                                                    <span className="text-xs text-[#718096]">{c.issued_at}</span>
                                                </div>
                                                <h4 className="text-sm font-semibold text-[#2D3748] mt-1">{c.title}</h4>
                                                {c.llm_summary && <p className="text-xs text-[#718096] mt-1 line-clamp-2">{c.llm_summary}</p>}
                                            </div>
                                            <StatusBadge status={c.status} />
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

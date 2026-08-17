import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link } from '@inertiajs/react';

export default function AucsIndex({ controls = [], domains = [], mappingsByControl = {}, frameworkCodes = [], domain }) {
    return (
        <AuthenticatedLayout header="AUCS Browser">
            <Head title="Atheris Unified Control Set" />
            <PageHeader
                breadcrumbs={[{ label: 'Compliance' }, { label: 'AUCS Browser' }]}
                title="Atheris Unified Control Set (AUCS)"
                subtitle={`${controls.length} canonical controls · mapped to ${frameworkCodes.length} frameworks (ISO 27001:2022, NIST CSF 2.0, CBN RBCSF, NDPA 2023, PCI-DSS 4.0.1)`}
            />
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <aside className="bg-white rounded-xl border border-gray-100 p-3 h-fit">
                    <h3 className="text-xs font-semibold uppercase text-[#718096] tracking-wider mb-2">Domains</h3>
                    <ul className="space-y-1">
                        <li>
                            <Link href={route('aucs.index')}
                                className={`block px-2 py-1.5 rounded-md text-sm ${!domain ? 'bg-[#0A1F44]/5 text-[#0A1F44] font-medium' : 'text-[#2D3748] hover:bg-gray-50'}`}>
                                All domains
                            </Link>
                        </li>
                        {domains.map((d) => (
                            <li key={d}>
                                <Link href={route('aucs.index', { domain: d })}
                                    className={`block px-2 py-1.5 rounded-md text-sm ${domain === d ? 'bg-[#0A1F44]/5 text-[#0A1F44] font-medium' : 'text-[#2D3748] hover:bg-gray-50'}`}>
                                    {d}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </aside>
                <section className="lg:col-span-4 bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Code</th>
                                <th className="px-3 py-2">Title</th>
                                <th className="px-3 py-2">Domain</th>
                                <th className="px-3 py-2">Framework mappings</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {controls.map((c) => {
                                const maps = mappingsByControl[c.id] || [];
                                return (
                                    <tr key={c.id}>
                                        <td className="px-3 py-2 font-mono text-xs text-[#0A1F44]">{c.code}</td>
                                        <td className="px-3 py-2 text-[#2D3748]">{c.title}</td>
                                        <td className="px-3 py-2 text-xs text-[#718096]">{c.domain}</td>
                                        <td className="px-3 py-2">
                                            <div className="flex flex-wrap gap-1">
                                                {maps.length === 0 && <span className="text-xs text-[#718096]">—</span>}
                                                {maps.map((m) => (
                                                    <span key={m.id} className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-[#C9A86A]/15 text-[11px] text-[#0A1F44]">
                                                        {m.clause?.framework_code}: {m.clause?.clause_path}
                                                    </span>
                                                ))}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

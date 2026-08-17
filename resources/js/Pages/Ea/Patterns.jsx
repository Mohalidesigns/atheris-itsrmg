import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EaWorkspaceTabs from '@/Components/Ea/EaWorkspaceTabs';
import { tabsFor } from '@/Config/eaWorkspaces';
import StatusBadge from '@/Components/StatusBadge';
import { Head } from '@inertiajs/react';
import { CubeTransparentIcon } from '@heroicons/react/24/outline';

export default function Patterns({ patterns = [] }) {
    const byCategory = patterns.reduce((a, p) => { (a[p.category] ||= []).push(p); return a; }, {});
    return (
        <AuthenticatedLayout header="Reference Patterns">
            <Head title="Reference Patterns" />
            <PageHeader
                breadcrumbs={[{ label: 'Enterprise Architecture' }, { label: 'Reference Patterns' }]}
                title="Reference Pattern Library"
                subtitle="Versioned, published architectural patterns. Solutions instantiated from a pattern retain a link and receive supersession alerts."
            />
            <EaWorkspaceTabs tabs={tabsFor('ea.patterns')} current="ea.patterns" />
            <div className="space-y-4">
                {Object.entries(byCategory).map(([cat, items]) => (
                    <div key={cat} className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                        <h3 className="p-4 text-sm font-semibold text-[#0A1F44] bg-[#0A1F44]/5 capitalize">{cat}</h3>
                        <ul className="divide-y divide-gray-100">
                            {items.map((p) => (
                                <li key={p.id} className="p-4 flex items-start gap-4">
                                    <CubeTransparentIcon className="w-6 h-6 text-[#C9A86A] shrink-0" />
                                    <div className="flex-1">
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <p className="text-xs font-mono text-[#0A1F44]">{p.code} · v{p.version}</p>
                                                <h4 className="text-sm font-semibold text-[#2D3748]">{p.name}</h4>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <StatusBadge status={p.status === 'published' ? 'active' : 'draft'} label={p.status} />
                                                <span className="text-[10px] text-[#718096]">{p.adoption_count} adopted</span>
                                            </div>
                                        </div>
                                        <div className="mt-2 grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                                            <div><p className="text-[#718096] uppercase font-semibold">Intent</p><p className="text-[#2D3748]">{p.intent}</p></div>
                                            <div><p className="text-[#718096] uppercase font-semibold">Context</p><p className="text-[#2D3748]">{p.context}</p></div>
                                            <div><p className="text-[#718096] uppercase font-semibold">Consequences</p><p className="text-[#2D3748]">{p.consequences}</p></div>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

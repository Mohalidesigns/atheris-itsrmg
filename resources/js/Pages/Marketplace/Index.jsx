import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { StarIcon, CubeIcon } from '@heroicons/react/24/outline';

const fmtNgn = (n) => Number(n) > 0 ? '₦' + Number(n).toLocaleString() : 'Free';

export default function MarketplaceIndex({ items = [] }) {
    const install = (id) => router.post(route('marketplace.install', id));
    return (
        <AuthenticatedLayout header="Content Marketplace">
            <Head title="Atheris Content Marketplace" />
            <PageHeader
                breadcrumbs={[{ label: 'Marketplace' }, { label: 'Content Marketplace' }]}
                title="Atheris Content Marketplace"
                subtitle="Regulator packs, KRI packs, workflow templates, CCM packs, control packs — Cosign-signed bundles."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {items.map((i) => (
                    <div key={i.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                        <div className="flex items-start justify-between">
                            <div className="flex items-start gap-3">
                                <div className="w-10 h-10 rounded-lg bg-[#0A1F44] flex items-center justify-center shrink-0">
                                    <CubeIcon className="w-5 h-5 text-[#C9A86A]" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-semibold text-[#2D3748]">{i.name}</h3>
                                    <p className="text-xs text-[#718096]">{i.publisher} · v{i.version}</p>
                                    <p className="text-[10px] text-[#718096] uppercase mt-0.5">{i.type.replace('_', ' ')}</p>
                                </div>
                            </div>
                            <div className="text-right">
                                <p className="text-sm font-semibold text-[#0A1F44]">{fmtNgn(i.price_ngn)}</p>
                                <p className="text-xs text-[#C9A86A] flex items-center gap-0.5 justify-end"><StarIcon className="w-3 h-3" /> {Number(i.rating).toFixed(1)}</p>
                            </div>
                        </div>
                        {i.description && <p className="text-xs text-[#2D3748] mt-3 line-clamp-3">{i.description}</p>}
                        <div className="mt-3 flex items-center justify-between">
                            <span className="text-[10px] text-[#718096]">{i.installs_count} installs</span>
                            <button onClick={() => install(i.id)}
                                className="px-3 py-1.5 rounded-lg bg-[#0A1F44] text-white text-xs">
                                Install
                            </button>
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

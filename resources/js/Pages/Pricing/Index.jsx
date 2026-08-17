import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head } from '@inertiajs/react';
import { CheckIcon } from '@heroicons/react/24/outline';

const fmtNgn = (n) => '₦' + Number(n).toLocaleString();

export default function PricingIndex({ tiers = [] }) {
    return (
        <AuthenticatedLayout header="Pricing">
            <Head title="Atheris Pricing" />
            <PageHeader
                breadcrumbs={[{ label: 'Marketplace' }, { label: 'Pricing' }]}
                title="Atheris ITSRM&G Pricing"
                subtitle="Three-tier Naira pricebook — CBN-CSAT bundled free from Professional tier upwards."
            />
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {tiers.map((t) => (
                    <div key={t.id} className={`rounded-2xl border shadow-sm p-6 ${t.is_popular ? 'border-[#C9A86A] bg-[#0A1F44] text-white' : 'border-gray-200 bg-white text-[#2D3748]'}`}>
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold">{t.name}</h3>
                            {t.is_popular && <span className="text-xs px-2 py-0.5 rounded-full bg-[#C9A86A] text-[#0A1F44] font-semibold">Most popular</span>}
                        </div>
                        <p className={`text-3xl font-bold mt-4 ${t.is_popular ? 'text-[#C9A86A]' : 'text-[#0A1F44]'}`}>{fmtNgn(t.annual_ngn)}</p>
                        <p className={`text-xs mt-1 ${t.is_popular ? 'text-white/70' : 'text-[#718096]'}`}>per year · ~${Number(t.annual_usd).toLocaleString()} equivalent</p>
                        <ul className={`mt-4 space-y-2 text-sm ${t.is_popular ? 'text-white' : 'text-[#2D3748]'}`}>
                            {(t.features || []).map((f) => (
                                <li key={f} className="flex items-start gap-2"><CheckIcon className="w-4 h-4 mt-0.5" /> <span>{f}</span></li>
                            ))}
                        </ul>
                        <button className={`mt-6 w-full px-4 py-2 rounded-lg font-medium text-sm ${t.is_popular ? 'bg-[#C9A86A] text-[#0A1F44]' : 'bg-[#0A1F44] text-white'}`}>
                            {t.tier === 'enterprise' ? 'Contact sales' : 'Start ' + t.name + ' trial'}
                        </button>
                        {t.limits && (
                            <div className={`mt-4 text-xs space-y-1 border-t pt-3 ${t.is_popular ? 'border-white/20 text-white/80' : 'border-gray-100 text-[#718096]'}`}>
                                {Object.entries(t.limits).map(([k, v]) => (
                                    <div key={k}><span className="uppercase tracking-wide">{k}:</span> {String(v)}</div>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

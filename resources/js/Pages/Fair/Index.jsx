import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router } from '@inertiajs/react';
import { PlayIcon } from '@heroicons/react/24/outline';

const fmt = (n) => '₦' + Math.round(Number(n)).toLocaleString();

export default function FairIndex({ scenarios = [] }) {
    const run = (id) => router.post(route('fair.run', id));
    return (
        <AuthenticatedLayout header="FAIR Quantification">
            <Head title="FAIR Quant" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Risk Management' }, { label: 'FAIR Quantification' }]}
                title="FAIR — Naira Quantification"
                subtitle="10,000-iteration Monte Carlo FAIR with CBN loss-frequency data and NDPC fine-band modelling."
            />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {scenarios.map((s) => {
                    const latest = s.runs?.[0];
                    const hist = latest?.histogram || [];
                    const maxH = Math.max(...hist, 1);
                    return (
                        <div key={s.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-[#2D3748]">{s.name}</h3>
                                    <p className="text-xs text-[#718096] mt-1">{s.loss_event_description}</p>
                                </div>
                                <button onClick={() => run(s.id)} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-[#0A1F44] text-white text-xs">
                                    <PlayIcon className="w-3 h-3" /> Run Monte Carlo
                                </button>
                            </div>
                            {latest && (
                                <>
                                    <div className="grid grid-cols-4 gap-2 mt-4 text-xs">
                                        <div><p className="text-[#718096]">Mean ALE</p><p className="font-semibold text-[#0A1F44]">{fmt(latest.ale_mean_ngn)}</p></div>
                                        <div><p className="text-[#718096]">Median</p><p className="font-semibold text-[#0A1F44]">{fmt(latest.ale_median_ngn)}</p></div>
                                        <div><p className="text-[#718096]">P95</p><p className="font-semibold text-[#B3261E]">{fmt(latest.ale_p95_ngn)}</p></div>
                                        <div><p className="text-[#718096]">P99</p><p className="font-semibold text-[#B3261E]">{fmt(latest.ale_p99_ngn)}</p></div>
                                    </div>
                                    <div className="mt-4 flex items-end gap-1 h-20">
                                        {hist.map((v, i) => (
                                            <div key={i} className="flex-1 bg-[#C9A86A] rounded-t" style={{ height: `${(v / maxH) * 100}%` }} />
                                        ))}
                                    </div>
                                    <p className="text-[10px] text-[#718096] mt-2">{s.iterations?.toLocaleString()} iterations · last ran {latest.ran_at}</p>
                                </>
                            )}
                        </div>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}

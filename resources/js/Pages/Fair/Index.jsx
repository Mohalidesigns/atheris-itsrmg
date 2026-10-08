import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PlayIcon } from '@heroicons/react/24/outline';
import { formatDate } from '@/Utils/risk';

const fmt = (n) => '₦' + Math.round(Number(n || 0)).toLocaleString();
const short = (n) => {
    const v = Number(n || 0);
    if (v >= 1e9) return `₦${(v / 1e9).toFixed(1)}bn`;
    if (v >= 1e6) return `₦${(v / 1e6).toFixed(0)}m`;
    return fmt(v);
};

function ScenarioCard({ s, risks, canEdit }) {
    const [running, setRunning] = useState(false);
    const latest = s.runs?.[0];
    const previous = s.runs?.[1];
    const hist = Array.isArray(latest?.histogram) ? latest.histogram.map(Number) : [];
    const maxH = Math.max(...hist, 1);
    const f = s.frequency_distribution || {};
    const m = s.magnitude_distribution || {};
    const delta = latest && previous ? Number(latest.ale_mean_ngn) - Number(previous.ale_mean_ngn) : null;

    const run = () => router.post(route('fair.run', s.id), {}, {
        preserveScroll: true,
        onStart: () => setRunning(true),
        onFinish: () => setRunning(false),
    });
    const link = (riskId) => router.patch(route('fair.link', s.id), { risk_id: riskId || null }, { preserveScroll: true });

    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold text-[#2D3748]">{s.name}</h3>
                    <p className="text-xs text-[#718096] mt-1">{s.loss_event_description}</p>
                </div>
                {canEdit && (
                    <button onClick={run} disabled={running}
                        className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-[#0A1F44] text-white text-xs shrink-0 disabled:opacity-60">
                        <PlayIcon className="w-3 h-3" /> {running ? 'Simulating…' : 'Run Monte Carlo'}
                    </button>
                )}
            </div>

            <div className="grid grid-cols-3 gap-2 mt-3 text-[11px] bg-gray-50 rounded-lg p-2">
                <div><p className="text-[#718096]">Loss events / yr</p><p className="font-mono-data text-[#2D3748]">{f.min} · {f.most} · {f.max}</p></div>
                <div><p className="text-[#718096]">Loss per event</p><p className="font-mono-data text-[#2D3748]">{short(m.min)} · {short(m.most)} · {short(m.max)}</p></div>
                <div><p className="text-[#718096]">Control effectiveness</p><p className="font-mono-data text-[#2D3748]">{s.control_effectiveness?.percent ?? 0}%</p></div>
            </div>

            <div className="flex items-center gap-2 mt-3 text-xs">
                <span className="text-[#718096]">Quantifies risk:</span>
                {canEdit ? (
                    <select value={s.risk_id || ''} onChange={(e) => link(e.target.value)} className="text-xs border-gray-200 rounded-lg py-1 flex-1 min-w-0">
                        <option value="">Not linked</option>
                        {risks.map((r) => <option key={r.id} value={r.id}>{r.risk_id_code} — {r.title}</option>)}
                    </select>
                ) : <span>{s.risk ? `${s.risk.risk_id_code} — ${s.risk.title}` : 'Not linked'}</span>}
                {s.risk && <Link href={route('risks.show', s.risk.id)} className="text-[#1A365D] underline shrink-0">Open</Link>}
            </div>

            {latest ? (
                <>
                    <div className="grid grid-cols-4 gap-2 mt-4 text-xs">
                        <div>
                            <p className="text-[#718096]">Mean ALE</p>
                            <p className="font-semibold text-[#0A1F44]">{fmt(latest.ale_mean_ngn)}</p>
                            {delta !== null && (
                                <p className={`text-[10px] ${delta > 0 ? 'text-[#C53030]' : 'text-[#2D7D46]'}`}>{delta > 0 ? '▲' : '▼'} {short(Math.abs(delta))} vs previous</p>
                            )}
                        </div>
                        <div><p className="text-[#718096]">Median</p><p className="font-semibold text-[#0A1F44]">{fmt(latest.ale_median_ngn)}</p></div>
                        <div><p className="text-[#718096]">P95</p><p className="font-semibold text-[#B3261E]">{fmt(latest.ale_p95_ngn)}</p></div>
                        <div><p className="text-[#718096]">P99</p><p className="font-semibold text-[#B3261E]">{fmt(latest.ale_p99_ngn)}</p></div>
                    </div>
                    <div className="mt-4 flex items-end gap-1 h-20" aria-label="Annual loss distribution histogram">
                        {hist.map((v, i) => (
                            <div key={i} title={`${v.toLocaleString()} simulated years`} className="flex-1 bg-[#C9A86A] rounded-t" style={{ height: `${Math.max(2, (v / maxH) * 100)}%` }} />
                        ))}
                    </div>
                    <div className="flex justify-between text-[10px] text-[#718096] mt-1">
                        <span>₦0</span><span>Annual loss →</span><span>{short(latest.ale_p99_ngn)}+</span>
                    </div>
                    <p className="text-[10px] text-[#718096] mt-2">
                        {Number(s.iterations || 0).toLocaleString()} iterations · last run {formatDate(latest.ran_at, true)} · {s.runs.length} run{s.runs.length === 1 ? '' : 's'} shown
                    </p>
                </>
            ) : (
                <p className="text-xs text-[#718096] mt-4">Not simulated yet — click <b>Run Monte Carlo</b>.</p>
            )}
        </div>
    );
}

export default function FairIndex({ scenarios = [], risks = [] }) {
    const { auth } = usePage().props;
    const canEdit = (auth?.user?.roles || []).includes('Super Admin') || (auth?.user?.permissions || []).includes('edit risks');

    return (
        <AuthenticatedLayout header="FAIR Quantification">
            <Head title="FAIR Quantification" />
            <PageHeader
                breadcrumbs={[{ label: 'IT Risk Management' }, { label: 'FAIR Quantification' }]}
                title="FAIR — Naira Quantification"
                subtitle="Monte Carlo FAIR: triangular loss-event frequency and magnitude, Poisson event counts, net of control effectiveness. A linked risk's ALE/SLE update with each run."
            />
            {scenarios.length === 0 ? (
                <p className="text-sm text-[#718096] bg-white rounded-xl border border-gray-100 p-8 text-center">No FAIR scenarios defined for this organisation.</p>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {scenarios.map((s) => <ScenarioCard key={s.id} s={s} risks={risks} canEdit={canEdit} />)}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

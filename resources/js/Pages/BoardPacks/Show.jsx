import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { fmtDate, fmtDateTime } from '@/Components/Monitoring/format';

const ngn = (v) => (v === null || v === undefined ? '—' : `₦${(Number(v) / 1e6).toLocaleString('en-GB', { maximumFractionDigits: 1 })}m`);
const tone = { green: '#2D7D46', amber: '#B7791F', red: '#C53030', critical: '#7F1D1D', high: '#C53030', medium: '#B7791F', low: '#2D7D46' };
const ratingBg = { critical: '#7F1D1D', high: '#C53030', medium: '#E5A100', low: '#2D7D46' };

function Stat({ label, value, alert }) {
    return <div className="rounded-lg border border-gray-200 px-3 py-2"><p className={`text-xl font-bold ${alert ? 'text-[#C53030]' : 'text-[#0A1F44]'}`}>{value ?? '—'}</p><p className="text-[11px] text-[#718096]">{label}</p></div>;
}
function Counts({ data = {}, order }) {
    const keys = order || Object.keys(data);
    return <div className="flex flex-wrap gap-2">{keys.map((k) => <span key={k} className="rounded border border-gray-200 px-2 py-1 text-xs capitalize"><b style={{ color: tone[k] }}>{data[k] ?? 0}</b> {String(k).replace(/_/g, ' ')}</span>)}</div>;
}
function Table({ head, rows }) {
    return (
        <table className="min-w-full text-xs">
            <thead><tr className="border-b border-[#0A1F44] text-left text-[10px] uppercase text-[#0A1F44]">{head.map((h) => <th key={h} className="py-1 pr-3">{h}</th>)}</tr></thead>
            <tbody className="divide-y divide-gray-100">{rows.length === 0 ? <tr><td colSpan={head.length} className="py-2 text-[#718096]">None.</td></tr> : rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j} className="py-1 pr-3 align-top">{c}</td>)}</tr>)}</tbody>
        </table>
    );
}

function Section({ s }) {
    const d = s.data;
    if (d === null || d === undefined) return <p className="text-xs text-[#718096]">No data for this section.</p>;
    switch (s.key) {
        case 'exec_summary':
            return (
                <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                    <Stat label="Open risks" value={d.risks_open} /><Stat label="Critical/high inherent" value={d.risks_critical_high} alert={d.risks_critical_high > 0} />
                    <Stat label="Critical/high residual" value={d.residual_critical_high} alert={d.residual_critical_high > 0} /><Stat label="Compliance score" value={d.compliance_score !== null ? `${d.compliance_score}%` : 'n/a'} />
                    <Stat label="KRIs red / amber" value={`${d.kri?.red ?? 0} / ${d.kri?.amber ?? 0}`} alert={d.kri?.red > 0} /><Stat label="KRI breaches without a plan" value={d.kri?.breaches_unacknowledged} alert={d.kri?.breaches_unacknowledged > 0} />
                    <Stat label="CCM tests failing" value={`${d.monitoring?.fail ?? 0} of ${d.monitoring?.tests ?? 0}`} alert={d.monitoring?.fail > 0} /><Stat label="Issues open (overdue)" value={`${d.issues_open} (${d.issues_overdue})`} alert={d.issues_overdue > 0} />
                </div>
            );
        case 'risk_overview':
            return <div className="space-y-2 text-xs"><p className="text-[#718096]">Inherent</p><Counts data={d.by_inherent} order={['critical', 'high', 'medium', 'low']} /><p className="text-[#718096]">Residual</p><Counts data={d.by_residual} order={['critical', 'high', 'medium', 'low']} /><p className="text-[#718096]">Status</p><Counts data={d.by_status} /><p>Treatments overdue: <b className={d.treatments_overdue ? 'text-[#C53030]' : ''}>{d.treatments_overdue}</b></p></div>;
        case 'risk_heat_map':
            return (
                <div className="inline-grid grid-cols-6 gap-1 text-[10px]">
                    {[5, 4, 3, 2, 1].map((l) => [<div key={`l${l}`} className="flex items-center pr-1 text-[#718096]">L{l}</div>, ...[1, 2, 3, 4, 5].map((i) => {
                        const c = d.find((x) => x.likelihood === l && x.impact === i) || {};
                        return <div key={`${l}${i}`} className="flex h-10 w-12 items-center justify-center rounded font-bold text-white" style={{ background: ratingBg[c.rating] || '#CBD5E0', opacity: c.count ? 1 : 0.35 }}>{c.count || ''}</div>;
                    })])}
                    <div />{[1, 2, 3, 4, 5].map((i) => <div key={i} className="text-center text-[#718096]">I{i}</div>)}
                </div>
            );
        case 'top_10_risks':
            return <Table head={['Risk', 'Title', 'Score', 'Inherent', 'Residual', 'Owner']} rows={d.map((r) => [r.code, r.title, r.score, <b style={{ color: tone[r.inherent] }}>{r.inherent}</b>, r.residual || '—', r.owner || '—'])} />;
        case 'kri_panel':
            return <><div className="mb-2"><Counts data={{ green: d.summary.green, amber: d.summary.amber, red: d.summary.red }} /></div><Table head={['KRI', 'Indicator', 'Value', 'Status', 'Period', 'Owner']} rows={d.kris.map((k) => [k.code, k.name, `${Number(k.value).toLocaleString('en-GB')} ${k.unit}`, <b style={{ color: tone[k.status] }}>{k.status}</b>, k.period, k.owner || '—'])} /></>;
        case 'risk_appetite':
            return <Table head={['Category', 'KRIs', 'Worst', 'Outside appetite']} rows={d.map((c) => [c.category, c.kris, <b style={{ color: tone[c.worst] }}>{c.worst}</b>, c.outside.join(', ') || '—'])} />;
        case 'control_status':
            return <div className="grid grid-cols-2 gap-2 md:grid-cols-5"><Stat label="Controls" value={d.total} /><Stat label="Effective" value={d.effective} /><Stat label="Partially effective" value={d.partially_effective} /><Stat label="Ineffective" value={d.ineffective} alert={d.ineffective > 0} /><Stat label="Rating ≠ CCM" value={d.disagreements} alert={d.disagreements > 0} /></div>;
        case 'ccm_results':
            return <><div className="mb-2 grid grid-cols-2 gap-2 md:grid-cols-4"><Stat label="Tests" value={d.summary.tests} /><Stat label="Pass" value={d.summary.pass} /><Stat label="Warn" value={d.summary.warn} /><Stat label="Fail" value={d.summary.fail} alert={d.summary.fail > 0} /></div><Table head={['Test', 'Failing check', 'Last value']} rows={d.failing.map((f) => [f.code, f.title, f.last_value])} /></>;
        case 'cbn_return_readiness':
            return <Table head={['Return', 'Period', 'Regulator', 'Due', 'State', 'Complete', 'Signed']} rows={d.map((r) => [r.name, r.period, r.regulator, <span className={r.overdue ? 'font-bold text-[#C53030]' : ''}>{fmtDate(r.due_date)}{r.overdue ? ' (overdue)' : ''}</span>, String(r.state).replace(/_/g, ' '), r.completeness !== null ? `${r.completeness}%` : '—', r.signed_at ? fmtDate(r.signed_at) : 'no'])} />;
        case 'incident_summary':
        case 'incidents':
            return <><div className="mb-2 grid grid-cols-2 gap-2 md:grid-cols-4"><Stat label="Incidents (90d)" value={d.total} /><Stat label="Still open" value={d.open} alert={d.open > 0} /><Stat label="Data breaches" value={d.breaches} alert={d.breaches > 0} /><Stat label="Critical" value={d.by_severity?.critical ?? 0} alert={d.by_severity?.critical > 0} /></div><Table head={['Incident', 'Title', 'Severity', 'Status', 'Detected']} rows={d.recent.map((i) => [i.incident_id_code, i.title, <b style={{ color: tone[i.severity] }}>{i.severity}</b>, i.status, fmtDate(i.detected_at)])} /></>;
        case 'vulnerabilities':
            return <div className="space-y-2"><div className="grid grid-cols-2 gap-2"><Stat label="Open" value={d.open} /><Stat label="Past due" value={d.overdue} alert={d.overdue > 0} /></div><Counts data={d.by_severity} order={['critical', 'high', 'medium', 'low']} /></div>;
        case 'tprm_summary':
            return <div className="grid grid-cols-2 gap-2 md:grid-cols-4"><Stat label="Vendors" value={d.vendors} /><Stat label="Reviews overdue" value={d.reviews_overdue} alert={d.reviews_overdue > 0} /><Stat label="Rated below C" value={d.below_c} alert={d.below_c > 0} /><Stat label="Breach events (30d)" value={d.breach_events_30d} alert={d.breach_events_30d > 0} /></div>;
        case 'fair_summary':
        case 'fair_ale':
            return <Table head={['Scenario', 'Risk', 'Expected annual loss', '95th percentile', 'Last run']} rows={d.map((f) => [f.name, f.risk || '—', ngn(f.ale_mean_ngn), ngn(f.ale_p95_ngn), fmtDate(f.ran_at)])} />;
        case 'threat_advisories':
            return <Table head={['Source', 'Advisory', 'Title', 'Published']} rows={d.map((a) => [a.source, a.advisory_id, a.title, fmtDate(a.published_at)])} />;
        default:
            return <pre className="text-[10px]">{JSON.stringify(d, null, 2)}</pre>;
    }
}

export default function BoardPackShow({ run, can = {} }) {
    const org = usePage().props.auth?.organization?.name;
    const errors = usePage().props.errors || {};
    const form = useForm({ notes: '' });
    return (
        <AuthenticatedLayout header="Board Packs">
            <Head title={run.title || 'Board pack'} />
            <style>{'@media print { aside, nav, header, .no-print { display: none !important; } main { padding: 0 !important; } .pack-section { break-after: page; box-shadow: none !important; } }'}</style>
            <div className="no-print mb-4 flex flex-wrap items-center gap-2">
                <Link href={route('board-packs.index')} className="text-sm text-[#0A1F44] underline">← Board packs</Link>
                <span className="ml-auto" />
                <button onClick={() => window.print()} className="rounded-lg border border-[#0A1F44] px-3 py-2 text-sm text-[#0A1F44]">Print / save as PDF</button>
                {run.status === 'draft' && can.approve && (
                    <form onSubmit={(e) => { e.preventDefault(); form.post(route('board-packs.approve', run.id), { preserveScroll: true }); }} className="flex items-center gap-2">
                        <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Approval note (optional)" className="rounded-lg border-gray-300 text-sm" />
                        <button disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-sm text-white">Approve for the board</button>
                    </form>
                )}
            </div>
            {errors.pack && <p className="no-print mb-3 rounded-lg bg-[#FEE2E2] px-3 py-2 text-xs text-[#C53030]">{errors.pack}</p>}
            {run.status === 'draft' && !can.approve && <p className="no-print mb-3 rounded-lg bg-[#FEF3C7] px-3 py-2 text-xs text-[#8A6400]">Draft. Someone other than the preparer, with approval rights, approves it for the board.</p>}
            <div className="pack-section mb-4 rounded-xl bg-[#0A1F44] p-8 text-white shadow-sm">
                <p className="text-xs uppercase tracking-widest text-[#C9A86A]">{org}</p>
                <h1 className="mt-2 text-2xl font-bold">{run.title}</h1>
                <p className="mt-1 text-sm text-white/80">{run.template?.name} · reporting period {run.period}</p>
                <div className="mt-6 grid grid-cols-1 gap-2 text-xs text-white/80 md:grid-cols-3">
                    <p>Figures as at <b className="text-white">{fmtDateTime(run.generated_at)}</b></p>
                    <p>Prepared by <b className="text-white">{run.preparer?.name || '—'}</b></p>
                    <p>{run.status === 'approved' ? <>Approved by <b className="text-white">{run.approver?.name}</b> on {fmtDate(run.approved_at)}</> : <b className="text-[#C9A86A]">DRAFT — not yet approved</b>}</p>
                </div>
                {run.notes && <p className="mt-3 text-xs text-white/80">Approval note: {run.notes}</p>}
            </div>
            {(run.content || []).map((s, i) => (
                <section key={`${s.key}${i}`} className="pack-section mb-4 rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
                    <h2 className="mb-3 border-b-2 border-[#C9A86A] pb-1 text-base font-semibold text-[#0A1F44]">{i + 1}. {s.title}</h2>
                    <Section s={s} />
                </section>
            ))}
            {!run.content && <p className="rounded-xl border border-gray-100 bg-white p-6 text-sm text-[#718096]">This pack has no snapshot.</p>}
        </AuthenticatedLayout>
    );
}

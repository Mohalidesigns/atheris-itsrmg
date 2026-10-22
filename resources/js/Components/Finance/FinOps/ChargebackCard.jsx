import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import VoidRunModal from '@/Components/Finance/FinOps/VoidRunModal';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, FIELD, LABEL, finMoney, humanize, lensLabel, localDate } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/** Chargeback journal: preview for a closed period and lens, export (downloads the CSV), list of runs with void. */
export default function ChargebackCard({ chargeback, targetTypes, enabled, can, baseCurrency, money }) {
    const preview = chargeback.preview;
    const [periodId, setPeriodId] = useState(preview?.period_id ?? '');
    const [lens, setLens] = useState(preview?.target_type ?? targetTypes[0]?.value ?? 'business_unit');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [voiding, setVoiding] = useState(null);
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    const load = (p, l) => {
        setPeriodId(p); setLens(l);
        if (p) router.reload({ only: ['chargeback'], data: { cb_period_id: p, cb_target_type: l } });
    };
    const exportRun = async () => {
        setBusy(true); setError(null);
        try {
            const res = await window.axios.post(route('finance.chargeback.store'), { period_id: periodId, target_type: lens }, { responseType: 'blob', headers: { Accept: 'text/csv, application/json' } });
            const name = /filename="?([^";]+)"?/.exec(res.headers['content-disposition'] ?? '')?.[1] ?? 'chargeback.csv';
            const url = URL.createObjectURL(res.data);
            const a = document.createElement('a'); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
            router.reload({ only: ['chargeback', 'flash'] });
        } catch (err) {
            let msg = 'The export failed.';
            try { const body = JSON.parse(await err.response.data.text()); msg = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? msg; } catch { /* keep default */ }
            setError(msg);
        } finally { setBusy(false); }
    };
    const blocked = preview?.blocked_reason;
    return (
        <Card title="Chargeback journal">
            <div className="space-y-3 px-5 py-4">
                {!enabled && <p className="rounded-lg bg-[#FCEBC4] px-3 py-2 text-xs text-[#5F4300]">Showback is switched off for this bank, so chargeback export is refused.</p>}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL} htmlFor="cb-p">Closed period</label><select id="cb-p" value={periodId} onChange={(e) => load(e.target.value, lens)} className={FIELD}><option value="">Select…</option>{chargeback.periods.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.status})</option>)}</select></div>
                    <div><label className={LABEL} htmlFor="cb-l">Lens</label><select id="cb-l" value={lens} onChange={(e) => load(periodId, e.target.value)} className={FIELD}>{targetTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select></div>
                    <div className="flex items-end">{can.run && <button type="button" disabled={busy || !periodId || !enabled || !!blocked || !preview} onClick={exportRun} className={`${BTN_PRIMARY} w-full`}>{busy ? 'Exporting…' : 'Export journal'}</button>}</div>
                </div>
                {chargeback.periods.length === 0 && <p className="text-xs text-[#718096]">No closed periods yet. A journal can only be exported once the period is closed.</p>}
                {preview && <p className="text-xs text-[#4A5568]">Preview: {preview.line_count} lines, debit {fmt(preview.total_debit)}, credit {fmt(preview.total_credit)}.{blocked && <span className="ml-1 text-[#B3261E]">{blocked}</span>}</p>}
                <InputError message={error} />
            </div>
            <Table head={['Run', 'Period', 'Lens', 'Lines', 'Debit', 'Credit', 'Status', 'Exported', '']} empty={chargeback.runs.length === 0 && <Empty>No chargeback runs yet.</Empty>}>
                {chargeback.runs.map((r) => (
                    <tr key={r.id}>
                        <td className="whitespace-nowrap px-4 py-2 font-mono text-xs">{r.run_code}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs">{r.period.name}</td>
                        <td className="px-4 py-2 text-xs">{lensLabel(r.target_type)}</td>
                        <td className="px-4 py-2 text-right tabular-nums">{r.line_count}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(r.total_debit)}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(r.total_credit)}</td>
                        <td className="px-4 py-2"><Chip size="xs" tone={r.status === 'exported' ? 'success' : 'muted'}>{humanize(r.status)}</Chip>{r.void_reason && <span className="block max-w-[14rem] truncate text-[11px] text-[#718096]" title={r.void_reason}>{r.void_reason}</span>}</td>
                        <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{localDate(r.exported_at)}{r.exported_by ? ` · ${typeof r.exported_by === 'string' ? r.exported_by : r.exported_by.name}` : ''}</td>
                        <td className="px-4 py-2"><div className="flex justify-end gap-3 text-xs">
                            {can.run && r.download_url && <a className="text-[#0A1F44] underline" href={r.download_url}>Download</a>}
                            {can.void && r.status === 'exported' && <button type="button" onClick={() => setVoiding(r)} className="text-[#B3261E] underline">Void</button>}
                        </div></td>
                    </tr>
                ))}
            </Table>
            <VoidRunModal run={voiding} onClose={() => setVoiding(null)} />
        </Card>
    );
}

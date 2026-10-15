import Chip from '@/Components/Material/Chip';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import Money from './Money';
import { BUDGET_TONES, formatMoney, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

const CHANGE_TONE = { added: 'success', removed: 'error', changed: 'warning', unchanged: 'muted' };

export default function VersionsDiff({ budget, versions, periods, baseCurrency }) {
    const [picked, setPicked] = useState([]);
    const [diff, setDiff] = useState(null);
    const [err, setErr] = useState(null);
    const [showSame, setShowSame] = useState(false);
    const toggle = (id) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p.slice(-1), id]));
    const compare = () => {
        setErr(null);
        window.axios.get(route('finance.budgets.diff', { budget: picked[0], against: picked[1] })).then((r) => setDiff(r.data))
            .catch((e) => { setDiff(null); setErr(e.response?.data?.errors?.against?.[0] || e.response?.data?.message || 'Could not compare these versions.'); });
    };
    const period = (id) => periods.find((p) => String(p.id) === String(id))?.name ?? id;
    const rows = diff ? diff.lines.filter((r) => showSame || r.change !== 'unchanged') : [];

    return (
        <div className="space-y-4">
            <Card title="Versions" right={<button type="button" disabled={picked.length !== 2} onClick={compare} className="rounded-lg bg-[#0A1F44] px-3 py-1.5 text-xs text-white disabled:opacity-50">Compare selected</button>}>
                <Table head={['Compare', 'Version', 'Status', 'Total (base)', 'Submitted', 'Approved', 'Baselined']} empty={versions.length === 0 && <Empty>No versions.</Empty>}>
                    {versions.map((v) => (
                        <tr key={v.id} className={v.is_current ? 'bg-[#FBF7EE]' : ''}>
                            <td className="px-4 py-2"><input type="checkbox" aria-label={`Select version ${v.version_no}`} checked={picked.includes(v.id)} onChange={() => toggle(v.id)} /></td>
                            <td className="px-4 py-2 text-[#2D3748]">{v.is_current ? `v${v.version_no} (this)` : <Link className="text-[#0A1F44] underline" href={route('finance.budgets.show', { budget: v.id, tab: 'versions' })}>v{v.version_no}</Link>}</td>
                            <td className="px-4 py-2"><Chip size="xs" tone={BUDGET_TONES[v.status] || 'neutral'}>{humanize(v.status)}</Chip></td>
                            <td className="px-4 py-2 text-right"><Money value={v.total_base_amount} currency={baseCurrency} baseCurrency={baseCurrency} /></td>
                            <td className="px-4 py-2 text-xs">{formatDate(v.submitted_at)}</td>
                            <td className="px-4 py-2 text-xs">{formatDate(v.approved_at)}</td>
                            <td className="px-4 py-2 text-xs">{formatDate(v.baselined_at)}</td>
                        </tr>
                    ))}
                </Table>
            </Card>
            {err && <p className="text-sm text-[#B3261E]">{err}</p>}
            {diff && (
                <Card title={`v${diff.a.version_no} to v${diff.b.version_no}`} right={<label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={showSame} onChange={(e) => setShowSame(e.target.checked)} /> Show unchanged</label>}>
                    <div className="grid grid-cols-3 gap-3 px-5 py-3 text-xs">
                        {[['CapEx', diff.totals.capex_delta], ['OpEx', diff.totals.opex_delta], ['Total', diff.totals.base_delta]].map(([l, v]) => (
                            <div key={l}><p className="text-[#718096]">{l} change</p><p className={`text-sm font-semibold ${Number(v) > 0 ? 'text-[#B3261E]' : Number(v) < 0 ? 'text-[#1B5530]' : 'text-[#4A5568]'}`}>{formatMoney(v, baseCurrency, { decimals: 0 })}</p></div>
                        ))}
                    </div>
                    <Table head={['Change', 'Line', 'Δ base', 'Field changes', 'Period changes']} empty={rows.length === 0 && <Empty>No differences.</Empty>}>
                        {rows.map((r) => (
                            <tr key={r.key} className="align-top">
                                <td className="px-4 py-2"><Chip size="xs" tone={CHANGE_TONE[r.change]}>{humanize(r.change)}</Chip></td>
                                <td className="px-4 py-2 text-[#2D3748]">#{(r.b || r.a).line_no} {(r.b || r.a).description}</td>
                                <td className="px-4 py-2 text-right text-xs">{formatMoney(r.base_delta, baseCurrency, { decimals: 0 })}</td>
                                <td className="px-4 py-2 text-xs">{Object.entries(r.fields).map(([f, [x, y]]) => <p key={f}><span className="text-[#718096]">{humanize(f)}:</span> {String(x ?? '—')} → {String(y ?? '—')}</p>)}</td>
                                <td className="px-4 py-2 text-xs">{Object.entries(r.periods).map(([p, d]) => <p key={p}><span className="text-[#718096]">{period(p)}:</span> {Number(d) > 0 ? '+' : ''}{Number(d).toLocaleString()}</p>)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
        </div>
    );
}

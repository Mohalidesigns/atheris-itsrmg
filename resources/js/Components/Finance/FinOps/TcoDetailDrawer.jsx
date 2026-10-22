import Icon from '@/Components/Material/Icon';
import { Empty, Table } from '@/Components/Finance/Settings/ui';
import { finMoney } from '@/Utils/finance';
import { useEffect, useState } from 'react';

/** Side drawer fetching finance.monitor.tco.detail (JSON): direct lines, allocation rules, cloud services, linked contracts. */
export default function TcoDetailDrawer({ item, params, onClose, baseCurrency, money }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    useEffect(() => {
        if (!item) return undefined;
        setData(null); setError(null);
        let live = true;
        window.axios.get(route('finance.monitor.tco.detail'), { params: { ...params, id: item.id } })
            .then((r) => live && setData(r.data)).catch((e) => live && setError(e?.response?.status === 403 ? 'Amounts are hidden for your role.' : 'Could not load the detail.'));
        const esc = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', esc);
        return () => { live = false; window.removeEventListener('keydown', esc); };
    }, [item?.id, params.subject, params.fiscal_year_id, params.basis]); // eslint-disable-line react-hooks/exhaustive-deps
    if (!item) return null;
    const fmt = (v) => finMoney(v, baseCurrency, money, { decimals: 0 });
    const c = data?.components;
    return (
        <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="TCO detail">
            <button type="button" aria-label="Close" onClick={onClose} className="absolute inset-0 bg-black/30" />
            <div className="relative flex h-full w-full max-w-2xl flex-col overflow-y-auto bg-white shadow-xl">
                <div className="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div className="min-w-0"><h3 className="truncate text-base font-semibold text-[#0A1F44]">{data?.label ?? item.label}</h3><p className="text-xs text-[#718096]">What makes up this total, in {baseCurrency}</p></div>
                    <button type="button" onClick={onClose} aria-label="Close drawer" className="text-[#718096]"><Icon name="close" size={22} /></button>
                </div>
                {error && <p className="m-5 rounded-lg bg-[#F9DEDC] px-3 py-2 text-sm text-[#8C1D18]">{error}</p>}
                {!data && !error && <p className="px-5 py-10 text-center text-sm text-[#718096]">Loading…</p>}
                {c && (
                    <div className="space-y-5 py-4">
                        <section><h4 className="px-5 pb-1 text-sm font-semibold text-[#2D3748]">Direct (tagged budget lines and actuals)</h4>
                            <Table head={['Line', 'Cost centre', 'Amount']} empty={c.direct.length === 0 && <Empty>None.</Empty>}>{c.direct.map((d) => <tr key={d.line_id}><td className="px-4 py-2 text-xs">{d.label}</td><td className="px-4 py-2 text-xs text-[#718096]">{d.cost_centre}</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(d.amount)}</td></tr>)}</Table></section>
                        <section><h4 className="px-5 pb-1 text-sm font-semibold text-[#2D3748]">Allocated (rules)</h4>
                            <Table head={['Rule', 'Source', 'Amount']} empty={c.allocated.length === 0 && <Empty>None.</Empty>}>{c.allocated.map((d, i) => <tr key={i}><td className="px-4 py-2 font-mono text-xs">{d.rule_code}</td><td className="px-4 py-2 text-xs">{d.source_label}</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(d.amount)}</td></tr>)}</Table></section>
                        <section><h4 className="px-5 pb-1 text-sm font-semibold text-[#2D3748]">Cloud (FinOps)</h4>
                            <Table head={['Service', 'Account', 'Cost']} empty={c.cloud.length === 0 && <Empty>None.</Empty>}>{c.cloud.map((d, i) => <tr key={i}><td className="px-4 py-2 text-xs">{d.service}</td><td className="px-4 py-2 text-xs text-[#718096]">{d.account}</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(d.cost)}</td></tr>)}</Table></section>
                        <section><h4 className="px-5 pb-1 text-sm font-semibold text-[#2D3748]">Contracts (annualised, memo)</h4>
                            <Table head={['Contract', 'Annualised']} empty={c.contracts.length === 0 && <Empty>None.</Empty>}>{c.contracts.map((d) => <tr key={d.id}><td className="px-4 py-2 text-xs"><span className="font-mono">{d.contract_code}</span> {d.title}</td><td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{fmt(d.annualised)}</td></tr>)}</Table></section>
                    </div>
                )}
            </div>
        </div>
    );
}

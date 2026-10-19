import Chip from '@/Components/Material/Chip';
import { Card, Empty } from '@/Components/Finance/Settings/ui';
import { finMoney, fmtPct } from '@/Utils/finance';

/** FinOps total against the cloud actuals in the ledger (the FinOps sub-ledger is a memo and never posts to actuals). */
export default function LedgerReconCard({ ledger, baseCurrency, money }) {
    if (!ledger?.period) return <Card title="Ledger reconciliation"><Empty>No period in range to reconcile.</Empty></Card>;
    const gap = Number(ledger.gap);
    const tone = !money ? 'muted' : Math.abs(Number(ledger.gap_pct)) <= 5 ? 'success' : 'warning';
    return (
        <Card title={`Ledger reconciliation, ${ledger.period.name}`} right={<Chip size="xs" tone={tone}>{money ? `Gap ${fmtPct(ledger.gap_pct, { sign: true })}` : 'Hidden'}</Chip>}>
            <dl className="grid grid-cols-3 gap-3 px-5 py-4 text-sm">
                <div><dt className="text-[11px] text-[#718096]">FinOps total</dt><dd className="font-semibold tabular-nums">{finMoney(ledger.finops_total, baseCurrency, money, { decimals: 0 })}</dd></div>
                <div><dt className="text-[11px] text-[#718096]">Cloud actuals in ledger</dt><dd className="font-semibold tabular-nums">{finMoney(ledger.cloud_ledger_actual, baseCurrency, money, { decimals: 0 })}</dd></div>
                <div><dt className="text-[11px] text-[#718096]">Gap</dt><dd className={`font-semibold tabular-nums ${money && gap !== 0 ? 'text-[#8A6400]' : ''}`}>{finMoney(ledger.gap, baseCurrency, money, { decimals: 0 })}</dd></div>
            </dl>
            <p className="border-t border-gray-100 px-5 py-2 text-[11px] text-[#718096]">FinOps cost is a memo sub-ledger. The book of record stays invoices and the general ledger, so a gap is a timing or coverage difference to investigate, not an error to post.</p>
        </Card>
    );
}

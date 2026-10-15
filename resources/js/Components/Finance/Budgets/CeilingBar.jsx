import { formatMoney, pctOf } from '@/Utils/finance';

function Bar({ label, requested, ceiling, over, baseCurrency }) {
    const pct = ceiling ? pctOf(requested, ceiling) : null;
    return (
        <div>
            <div className="flex items-baseline justify-between text-xs">
                <span className="font-medium text-[#4A5568]">{label}</span>
                <span className={over ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}>
                    {formatMoney(requested, baseCurrency, { decimals: 0 })}{ceiling ? ` of ${formatMoney(ceiling, baseCurrency, { decimals: 0 })}` : ' (no ceiling)'}
                </span>
            </div>
            <div className="mt-1 h-2 overflow-hidden rounded bg-gray-100" role="progressbar" aria-label={`${label} against ceiling`} aria-valuenow={pct === null ? undefined : Math.round(pct)} aria-valuemin={0} aria-valuemax={100}>
                <div className={`h-full ${over ? 'bg-[#B3261E]' : pct !== null && pct > 90 ? 'bg-[#E5A100]' : 'bg-[#2D7D46]'}`} style={{ width: `${pct === null ? 0 : Math.min(100, pct)}%` }} />
            </div>
        </div>
    );
}

/** CapEx / OpEx requested against the cycle ceilings that apply to the budget (sums the matching targets). */
export default function CeilingBar({ ceiling, baseCurrency }) {
    if (!ceiling || ceiling.targets.length === 0) return <p className="text-xs text-[#718096]">No ceilings set for this budget's cycle.</p>;
    const add = (key) => ceiling.targets.reduce((a, t) => (t[key] === null || t[key] === undefined ? a : a + Number(t[key])), 0);
    const has = (key) => ceiling.targets.some((t) => t[key] !== null && t[key] !== undefined);
    return (
        <div className="space-y-2">
            {has('capex_ceiling') && <Bar label="CapEx" requested={add('capex_requested')} ceiling={add('capex_ceiling')} over={ceiling.targets.some((t) => t.capex_over)} baseCurrency={baseCurrency} />}
            {has('opex_ceiling') && <Bar label="OpEx" requested={add('opex_requested')} ceiling={add('opex_ceiling')} over={ceiling.targets.some((t) => t.opex_over)} baseCurrency={baseCurrency} />}
            {ceiling.over && <p className="text-xs text-[#B3261E]">{ceiling.over_line_ids.length} line(s) push a ceiling over its limit.</p>}
        </div>
    );
}

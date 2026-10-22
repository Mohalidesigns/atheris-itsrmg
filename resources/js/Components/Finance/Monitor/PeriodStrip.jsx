import Chip from '@/Components/Material/Chip';
import { PERIOD_TONES, humanize } from '@/Utils/finance';

/** Horizontal strip of the fiscal year's periods; the selected one is outlined in gold. */
export default function PeriodStrip({ periods, selectedId, onSelect }) {
    return (
        <div className="mb-4 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Periods">
            {periods.map((p) => (
                <button key={p.id} type="button" role="tab" aria-selected={p.id === selectedId} onClick={() => onSelect(p)}
                    className={`flex min-w-[6.5rem] shrink-0 flex-col items-start gap-1 rounded-lg border px-3 py-2 text-left ${p.id === selectedId ? 'border-[#C9A86A] bg-[#FFF8E6] shadow-sm' : 'border-gray-200 bg-white hover:bg-gray-50'}`}>
                    <span className="text-sm font-semibold text-[#0A1F44]">{p.name}</span>
                    <Chip size="xs" tone={PERIOD_TONES[p.status] || 'neutral'}>{humanize(p.status)}</Chip>
                </button>
            ))}
        </div>
    );
}

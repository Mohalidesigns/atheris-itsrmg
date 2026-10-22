import Icon from '@/Components/Material/Icon';
import { STAGES, stageLabel } from '@/Utils/finance';

/** Idea → closed progress. Cancelled shows a single terminal state. */
export default function StageStepper({ stage }) {
    if (stage === 'cancelled') {
        return <p className="flex items-center gap-1 text-sm text-[#74777F]"><Icon name="cancel" size={18} /> Cancelled</p>;
    }
    const at = STAGES.indexOf(stage);
    return (
        <ol className="flex items-center gap-1 overflow-x-auto whitespace-nowrap py-1" aria-label="Investment stage">
            {STAGES.map((s, i) => {
                const done = i < at; const current = i === at;
                return (
                    <li key={s} aria-current={current ? 'step' : undefined} className="flex items-center gap-1">
                        <span className={`flex items-center gap-1 rounded-lg px-2 py-1 text-xs ${current ? 'bg-[#0A1F44] font-semibold text-white' : done ? 'bg-[#DCEFE1] text-[#1B5530]' : 'bg-gray-100 text-[#74777F]'}`}>
                            {done && <Icon name="check" size={14} />}{stageLabel(s)}
                        </span>
                        {i < STAGES.length - 1 && <span className="h-px w-3 bg-gray-300" />}
                    </li>
                );
            })}
        </ol>
    );
}

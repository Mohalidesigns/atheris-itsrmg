import { CAL_COLORS } from '@/Utils/finance';

const FALLBACK = [{ color: 'green', label: 'Active' }, { color: 'amber', label: 'In renewal window' }, { color: 'red', label: 'Notice deadline passed or overdue' }, { color: 'grey', label: 'Closed' }, { color: 'blue', label: 'Milestone' }];

export default function Legend({ legend }) {
    const items = legend && legend.length ? legend : FALLBACK;
    return (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[#4A5568]" aria-label="Legend">
            {items.map((l) => <li key={l.color} className="flex items-center gap-1.5"><span className={`h-2.5 w-2.5 rounded-sm ${CAL_COLORS[l.color]?.dot ?? 'bg-gray-400'}`} />{l.label}</li>)}
        </ul>
    );
}

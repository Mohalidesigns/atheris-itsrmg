import Icon from '@/Components/Material/Icon';
import { checklistIcon } from '@/Utils/finance';
import { Card } from '@/Components/Finance/Settings/ui';
import { TONES } from '@/Components/Material/Chip';

/** Close checklist: one row per item with a status icon, count, detail and a fix link. */
export default function ChecklistPanel({ checklist, errors, actions }) {
    const blockers = checklist.blockers ?? [];
    const serverErrors = errors ? String(errors).split(/\n|;\s/).filter(Boolean) : [];
    return (
        <Card title="Close checklist" right={actions}>
            <ul className="divide-y divide-gray-50">
                {checklist.items.map((it) => {
                    const [icon, tone] = checklistIcon(it.status);
                    return (
                        <li key={it.key} className="flex items-start gap-3 px-5 py-3">
                            <Icon name={icon} size={22} fill style={{ color: TONES[tone].rail }} label={it.status.replace('_', '/')} />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-[#2D3748]">{it.label}{it.count > 0 && <span className="ml-2 rounded bg-gray-100 px-1.5 text-xs tabular-nums text-[#4A5568]">{it.count}</span>}</p>
                                {it.detail && <p className="text-xs text-[#718096]">{it.detail}</p>}
                            </div>
                            {it.href && it.status !== 'pass' && it.status !== 'n_a' && <a href={it.href} className="shrink-0 text-xs text-[#0A1F44] underline">Fix</a>}
                        </li>
                    );
                })}
            </ul>
            {(blockers.length > 0 || serverErrors.length > 0) && (
                <div className="border-t border-gray-100 bg-[#FDF3F2] px-5 py-3" role="alert">
                    <p className="text-xs font-semibold text-[#8C1D18]">{serverErrors.length > 0 ? 'The close was refused:' : 'The period cannot close yet:'}</p>
                    <ul className="mt-1 list-disc space-y-0.5 pl-5 text-xs text-[#8C1D18]">{(serverErrors.length > 0 ? serverErrors : blockers).map((b, i) => <li key={i}>{b}</li>)}</ul>
                </div>
            )}
        </Card>
    );
}

import Chip from '@/Components/Material/Chip';
import Icon from '@/Components/Material/Icon';
import { BTN_SECONDARY, MASK, VARIANCE_STATUS_TONES, fmtPct, humanize, moneyOrMask, PERIOD_TONES } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CommentThread from './CommentThread';
import SeverityChip from './SeverityChip';

/** One variance note: figures, explanation, corrective action, issue link, actions and the comment thread. */
export default function VarianceCard({ note, baseCurrency, money, onExplain, onRaiseIssue }) {
    const [open, setOpen] = useState(false);
    const fmt = (v) => moneyOrMask(v, baseCurrency, money, { decimals: 0 });
    const over = Number(note.variance) > 0 || note.direction === 'over';
    const acknowledge = () => router.post(route('finance.monitor.variances.acknowledge', note.id), {}, { preserveScroll: true });
    return (
        <article className="rounded-lg border border-gray-200 bg-white" aria-label={`${note.line.label}, ${note.period.name}`}>
            <div className="flex flex-wrap items-start justify-between gap-2 px-4 py-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold text-[#0A1F44]">{note.line.label}</h3>
                    <p className="text-xs text-[#718096]">{note.cost_centre.code} {note.cost_centre.name} · {note.period.name} · owner {note.owner?.name ?? 'unassigned'}</p>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    <SeverityChip severity={note.severity} direction={note.direction} />
                    {note.peak_severity !== note.severity && <Chip size="xs" tone="muted" title="Highest severity reached">Peak {note.peak_severity}</Chip>}
                    <Chip size="xs" tone={VARIANCE_STATUS_TONES[note.status] || 'neutral'}>{humanize(note.status)}</Chip>
                    {!note.is_breaching && note.status !== 'cleared' && <Chip size="xs" tone="success">No longer breaching</Chip>}
                </div>
            </div>
            <dl className="grid grid-cols-2 gap-3 border-t border-gray-100 px-4 py-3 text-sm sm:grid-cols-5">
                {[['Budget (YTD)', fmt(note.budget)], ['Actual (YTD)', fmt(note.actual)], ['Variance', fmt(note.variance)], ['Variance %', money ? fmtPct(note.variance_pct, { sign: true }) : MASK], ['This period', fmt(note.period_variance)]].map(([k, v], i) => (
                    <div key={k}><dt className="text-[11px] text-[#718096]">{k}</dt><dd className={`font-semibold tabular-nums ${i >= 2 && i <= 3 && money ? (over ? 'text-[#B3261E]' : 'text-[#2D7D46]') : ''}`}>{v}</dd></div>
                ))}
            </dl>
            <div className="space-y-2 border-t border-gray-100 px-4 py-3 text-sm">
                {note.explanation ? (
                    <>
                        <div><p className="text-[11px] text-[#718096]">Explanation{note.author ? ` · ${note.author}` : ''}{note.explained_at ? `, ${formatDate(note.explained_at)}` : ''}</p><p className="whitespace-pre-line text-[#2D3748]">{note.explanation}</p></div>
                        {note.corrective_action && <div><p className="text-[11px] text-[#718096]">Corrective action</p><p className="whitespace-pre-line text-[#2D3748]">{note.corrective_action}</p></div>}
                    </>
                ) : <p className="flex items-center gap-1 text-[#8A6400]"><Icon name="edit_note" size={16} />Awaiting an explanation.</p>}
                {note.acknowledged_by && <p className="text-xs text-[#4A5568]">Acknowledged by {note.acknowledged_by}{note.acknowledged_at ? `, ${formatDate(note.acknowledged_at)}` : ''}</p>}
                {note.issue && <p className="text-xs"><Icon name="flag" size={14} className="mr-1 inline" /><Link href={note.issue.url} className="text-[#0A1F44] underline">Issue {note.issue.code}</Link></p>}
            </div>
            <div className="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-2">
                {note.can.explain && <button type="button" onClick={() => onExplain(note)} className={BTN_SECONDARY}>{note.explanation ? 'Edit explanation' : 'Explain'}</button>}
                {note.can.acknowledge && note.status === 'explained' && <button type="button" onClick={acknowledge} className="rounded-lg bg-[#0A1F44] px-4 py-2 text-sm text-white">Acknowledge</button>}
                {note.can.raiseIssue && !note.issue && <button type="button" onClick={() => onRaiseIssue(note)} className={BTN_SECONDARY}>Raise issue</button>}
                <Chip size="xs" tone={PERIOD_TONES[note.period.status] || 'neutral'}>Period {note.period.status}</Chip>
                <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="ml-auto flex items-center gap-1 text-xs text-[#0A1F44] underline">
                    <Icon name={open ? 'expand_less' : 'forum'} size={16} />{open ? 'Hide' : 'Comments'} ({note.comments.length})
                </button>
            </div>
            {open && <div className="border-t border-gray-100 px-4 py-3"><CommentThread note={note} /></div>}
        </article>
    );
}

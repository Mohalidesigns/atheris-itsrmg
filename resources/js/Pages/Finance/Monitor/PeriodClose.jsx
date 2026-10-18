import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ChecklistPanel from '@/Components/Finance/Monitor/ChecklistPanel';
import MonitorNav from '@/Components/Finance/Monitor/MonitorNav';
import PeriodStrip from '@/Components/Finance/Monitor/PeriodStrip';
import ReopenModal from '@/Components/Finance/Monitor/ReopenModal';
import ReopenPanel from '@/Components/Finance/Monitor/ReopenPanel';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import Chip from '@/Components/Material/Chip';
import { BTN_PRIMARY, BTN_SECONDARY, PERIOD_TONES, humanize, localDate } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const REOPEN_TONES = { submitted: 'warning', approved: 'success', rejected: 'error', withdrawn: 'muted' };

export default function PeriodClose(props) {
    const { fiscalYears, filters, periods, selected, can, errors = {} } = props;
    const [reopening, setReopening] = useState(false);
    const [busy, setBusy] = useState(false);
    const go = (params) => router.get(route('finance.monitor.period-close'), Object.fromEntries(Object.entries(params).filter(([, v]) => v)), { preserveScroll: true });
    const sel = selected?.period;
    const selPeriod = periods.find((p) => p.id === sel?.id);
    const act = (name, msg) => { if (!msg || confirm(msg)) { setBusy(true); router.post(route(name, sel.id), {}, { preserveScroll: true, onFinish: () => setBusy(false) }); } };
    const checklist = selected?.checklist;

    const actions = selPeriod && (
        <>
            <button type="button" onClick={() => router.reload({ only: ['selected'], preserveScroll: true })} className={BTN_SECONDARY}>Re-check</button>
            {selPeriod.can?.close && <button type="button" disabled={busy} onClick={() => act('finance.periods.evaluate-variances')} className={BTN_SECONDARY}>Evaluate variances</button>}
            {selPeriod.can?.close && (
                <button type="button" disabled={busy || !checklist?.can_close} onClick={() => act('finance.periods.close', `Close ${sel.name}? The checklist is re-run first.`)} className={BTN_PRIMARY}
                    title={!checklist?.can_close ? 'Resolve the blockers first' : undefined}>Close period</button>
            )}
            {selPeriod.can?.lock && <button type="button" disabled={busy} onClick={() => act('finance.periods.lock', `Lock ${sel.name}? A locked period can only be reopened through approval.`)} className={BTN_SECONDARY}>Lock</button>}
            {selPeriod.can?.reopen && <button type="button" onClick={() => setReopening(true)} className={BTN_SECONDARY}>Request reopen</button>}
        </>
    );

    return (
        <AuthenticatedLayout header="Period Close">
            <Head title="Period Close" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budget Monitor', href: route('finance.monitor.bva') }, { label: 'Period Close' }]} title="Period Close"
                subtitle="Checklist, close, lock and approved reopening of accounting periods" />
            <MonitorNav active="period-close" params={{ fiscal_year_id: filters.fiscal_year_id }} />
            <div className="mb-4 max-w-xs">
                <SelectFilter id="pc-fy" label="Fiscal year" value={filters.fiscal_year_id} onChange={(e) => go({ fiscal_year_id: e.target.value })} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
            </div>

            {periods.length === 0 ? <Card><Empty>No periods for this fiscal year.</Empty></Card> : (
                <>
                    <PeriodStrip periods={periods} selectedId={sel?.id} onSelect={(p) => go({ fiscal_year_id: filters.fiscal_year_id, period_id: p.id })} />
                    {selected && (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="text-base font-semibold text-[#0A1F44]">{sel.name}</h3>
                                <Chip tone={PERIOD_TONES[sel.status] || 'neutral'}>{humanize(sel.status)}</Chip>
                                <span className="text-xs text-[#718096]">Ends {localDate(sel.ends_on)}</span>
                            </div>
                            {sel.status === 'open' || checklist?.items?.length ? <ChecklistPanel checklist={checklist} errors={errors.checklist} actions={actions} /> : null}
                            {(errors.workflow || errors.approval) && !selected.reopen && <p className="rounded-lg bg-[#F9DEDC] px-3 py-2 text-xs text-[#8C1D18]">{errors.workflow || errors.approval}</p>}
                            <ReopenPanel reopen={selected.reopen} errors={errors} />
                            <Card title="Close history">
                                <Table head={['Status', 'Closed', 'Locked', 'Reopen', 'Reason', 'Decided']} empty={selected.history.length === 0 && <Empty>This period has not been closed yet.</Empty>}>
                                    {selected.history.map((h) => (
                                        <tr key={h.id}>
                                            <td className="px-4 py-2"><Chip size="xs" tone={h.status === 'reopened' ? 'tertiary' : h.status === 'locked' ? 'warning' : 'neutral'}>{humanize(h.status)}</Chip></td>
                                            <td className="whitespace-nowrap px-4 py-2 text-xs">{h.closed_at ? `${localDate(h.closed_at)}${h.closed_by ? ` · ${h.closed_by}` : ''}` : '—'}</td>
                                            <td className="whitespace-nowrap px-4 py-2 text-xs">{h.locked_at ? localDate(h.locked_at) : '—'}</td>
                                            <td className="px-4 py-2">{h.reopen_status ? <Chip size="xs" tone={REOPEN_TONES[h.reopen_status] || 'neutral'}>{humanize(h.reopen_status)}</Chip> : '—'}</td>
                                            <td className="min-w-[14rem] px-4 py-2 text-xs text-[#4A5568]">{h.reopen_reason ?? '—'}{h.requested_by && <span className="block text-[#718096]">{h.requested_by}{h.requested_at ? `, ${localDate(h.requested_at)}` : ''}</span>}</td>
                                            <td className="px-4 py-2 text-xs text-[#4A5568]">{h.decided_by ? `${h.decided_by}${h.decided_at ? `, ${localDate(h.decided_at)}` : ''}` : '—'}{h.rejection_reason && <span className="block text-[#8C1D18]">{h.rejection_reason}</span>}</td>
                                        </tr>
                                    ))}
                                </Table>
                            </Card>
                        </div>
                    )}
                </>
            )}
            <ReopenModal period={sel} show={reopening} onClose={() => setReopening(false)} />
        </AuthenticatedLayout>
    );
}

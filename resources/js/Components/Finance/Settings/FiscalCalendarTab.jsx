import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { FIELD, FY_TONES, LABEL, PERIOD_TONES, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AddButton, Card, Check, Empty, FormModal, Table } from './ui';

export default function FiscalCalendarTab({ fiscalYears, options, can }) {
    const [mode, setMode] = useState(null); // null | 'new' | fiscal year row
    const form = useForm({ year_label: '', starts_on: '', period_scheme: 'monthly', status: 'planning', is_current: false });
    const openNew = () => { form.setData({ year_label: '', starts_on: '', period_scheme: 'monthly', status: 'planning', is_current: false }); form.clearErrors(); setMode('new'); };
    const openEdit = (fy) => { form.setData({ year_label: fy.year_label, status: fy.status, is_current: fy.is_current }); form.clearErrors(); setMode(fy); };
    const close = () => { setMode(null); form.clearErrors(); };
    const submit = () => {
        const opts = { preserveScroll: true, onSuccess: close };
        if (mode === 'new') form.post(route('finance.fiscal-years.store'), opts);
        else form.put(route('finance.fiscal-years.update', mode.id), opts);
    };
    const act = (name, p, msg) => { if (confirm(msg)) router.post(route(name, p.id), {}, { preserveScroll: true }); };
    const isNew = mode === 'new';

    return (
        <div className="space-y-4">
            <div className="flex justify-end">{can.manage && <AddButton onClick={openNew}>Add fiscal year</AddButton>}</div>
            {fiscalYears.length === 0 && <Card><Empty>No fiscal years yet. Add the current year and next year's planning year.</Empty></Card>}
            {fiscalYears.map((fy) => (
                <Card key={fy.id}
                    title={<span className="flex flex-wrap items-center gap-2">{fy.year_label}
                        <Chip tone={FY_TONES[fy.status] || 'neutral'}>{humanize(fy.status)}</Chip>
                        {fy.is_current && <Chip tone="tertiary">Current</Chip>}
                        <span className="text-xs font-normal text-[#718096]">{formatDate(fy.starts_on)} to {formatDate(fy.ends_on)} · {fy.period_scheme === '4-4-5' ? '4-4-5 weeks' : humanize(fy.period_scheme)}</span></span>}
                    right={can.manage && (
                        <>
                            <button type="button" onClick={() => openEdit(fy)} className="text-xs text-[#0A1F44] underline">Edit</button>
                            {fy.status === 'planning' && <button type="button" onClick={() => { if (confirm(`Delete ${fy.year_label}?`)) router.delete(route('finance.fiscal-years.destroy', fy.id), { preserveScroll: true }); }} className="text-xs text-[#B3261E] underline">Delete</button>}
                        </>
                    )}>
                    <Table head={['#', 'Period', 'Dates', 'Status', 'Closed', 'Locked', '']}>
                        {fy.periods.map((p) => (
                            <tr key={p.id}>
                                <td className="px-4 py-2 font-mono text-xs text-[#718096]">{p.sequence}</td>
                                <td className="whitespace-nowrap px-4 py-2 font-medium text-[#2D3748]">{p.name}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{formatDate(p.starts_on)} to {formatDate(p.ends_on)}</td>
                                <td className="px-4 py-2"><Chip tone={PERIOD_TONES[p.status] || 'neutral'}>{humanize(p.status)}</Chip></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{p.closed_at ? `${formatDate(p.closed_at)}${p.closed_by ? ` · ${p.closed_by.name}` : ''}` : '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{p.locked_at ? `${formatDate(p.locked_at)}${p.locked_by ? ` · ${p.locked_by.name}` : ''}` : '—'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right text-xs">
                                    {can.closePeriods && p.status === 'open' && <button type="button" onClick={() => act('finance.periods.close', p, `Close period ${p.name}? Earlier periods must already be closed.`)} className="text-[#0A1F44] underline">Close</button>}
                                    {can.closePeriods && p.status === 'closed' && <button type="button" onClick={() => act('finance.periods.lock', p, `Lock period ${p.name}? Locked periods cannot be reopened here.`)} className="text-[#0A1F44] underline">Lock</button>}
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            ))}

            <FormModal show={mode !== null} onClose={close} onSubmit={submit} processing={form.processing} title={isNew ? 'Add fiscal year' : 'Edit fiscal year'}>
                <div><label className={LABEL}>Year label</label><input value={form.data.year_label} onChange={(e) => form.setData('year_label', e.target.value)} className={FIELD} placeholder="FY2027" /><InputError message={form.errors.year_label} /></div>
                {isNew && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div><label className={LABEL}>Starts on</label><input type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} className={FIELD} /><InputError message={form.errors.starts_on} /></div>
                        <div><label className={LABEL}>Period scheme</label><select value={form.data.period_scheme} onChange={(e) => form.setData('period_scheme', e.target.value)} className={FIELD}>{options.periodSchemes.map((s) => <option key={s} value={s}>{s === '4-4-5' ? '4-4-5 weeks' : humanize(s)}</option>)}</select><InputError message={form.errors.period_scheme} /></div>
                    </div>
                )}
                <div><label className={LABEL}>Status</label>
                    <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={FIELD}>
                        {(isNew ? ['planning', 'open'] : options.fiscalYearStatuses).map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select><InputError message={form.errors.status} /></div>
                <Check label="Current fiscal year (requires status open)" checked={form.data.is_current} onChange={(v) => form.setData('is_current', v)} />
                <InputError message={form.errors.is_current} />
                {!isNew && <p className="text-xs text-[#718096]">Dates and period scheme cannot change after creation.</p>}
            </FormModal>
        </div>
    );
}

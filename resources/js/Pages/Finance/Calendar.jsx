import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ContractsNav from '@/Components/Finance/Contracts/ContractsNav';
import CalendarFilters from '@/Components/Finance/Calendar/CalendarFilters';
import Legend from '@/Components/Finance/Calendar/Legend';
import MonthGrid from '@/Components/Finance/Calendar/MonthGrid';
import PassedPanel from '@/Components/Finance/Calendar/PassedPanel';
import QuarterView from '@/Components/Finance/Calendar/QuarterView';
import SubscribeLink from '@/Components/Finance/Calendar/SubscribeLink';
import TimelineGantt from '@/Components/Finance/Calendar/TimelineGantt';
import YearView from '@/Components/Finance/Calendar/YearView';
import { VIEWS, periodLabel, stepDate } from '@/Components/Finance/Calendar/calendarUtils';
import { BTN_SECONDARY, isoDate } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';

export default function Calendar({ view, anchor, from, to, today, events, timeline, passed, filters, options, legend, feed, exportUrls, can, errors }) {
    const go = (next = {}) => {
        const params = { view, date: anchor, ...filters, ...next };
        const clean = {};
        Object.entries(params).forEach(([k, v]) => {
            if (v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)) return;
            clean[k] = v;
        });
        router.get(route('finance.calendar'), clean, { preserveState: true, preserveScroll: true, replace: true });
    };
    const onFilters = (next) => go({ ...next });
    const empty = events.length === 0 && (view !== 'timeline' || timeline.length === 0);

    return (
        <AuthenticatedLayout header="IT Finance Calendar">
            <Head title="IT Finance Calendar" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Calendar' }]} title="IT Finance Calendar"
                subtitle="Contract commencements, renewal windows, notice deadlines and ends, with budget cycle and period milestones"
                actions={<div className="flex flex-wrap items-center justify-end gap-2 print:hidden">
                    {can.export && (
                        <details className="relative">
                            <summary className={`${BTN_SECONDARY} cursor-pointer list-none`}>Export</summary>
                            <div className="absolute right-0 z-20 mt-1 w-36 rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-md">
                                <a className="block px-3 py-1.5 hover:bg-gray-50" href={exportUrls.csv}>CSV</a>
                                <a className="block px-3 py-1.5 hover:bg-gray-50" href={exportUrls.pdf}>PDF</a>
                            </div>
                        </details>
                    )}
                    <button type="button" onClick={() => window.print()} className={BTN_SECONDARY}>Print</button>
                </div>} />
            <ContractsNav active="calendar" />
            {errors?.format && <p className="mb-3 text-sm text-[#B3261E]">{errors.format}</p>}

            <div className="mb-3 flex flex-wrap items-center justify-between gap-3 print:hidden">
                <div className="inline-flex overflow-hidden rounded-lg border border-gray-200" role="group" aria-label="Calendar view">
                    {VIEWS.map(([k, l]) => (
                        <button key={k} type="button" aria-pressed={view === k} onClick={() => go({ view: k })}
                            className={`px-3 py-1.5 text-sm ${view === k ? 'bg-[#0A1F44] text-white' : 'bg-white text-[#2D3748] hover:bg-gray-50'}`}>{l}</button>
                    ))}
                </div>
                <div className="flex items-center gap-2">
                    <button type="button" aria-label="Previous period" onClick={() => go({ date: stepDate(view, anchor, -1) })} className={BTN_SECONDARY}>Prev</button>
                    <button type="button" onClick={() => go({ date: today })} className={BTN_SECONDARY}>Today</button>
                    <button type="button" aria-label="Next period" onClick={() => go({ date: stepDate(view, anchor, 1) })} className={BTN_SECONDARY}>Next</button>
                </div>
            </div>
            <p className="mb-2 text-sm font-semibold text-[#0A1F44]" aria-live="polite">{periodLabel(view, anchor)}</p>

            <CalendarFilters filters={{ ...filters, sources: filters.sources ?? [] }} options={options} onChange={onFilters} />

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-4">
                <div className="order-2 space-y-3 xl:order-1 xl:col-span-3">
                    <Legend legend={legend} />
                    {view === 'month' && <MonthGrid anchor={anchor} today={today} events={events} />}
                    {view === 'quarter' && <QuarterView anchor={anchor} today={today} events={events} />}
                    {view === 'year' && <YearView anchor={anchor} today={today} events={events} onPickMonth={(y, m) => go({ view: 'month', date: isoDate(new Date(y, m, 1)) })} />}
                    {view === 'timeline' && <TimelineGantt from={from} to={to} today={today} rows={timeline} />}
                    {empty && <p className="text-center text-sm text-[#718096]">No events in this range{Object.values(filters).some((v) => v && (!Array.isArray(v) || v.length)) ? ' for the selected filters' : ''}.</p>}
                </div>
                <div className="order-1 space-y-4 xl:order-2">
                    <PassedPanel passed={passed} />
                    <div className="print:hidden"><SubscribeLink feed={feed} /></div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

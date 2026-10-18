import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import ForecastTable from '@/Components/Finance/Monitor/ForecastTable';
import MonitorNav from '@/Components/Finance/Monitor/MonitorNav';
import OverrideModal, { ClearOverrideModal } from '@/Components/Finance/Monitor/OverrideModal';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card } from '@/Components/Finance/Settings/ui';
import { compactMoney } from '@/Components/Finance/Budgets/Charts';
import { humanize } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export default function Forecast(props) {
    const { filters, fiscalYears, costCentres, settingsMethod, asOfPeriod, periodsElapsed, periodsTotal, lines, totals, history = [], can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [f, setF] = useState({ ...filters });
    const [overriding, setOverriding] = useState(null);
    const [clearing, setClearing] = useState(null);
    const go = (next) => {
        setF(next);
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        router.get(route('finance.monitor.forecast'), clean, { preserveScroll: true, preserveState: true, replace: true });
    };
    const set = (k) => (e) => go({ ...f, [k]: e.target.value });
    const data = history.map((h) => ({ name: h.name, eac: Number(h.eac_total) }));

    return (
        <AuthenticatedLayout header="Forecast">
            <Head title="Forecast" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budget Monitor', href: route('finance.monitor.bva') }, { label: 'Forecast' }]} title="Forecast"
                subtitle={`Estimate at completion by line. Default method: ${humanize(settingsMethod ?? 'remaining_budget')}. ${asOfPeriod ? `As of ${asOfPeriod.name} (${periodsElapsed} of ${periodsTotal} periods elapsed).` : 'No period has elapsed yet, so the forecast equals the budget.'}`} />
            <MonitorNav active="forecast" params={{ fiscal_year_id: filters.fiscal_year_id }} />
            <FiltersBar cols="lg:grid-cols-4">
                <SelectFilter id="f-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="f-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
            </FiltersBar>
            {errors?.reason && <p className="mb-3 rounded-lg bg-[#F9DEDC] px-3 py-2 text-xs text-[#8C1D18]">{errors.reason}</p>}

            <ForecastTable lines={lines} totals={totals} baseCurrency={baseCurrency} money={money} onOverride={setOverriding} onClear={setClearing} />

            <div className="mt-4">
                <Card title="EAC history" right={<span className="text-xs text-[#718096]">Snapshot taken at each period close</span>}>
                    {!money ? <p className="px-5 py-10 text-center text-sm text-[#718096]">Amounts are hidden for your role.</p>
                        : data.length < 2 ? <p className="px-5 py-10 text-center text-sm text-[#718096]">History appears once two periods have been closed.</p> : (
                            <div className="h-56 p-4" role="img" aria-label="Estimate at completion by period close">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={data} margin={{ left: 8, right: 8, top: 8 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                                        <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                                        <YAxis tick={{ fontSize: 11 }} tickFormatter={(v) => compactMoney(v, baseCurrency)} width={70} domain={['auto', 'auto']} />
                                        <Tooltip formatter={(v) => new Intl.NumberFormat(undefined, { style: 'currency', currency: baseCurrency, maximumFractionDigits: 0 }).format(v)} />
                                        <Line type="monotone" dataKey="eac" name="EAC" stroke="#0A1F44" strokeWidth={2} dot={{ r: 3 }} />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                </Card>
            </div>

            <OverrideModal line={overriding} baseCurrency={baseCurrency} onClose={() => setOverriding(null)} />
            <ClearOverrideModal line={clearing} errors={errors ?? {}} onClose={() => setClearing(null)} />
        </AuthenticatedLayout>
    );
}

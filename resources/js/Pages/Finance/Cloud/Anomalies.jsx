import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import Chip from '@/Components/Material/Chip';
import AnomalyActionModal from '@/Components/Finance/FinOps/AnomalyActionModal';
import CloudNav from '@/Components/Finance/FinOps/CloudNav';
import DeviationChip from '@/Components/Finance/FinOps/DeviationChip';
import ProviderChip from '@/Components/Finance/FinOps/ProviderChip';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { anomalyTone, cleanQuery, finMoney, humanize, localDate } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const STATUS_TABS = [['open', 'Open'], ['acknowledged', 'Acknowledged'], ['resolved', 'Resolved'], ['false_positive', 'False positive']];

export default function Anomalies(props) {
    const { filters, counts = {}, anomalies, thresholds, accounts = [], options, can } = props;
    const money = can?.viewAmounts !== false;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const [action, setAction] = useState(null);
    const go = (next) => router.get(route('finance.cloud.anomalies.index'), cleanQuery(next), { preserveScroll: true, preserveState: true, replace: true });
    const rows = anomalies?.data ?? [];
    const links = Array.isArray(anomalies?.meta?.links) ? anomalies.meta.links : Array.isArray(anomalies?.links) ? anomalies.links : null;
    const status = filters.status || 'open';

    return (
        <AuthenticatedLayout header="Cloud Anomalies">
            <Head title="Cloud Anomalies" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Cloud & FinOps', href: route('finance.cloud.costs') }, { label: 'Cloud Anomalies' }]} title="Cloud Anomalies"
                subtitle="Days where cloud cost jumped past the rolling seven-day average or the same weekday a week earlier" />
            <CloudNav active="anomalies" />

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200" role="tablist">
                {STATUS_TABS.map(([k, label]) => (
                    <button key={k} type="button" role="tab" aria-selected={status === k} onClick={() => go({ ...filters, status: k })}
                        className={`flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm ${status === k ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096]'}`}>
                        {label}<Chip size="xs" tone={k === 'open' && counts[k] > 0 ? 'error' : 'muted'}>{counts[k] ?? 0}</Chip>
                    </button>
                ))}
            </div>

            <FiltersBar cols="lg:grid-cols-4">
                <SelectFilter id="an-ac" label="Account" value={filters.account_id} onChange={(e) => go({ ...filters, status, account_id: e.target.value })}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</SelectFilter>
                <SelectFilter id="an-sc" label="Scope" value={filters.scope} onChange={(e) => go({ ...filters, status, scope: e.target.value })}>{options.scopes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
            </FiltersBar>

            <Card title="Anomalies">
                <Table head={['Detected on', 'Account', 'Scope', 'Baseline', 'Observed', 'Deviation', 'Method', 'Status', 'Owner', '']} empty={rows.length === 0 && <Empty>No {humanize(status).toLowerCase()} anomalies.</Empty>}>
                    {rows.map((a) => (
                        <tr key={a.id}>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{localDate(a.detected_on)}</td>
                            <td className="px-4 py-2 text-xs"><span className="block text-[#2D3748]">{a.cloud_account.name}</span><ProviderChip provider={a.cloud_account.provider} /></td>
                            <td className="min-w-[8rem] px-4 py-2 text-[#2D3748]"><span className="text-[11px] text-[#718096]">{humanize(a.scope)}</span><span className="block">{a.scope_label}</span></td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums" title={money ? `${finMoney(a.baseline_base_cost, baseCurrency, money, { decimals: 0 })} base` : undefined}>{finMoney(a.baseline_cost, a.currency, money, { decimals: 0 })}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums" title={money ? `${finMoney(a.observed_base_cost, baseCurrency, money, { decimals: 0 })} base` : undefined}>{finMoney(a.observed_cost, a.currency, money, { decimals: 0 })}</td>
                            <td className="px-4 py-2"><DeviationChip value={a.deviation_pct} /></td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{a.method === 'week_over_week' ? 'Week on week' : 'Rolling 7 days'}</td>
                            <td className="px-4 py-2"><Chip size="xs" tone={anomalyTone(a.status)}>{humanize(a.status)}</Chip>{a.note && <span className="mt-0.5 block max-w-[14rem] truncate text-[11px] text-[#718096]" title={a.note}>{a.note}</span>}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{a.owner?.name ?? 'Unassigned'}</td>
                            <td className="px-4 py-2">
                                <div className="flex justify-end gap-3 text-xs">
                                    {a.can?.acknowledge && <button type="button" onClick={() => setAction({ kind: 'acknowledge', anomaly: a })} className="text-[#0A1F44] underline">Acknowledge</button>}
                                    {a.can?.resolve && <button type="button" onClick={() => setAction({ kind: 'resolve', anomaly: a })} className="text-[#0A1F44] underline">Resolve</button>}
                                    {a.can?.dismiss && <button type="button" onClick={() => setAction({ kind: 'dismiss', anomaly: a })} className="text-[#B3261E] underline">Dismiss</button>}
                                </div>
                            </td>
                        </tr>
                    ))}
                </Table>
                <p className="border-t border-gray-100 px-5 py-2 text-[11px] text-[#718096]">
                    Flagged when the day exceeds its baseline by {thresholds.pct}% and by at least {finMoney(thresholds.abs_floor, baseCurrency, money, { decimals: 0 })}, looking back {thresholds.lookback_days} days against a {thresholds.baseline_days}-day baseline. Detection runs in the billing currency, so exchange-rate moves are not anomalies. Change the thresholds under Settings, FinOps & showback.
                </p>
            </Card>
            <Pagination links={links} />
            <AnomalyActionModal action={action} onClose={() => setAction(null)} />
        </AuthenticatedLayout>
    );
}

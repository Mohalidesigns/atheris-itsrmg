import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import AllocationFormModal from '@/Components/Finance/FinOps/AllocationFormModal';
import CoverageBar from '@/Components/Finance/FinOps/CoverageBar';
import RunAllocationsModal from '@/Components/Finance/FinOps/RunAllocationsModal';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import FiltersBar from '@/Components/Finance/Monitor/FiltersBar';
import { SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { AddButton, Card, Empty, FormModal, Table } from '@/Components/Finance/Settings/ui';
import { BTN_SECONDARY, FIELD, LABEL, cleanQuery, finMoney, fmtPct, humanize, lensLabel, localDate, sourceLabel } from '@/Utils/finance';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function EndRuleModal({ rule, onClose }) {
    const form = useForm({ effective_to: '' });
    if (!rule) return null;
    const submit = () => form.post(route('finance.allocations.end', rule.id), { preserveScroll: true, onSuccess: onClose });
    return (
        <FormModal show onClose={onClose} onSubmit={submit} processing={form.processing || !form.data.effective_to} title={`End ${rule.allocation_code}`} submitLabel="End rule">
            <div><label className={LABEL} htmlFor="er-d">Last effective date</label><input id="er-d" type="date" min={rule.effective_from} value={form.data.effective_to} onChange={(e) => form.setData('effective_to', e.target.value)} className={FIELD} /><InputError message={form.errors.effective_to} /></div>
            <p className="text-xs text-[#718096]">Results already computed stay. The rule stops applying after this date.</p>
        </FormModal>
    );
}

export default function Allocations(props) {
    const { filters, rules = [], coverage = [], runs = [], sources, targets, drivers = [], fiscalYears = [], periods = [], firstOpenDate, options, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [editing, setEditing] = useState(null); // null | 'new' | rule
    const [ending, setEnding] = useState(null);
    const [running, setRunning] = useState(false);
    const go = (next) => router.get(route('finance.allocations.index'), cleanQuery(next), { preserveScroll: true, preserveState: true, replace: true });
    const set = (k) => (e) => go({ ...filters, [k]: e.target.value });
    const remove = (r) => confirm(`Delete ${r.allocation_code}?`) && router.delete(route('finance.allocations.destroy', r.id), { preserveScroll: true });
    const lenses = [...new Set(rules.map((r) => r.target_type))];
    const methodText = (r) => (r.method === 'percentage' ? fmtPct(r.percentage) : r.method === 'driver' ? `${r.driver?.code ?? 'Driver'} ${r.driver_quantity ?? ''}${r.driver?.unit ? ` ${r.driver.unit}` : ''}` : '100%');

    return (
        <AuthenticatedLayout header="Allocations">
            <Head title="Allocations" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: 'Allocations' }]} title="Allocations"
                subtitle="Rules that spread IT cost to business units, services, applications, investments and entities for showback"
                actions={<>
                    {can.run && <button type="button" onClick={() => setRunning(true)} className={BTN_SECONDARY}>Run allocations</button>}
                    {can.manage && <AddButton onClick={() => setEditing('new')}>Add rule</AddButton>}
                </>} />
            <BudgetsNav active="allocations" />
            {['allocation', 'run', 'rules'].map((k) => errors?.[k] && <p key={k} role="alert" className="mb-2 text-sm text-[#B3261E]">{errors[k]}</p>)}

            <FiltersBar cols="lg:grid-cols-5">
                <SelectFilter id="al-fy" label="Fiscal year" value={filters.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                <SelectFilter id="al-tt" label="Lens" value={filters.target_type} onChange={set('target_type')}>{options.targetTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="al-st" label="Source" value={filters.source_type} onChange={set('source_type')}>{options.sourceTypes.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="al-me" label="Method" value={filters.method} onChange={set('method')}>{options.methods.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</SelectFilter>
                <SelectFilter id="al-ac" label="Status" value={filters.active} onChange={set('active')}><option value="1">Active</option><option value="0">Ended</option></SelectFilter>
            </FiltersBar>

            <div className="space-y-4">
                {lenses.length === 0 ? <Card><Empty>No allocation rules match. Add a rule to spread a cost centre, category, budget line or contract to a lens.</Empty></Card> : lenses.map((lens) => (
                    <Card key={lens} title={lensLabel(lens)}>
                        <Table head={['Rule', 'Source', 'Target', 'Method', 'Share', 'Effective', '']}>
                            {rules.filter((r) => r.target_type === lens).map((r) => (
                                <tr key={r.id} className={r.is_active ? '' : 'opacity-50'}>
                                    <td className="min-w-[10rem] px-4 py-2"><span className="font-mono text-xs text-[#718096]">{r.allocation_code}</span><span className="block text-[#2D3748]">{r.name}</span></td>
                                    <td className="px-4 py-2 text-xs"><span className="text-[#718096]">{sourceLabel(r.source_type)}</span><span className="block text-[#2D3748]">{r.source?.label}</span></td>
                                    <td className="px-4 py-2 text-xs text-[#2D3748]">{r.target?.label}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-xs"><Chip size="xs" tone="primary">{humanize(r.method)}</Chip><span className="ml-1 text-[#4A5568]">{methodText(r)}</span></td>
                                    <td className="px-4 py-2 text-right tabular-nums">{fmtPct(r.share_pct)}</td>
                                    <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{localDate(r.effective_from)} to {r.effective_to ? localDate(r.effective_to) : 'open'}</td>
                                    <td className="px-4 py-2">
                                        <div className="flex justify-end gap-3 text-xs">
                                            {r.can?.edit && <button type="button" onClick={() => setEditing(r)} className="text-[#0A1F44] underline">Edit</button>}
                                            {r.can?.end && r.is_active && <button type="button" onClick={() => setEnding(r)} className="text-[#0A1F44] underline">End</button>}
                                            {r.can?.delete && <button type="button" onClick={() => remove(r)} className="text-[#B3261E] underline">Delete</button>}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </Table>
                        <div className="grid grid-cols-1 gap-3 border-t border-gray-100 px-5 py-3 sm:grid-cols-2 lg:grid-cols-3">
                            {coverage.filter((c) => c.target_type === lens).map((c) => (
                                <div key={`${c.source_type}-${c.source_id}`}><p className="truncate text-xs text-[#2D3748]" title={c.label}>{sourceLabel(c.source_type)}: {c.label} <span className="text-[#718096]">({c.rules} {c.rules === 1 ? 'rule' : 'rules'})</span></p><CoverageBar pct={c.allocated_pct} label={c.label} /></div>
                            ))}
                        </div>
                    </Card>
                ))}

                <Card title="Runs">
                    <Table head={['Period', 'Amount', 'Lens', 'Results', 'Pool', 'Allocated', 'Computed', 'State']} empty={runs.length === 0 && <Empty>No allocation runs yet. Run allocations to compute results.</Empty>}>
                        {runs.map((r) => (
                            <tr key={r.id}>
                                <td className="whitespace-nowrap px-4 py-2">{r.period.name} <Chip size="xs" tone={r.period.status === 'open' ? 'success' : 'neutral'}>{humanize(r.period.status)}</Chip></td>
                                <td className="px-4 py-2 text-xs">{humanize(r.amount_type)}</td>
                                <td className="px-4 py-2 text-xs">{lensLabel(r.target_type)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{r.results_count}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{finMoney(r.pool_total, baseCurrency, money, { decimals: 0 })}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{finMoney(r.allocated_total, baseCurrency, money, { decimals: 0 })}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{localDate(r.computed_at)}</td>
                                <td className="px-4 py-2">{r.is_frozen ? <Chip size="xs" tone="info" icon="lock">Frozen</Chip> : <Chip size="xs" tone="muted">Live</Chip>}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>

            <AllocationFormModal show={editing !== null} onClose={() => setEditing(null)} rule={editing === 'new' ? null : editing} sources={sources} targets={targets} drivers={drivers} options={options} firstOpenDate={firstOpenDate} />
            <EndRuleModal rule={ending} onClose={() => setEnding(null)} />
            <RunAllocationsModal show={running} onClose={() => setRunning(false)} fiscalYears={fiscalYears} periods={periods} amountTypes={options.amountTypes} defaultFiscalYearId={filters.fiscal_year_id} />
        </AuthenticatedLayout>
    );
}

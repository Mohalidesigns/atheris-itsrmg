import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import TargetFormModal from '@/Components/Finance/Budgets/TargetFormModal';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { AddButton, Card, Empty, RowActions, Table } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, formatMoney } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

function Usage({ requested, ceiling, pct, baseCurrency }) {
    if (ceiling === null || ceiling === undefined) return <span className="text-xs text-[#718096]">No ceiling</span>;
    const p = Number(pct ?? 0);
    return (
        <div className="min-w-[10rem]">
            <div className="flex justify-between text-xs"><span>{formatMoney(requested, baseCurrency, { decimals: 0 })}</span><span className="text-[#718096]">of {formatMoney(ceiling, baseCurrency, { decimals: 0 })}</span></div>
            <div className="mt-1 h-1.5 overflow-hidden rounded bg-gray-100" role="progressbar" aria-valuenow={Math.round(p)} aria-valuemin={0} aria-valuemax={100} aria-label="Used of ceiling">
                <div className={`h-full ${p > 100 ? 'bg-[#B3261E]' : p > 90 ? 'bg-[#E5A100]' : 'bg-[#2D7D46]'}`} style={{ width: `${Math.min(100, p)}%` }} />
            </div>
            <span className="text-[10px] text-[#718096]">{p.toFixed(0)}%</span>
        </div>
    );
}

export default function Targets({ cycle, cycles, targets, costCentres, categories, legalEntities, can, baseCurrency }) {
    const [modal, setModal] = useState(null);
    const base = useBaseCurrency(baseCurrency);
    const del = (t) => confirm(`Remove the ceiling for ${t.scope_label}?`) && router.delete(route('finance.targets.destroy', t.id), { preserveScroll: true });
    return (
        <AuthenticatedLayout header="Budget targets">
            <Head title="Budget targets" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: 'Targets' }]} title="Budget targets"
                subtitle="CapEx and OpEx ceilings per cycle, compared with what owners have requested" />
            <BudgetsNav active="targets" />
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <div className="w-full sm:w-72"><label className={LABEL} htmlFor="t-cycle">Cycle</label>
                    <select id="t-cycle" value={cycle?.id ?? ''} onChange={(e) => router.get(route('finance.targets.index'), { cycle_id: e.target.value }, { preserveState: true })} className={FIELD}>
                        {cycles.length === 0 && <option value="">No cycles</option>}
                        {cycles.map((c) => <option key={c.id} value={c.id}>{c.cycle_code} · {c.name}</option>)}
                    </select></div>
            </div>
            <Card right={can.manage && cycle && <AddButton onClick={() => setModal('new')}>Add ceiling</AddButton>}>
                <Table head={['Scope', 'CapEx', 'OpEx', 'Status', 'Notes', ...(can.manage ? [''] : [])]} empty={targets.length === 0 && <Empty>{cycle ? 'No ceilings set for this cycle.' : 'Create a cycle before setting ceilings.'}</Empty>}>
                    {targets.map((t) => (
                        <tr key={t.id}>
                            <td className="min-w-[12rem] px-4 py-2 text-[#2D3748]">{t.scope_label}</td>
                            <td className="px-4 py-2"><Usage requested={t.capex_requested} ceiling={t.capex_ceiling} pct={t.capex_pct} baseCurrency={base} /></td>
                            <td className="px-4 py-2"><Usage requested={t.opex_requested} ceiling={t.opex_ceiling} pct={t.opex_pct} baseCurrency={base} /></td>
                            <td className="px-4 py-2">{t.over ? <Chip size="xs" tone="error" icon="warning">Over ceiling</Chip> : <Chip size="xs" tone="success">Within</Chip>}</td>
                            <td className="px-4 py-2 text-xs text-[#718096]">{t.notes ?? '—'}</td>
                            {can.manage && <td className="px-4 py-2"><RowActions onEdit={() => setModal(t)} onDelete={() => del(t)} /></td>}
                        </tr>
                    ))}
                </Table>
            </Card>
            <TargetFormModal show={modal !== null} onClose={() => setModal(null)} cycle={cycle} target={modal === 'new' ? null : modal} costCentres={costCentres} categories={categories} legalEntities={legalEntities} />
        </AuthenticatedLayout>
    );
}

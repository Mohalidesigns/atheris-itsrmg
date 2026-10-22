import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import BudgetsNav from '@/Components/Finance/Budgets/BudgetsNav';
import CycleFormModal from '@/Components/Finance/Budgets/CycleFormModal';
import Money from '@/Components/Finance/Budgets/Money';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { AddButton, Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { CYCLE_TONES, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const ACTIONS = { planned: [['open', 'Open']], open: [['review', 'Send to review']], in_review: [['reopen', 'Reopen'], ['close', 'Close']] };

export default function Cycles({ cycles, fiscalYears, users, types, can, baseCurrency: baseProp }) {
    const baseCurrency = useBaseCurrency(baseProp);
    const [modal, setModal] = useState(null);
    const transition = (c, action, label) => confirm(`${label} cycle ${c.cycle_code}?`) && router.post(route('finance.cycles.transition', c.id), { action }, { preserveScroll: true });
    return (
        <AuthenticatedLayout header="Budget cycles">
            <Head title="Budget cycles" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Budgets', href: route('finance.budgets.index') }, { label: 'Cycles' }]} title="Budget cycles"
                subtitle="Planning windows in which cost-centre owners prepare and submit budgets" />
            <BudgetsNav active="cycles" />
            <Card right={can.manage && <AddButton onClick={() => setModal('new')}>New cycle</AddButton>}>
                <Table head={['Cycle', 'Name', 'Type', 'Fiscal year', 'Window', 'Coordinator', 'Status', 'Budgets', 'In approval', 'Approved', 'Baseline', 'Requested', 'Ceiling', ...(can.manage ? [''] : [])]}
                    empty={cycles.length === 0 && <Empty>No planning cycles yet.{can.manage ? ' Create one to let owners start budgeting.' : ''}</Empty>}>
                    {cycles.map((c) => (
                        <tr key={c.id}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs"><Link className="text-[#0A1F44] underline" href={route('finance.targets.index', { cycle_id: c.id })}>{c.cycle_code}</Link></td>
                            <td className="min-w-[10rem] px-4 py-2 text-[#2D3748]">{c.name}</td>
                            <td className="px-4 py-2 text-xs">{humanize(c.type)}</td>
                            <td className="px-4 py-2 text-xs">{c.fiscal_year.year_label}</td>
                            <td className="whitespace-nowrap px-4 py-2 text-xs">{formatDate(c.opens_on)} to {formatDate(c.closes_on)}</td>
                            <td className="px-4 py-2 text-xs">{c.coordinator?.name ?? '—'}</td>
                            <td className="px-4 py-2"><Chip size="xs" tone={CYCLE_TONES[c.status] || 'neutral'}>{humanize(c.status)}</Chip></td>
                            <td className="px-4 py-2 text-right text-xs"><Link className="underline" href={route('finance.budgets.index', { cycle_id: c.id })}>{c.budgets_count}</Link></td>
                            <td className="px-4 py-2 text-right text-xs">{c.in_approval_count}</td>
                            <td className="px-4 py-2 text-right text-xs">{c.approved_count}</td>
                            <td className="px-4 py-2 text-right text-xs">{c.baseline_count}</td>
                            <td className="px-4 py-2 text-right"><Money value={c.requested_base} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} /></td>
                            <td className="px-4 py-2 text-right">{c.ceiling_total === null ? <span className="text-xs text-[#718096]">None</span> : <Money value={c.ceiling_total} currency={baseCurrency} baseCurrency={baseCurrency} decimals={0} />}</td>
                            {can.manage && (
                                <td className="whitespace-nowrap px-4 py-2"><div className="flex justify-end gap-3 text-xs">
                                    {(ACTIONS[c.status] || []).map(([a, l]) => <button key={a} type="button" onClick={() => transition(c, a, l)} className="text-[#0A1F44] underline">{l}</button>)}
                                    {c.status !== 'closed' && <button type="button" onClick={() => setModal(c)} className="text-[#0A1F44] underline">Edit</button>}
                                </div></td>
                            )}
                        </tr>
                    ))}
                </Table>
            </Card>
            <CycleFormModal show={modal !== null} onClose={() => setModal(null)} cycle={modal === 'new' ? null : modal} fiscalYears={fiscalYears} users={users} types={types} />
        </AuthenticatedLayout>
    );
}

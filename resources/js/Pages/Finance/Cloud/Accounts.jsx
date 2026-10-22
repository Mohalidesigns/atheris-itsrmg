import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Chip from '@/Components/Material/Chip';
import CloudAccountFormModal from '@/Components/Finance/FinOps/CloudAccountFormModal';
import CloudImportPanel from '@/Components/Finance/FinOps/CloudImportPanel';
import CloudNav from '@/Components/Finance/FinOps/CloudNav';
import ProviderChip from '@/Components/Finance/FinOps/ProviderChip';
import TagRulesPanel from '@/Components/Finance/FinOps/TagRulesPanel';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { AddButton, Card, Empty, Table } from '@/Components/Finance/Settings/ui';
import { BATCH_TONES, finMoney, fmtPct, humanize, localDate } from '@/Utils/finance';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Accounts(props) {
    const { accounts = [], tagRules = [], batches = [], options, costCentres = [], applications = [], businessServices = [], contracts = [], users = [], can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can?.viewAmounts !== false;
    const [editing, setEditing] = useState(null); // null | 'new' | account
    const targets = { cost_centre: costCentres.map((c) => ({ id: c.id, label: `${c.code} ${c.name}` })), ea_application: applications, business_service: businessServices };
    const contractOpts = contracts.map((c) => ({ id: c.id, label: c.label }));
    const remove = (a) => confirm(`Delete ${a.name}? Accounts with cost lines cannot be deleted; deactivate them instead.`) && router.delete(route('finance.cloud.accounts.destroy', a.id), { preserveScroll: true });
    const reallocate = (a) => confirm(`Re-attribute open-period lines of ${a.name} using the current tag mapping and rules? Closed periods are not touched.`) && router.post(route('finance.cloud.accounts.reallocate', a.id), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout header="Cloud Accounts">
            <Head title="Cloud Accounts" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Cloud & FinOps', href: route('finance.cloud.costs') }, { label: 'Cloud Accounts' }]} title="Cloud Accounts"
                subtitle="Accounts, subscriptions and projects whose cost exports feed the FinOps ledger" />
            <CloudNav active="accounts" />
            {['account', 'reallocate'].map((k) => errors?.[k] && <p key={k} role="alert" className="mb-2 text-sm text-[#B3261E]">{errors[k]}</p>)}

            <div className="space-y-4">
                <Card title="Accounts" right={can.create && <AddButton onClick={() => setEditing('new')}>Add account</AddButton>}>
                    <Table head={['Account', 'Owner', 'Default cost centre', 'MTD', 'Untagged', 'Open anomalies', 'Last usage', 'Last batch', '']} empty={accounts.length === 0 && <Empty>No cloud accounts yet. Add one, then import its cost export.</Empty>}>
                        {accounts.map((a) => (
                            <tr key={a.id} className={a.is_active ? '' : 'opacity-50'}>
                                <td className="min-w-[12rem] px-4 py-2"><span className="block font-medium text-[#0A1F44]">{a.name}</span><span className="flex flex-wrap items-center gap-1.5"><ProviderChip provider={a.provider} /><span className="font-mono text-[11px] text-[#718096]">{a.account_identifier}</span>{!a.is_active && <Chip size="xs" tone="muted">Inactive</Chip>}</span></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{a.owner?.name ?? <span className="text-[#B3261E]">Unassigned</span>}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{a.default_cost_centre?.code} {a.default_cost_centre?.name}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-right tabular-nums">{finMoney(a.mtd, baseCurrency, money, { decimals: 0 })}</td>
                                <td className="px-4 py-2 text-right tabular-nums"><span className={Number(a.untagged_pct) > 20 ? 'text-[#8A6400]' : ''}>{fmtPct(a.untagged_pct)}</span></td>
                                <td className="px-4 py-2 text-right">{a.open_anomalies > 0 ? <Link href={route('finance.cloud.anomalies.index', { account_id: a.id })}><Chip size="xs" tone="error">{a.open_anomalies}</Chip></Link> : <span className="text-[#718096]">0</span>}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{a.last_usage_date ? localDate(a.last_usage_date) : 'Never'}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{a.last_batch ? <Link className="text-[#0A1F44] underline" href={a.last_batch.href}>{localDate(a.last_batch.created_at)}</Link> : '—'}</td>
                                <td className="px-4 py-2">
                                    <div className="flex justify-end gap-3 text-xs">
                                        {a.can?.edit && <button type="button" onClick={() => setEditing(a)} className="text-[#0A1F44] underline">Edit</button>}
                                        {can.manageRules && a.can?.edit && <button type="button" onClick={() => reallocate(a)} className="text-[#0A1F44] underline">Re-attribute</button>}
                                        {a.can?.delete && <button type="button" onClick={() => remove(a)} className="text-[#B3261E] underline">Delete</button>}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>

                {can.import && accounts.length > 0 && <CloudImportPanel accounts={accounts.filter((a) => a.is_active && a.can?.import !== false)} options={options} />}

                <TagRulesPanel rules={tagRules} accounts={accounts} dimensions={options.dimensions} targets={targets} can={can} />

                <Card title="Recent imports">
                    <Table head={['File', 'Account', 'Format', 'Rows', 'Accepted', 'Rejected', 'Status', 'Imported', '']} empty={batches.length === 0 && <Empty>No cloud imports yet.</Empty>}>
                        {batches.map((b) => (
                            <tr key={b.id}>
                                <td className="max-w-[14rem] truncate px-4 py-2 text-[#2D3748]" title={b.file_name}>{b.file_name}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{b.account}</td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{humanize(b.format ?? '')}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{b.row_count}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{b.accepted_count}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{b.rejected_count}</td>
                                <td className="px-4 py-2"><Chip size="xs" tone={BATCH_TONES[b.status] ?? 'neutral'}>{humanize(b.status)}</Chip></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs text-[#718096]">{localDate(b.created_at)}{b.imported_by ? ` · ${typeof b.imported_by === 'string' ? b.imported_by : b.imported_by.name}` : ''}</td>
                                <td className="px-4 py-2 text-right text-xs"><Link className="text-[#0A1F44] underline" href={b.href}>Open</Link></td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>

            <CloudAccountFormModal show={editing !== null} onClose={() => setEditing(null)} account={editing === 'new' ? null : editing} options={options} costCentres={costCentres} users={users} contracts={contractOpts} baseCurrency={baseCurrency} />
        </AuthenticatedLayout>
    );
}

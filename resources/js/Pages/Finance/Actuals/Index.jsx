import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ActualsTable from '@/Components/Finance/Expenses/ActualsTable';
import BatchesTable from '@/Components/Finance/Expenses/BatchesTable';
import ExpensesNav from '@/Components/Finance/Expenses/ExpensesNav';
import GlImportPanel from '@/Components/Finance/Expenses/GlImportPanel';
import MappingsPanel from '@/Components/Finance/Expenses/MappingsPanel';
import MatchActualModal from '@/Components/Finance/Expenses/MatchActualModal';
import ReasonModal from '@/Components/Finance/Expenses/ReasonModal';
import RulesPanel from '@/Components/Finance/Expenses/RulesPanel';
import useFilters from '@/Components/Finance/Expenses/useFilters';
import useBaseCurrency from '@/Components/Finance/Budgets/useBaseCurrency';
import { SearchFilter, SelectFilter } from '@/Components/Finance/Expenses/FilterField';
import { StatCard } from '@/Components/Finance/Budgets/Charts';
import { Card } from '@/Components/Finance/Settings/ui';
import { BTN_PRIMARY, BTN_SECONDARY, humanize, moneyOrMask } from '@/Utils/finance';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const TABS = [['posted', 'Ledger'], ['queue', 'Unmatched queue'], ['import', 'GL import'], ['rules', 'Rules'], ['mappings', 'Mappings']];
const SOURCES = ['invoice', 'gl_import', 'manual', 'accrual', 'accrual_reversal', 'prepayment_release', 'cloud_import', 'adjustment'];

export default function Index(props) {
    const { filters, actuals, truncated, summary, queue, rules, mappings, batches, periods, fiscalYears, costCentres, glAccounts, vendors, contracts, budgetLines, mappingKeys, dateFormats, can, errors } = props;
    const baseCurrency = useBaseCurrency(props.baseCurrency);
    const money = can.viewAmounts !== false;
    const [tab, setTab] = useState(props.tab === 'queue' ? 'queue' : props.tab === 'rules' ? 'rules' : props.tab === 'mappings' ? 'mappings' : 'posted');
    const { f, setF, apply, set } = useFilters('finance.actuals.index', filters, { tab: tab === 'import' ? undefined : tab });
    const [matching, setMatching] = useState(null);
    const [ack, setAck] = useState(null);
    const fmt = (v) => moneyOrMask(v, baseCurrency, money, { decimals: 0 });
    const [dup, setDup] = useState(null);
    const action = (a, kind) => (kind === 'match' ? setMatching(a) : kind === 'unbudgeted' ? setAck(a) : setDup({ a, kind }));
    const select = (k) => {
        setTab(k);
        if (k !== 'import') router.get(route('finance.actuals.index'), { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), tab: k }, { preserveState: true, preserveScroll: true, replace: true, only: ['tab', 'queue', 'actuals', 'rules', 'mappings', 'summary', 'filters'] });
    };
    const autoMatch = () => router.post(route('finance.actuals.auto-match'), {}, { preserveScroll: true });
    const periodsOfFy = periods.filter((p) => !f.fiscal_year_id || String(p.fiscal_year_id) === String(f.fiscal_year_id));

    return (
        <AuthenticatedLayout header="Actuals">
            <Head title="Actuals" />
            <PageHeader breadcrumbs={[{ label: 'IT Financial Management' }, { label: 'Actuals' }]} title="Actuals" subtitle="Posted spend from invoices and the general ledger, reconciled to budget lines"
                actions={can.import && <button type="button" onClick={() => select('import')} className={BTN_PRIMARY}>Import GL extract</button>} />
            <ExpensesNav active="actuals" />

            <div className="mb-4 grid grid-cols-2 gap-4 xl:grid-cols-4">
                <StatCard label="Posted" value={fmt(summary.posted_base)} sublabel={`${baseCurrency}, duplicates excluded`} icon="account_balance" tint="bg-[#EAF0FB] text-[#1D4ED8]" />
                <StatCard label="Unmatched" value={summary.unmatched_count} sublabel={fmt(summary.unmatched_base)} icon="rule" tint="bg-[#FFF6E0] text-[#8A6400]" onClick={() => select('queue')} />
                <StatCard label="Unbudgeted" value={summary.unbudgeted_count} icon="warning" tint="bg-[#F9DEDC] text-[#8C1D18]" onClick={() => apply({ ...f, match_status: 'unbudgeted' })} />
                <StatCard label="Duplicates skipped" value={summary.duplicates} icon="content_copy" tint="bg-[#F1F2F4] text-[#43474E]" onClick={() => apply({ ...f, match_status: 'duplicate' })} />
            </div>
            {Object.keys(summary.by_source ?? {}).length > 0 && (
                <p className="mb-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#4A5568]">{Object.entries(summary.by_source).map(([s, v]) => <span key={s}>{humanize(s)}: <b className="tabular-nums">{fmt(v)}</b></span>)}</p>
            )}

            <div className="mb-4 flex gap-1 overflow-x-auto whitespace-nowrap border-b border-gray-200" role="tablist">
                {TABS.filter(([k]) => k !== 'import' || can.import).map(([k, label]) => (
                    <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => select(k)}
                        className={`border-b-2 px-4 py-2 text-sm ${tab === k ? 'border-[#C9A86A] font-semibold text-[#0A1F44]' : 'border-transparent text-[#718096] hover:text-[#0A1F44]'}`}>{label}{k === 'queue' && summary.unmatched_count > 0 ? ` (${summary.unmatched_count})` : ''}</button>
                ))}
            </div>

            {tab === 'posted' && (
                <>
                    <div className="mb-4 grid grid-cols-2 gap-3 rounded-lg border border-gray-200 bg-white p-4 lg:grid-cols-6">
                        <SearchFilter id="a-q" value={f.q} setValue={(q) => setF({ ...f, q })} apply={() => f.q !== (filters.q ?? '') && apply(f)} placeholder="Description or reference" />
                        <SelectFilter id="a-fy" label="Fiscal year" value={f.fiscal_year_id} onChange={set('fiscal_year_id')} all="Current">{fiscalYears.map((y) => <option key={y.id} value={y.id}>{y.year_label}</option>)}</SelectFilter>
                        <SelectFilter id="a-pe" label="Period" value={f.period_id} onChange={set('period_id')}>{periodsOfFy.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</SelectFilter>
                        <SelectFilter id="a-cc" label="Cost centre" value={f.cost_centre_id} onChange={set('cost_centre_id')}>{costCentres.map((c) => <option key={c.id} value={c.id}>{c.code} {c.name}</option>)}</SelectFilter>
                        <SelectFilter id="a-so" label="Source" value={f.source} onChange={set('source')}>{SOURCES.map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</SelectFilter>
                        <SelectFilter id="a-ms" label="Status" value={f.match_status} onChange={set('match_status')}>{['matched', 'unmatched', 'unbudgeted', 'duplicate'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</SelectFilter>
                    </div>
                    <Card title="Ledger"><ActualsTable actuals={actuals} baseCurrency={baseCurrency} money={money} canMatch={can.match} onAction={action} />
                        {truncated && <p className="border-t border-gray-100 px-5 py-2 text-xs text-[#718096]">Showing the first 500 rows. Narrow the filters to see more.</p>}</Card>
                    {batches.length > 0 && <div className="mt-4"><Card title="Recent GL imports"><BatchesTable batches={batches} /></Card></div>}
                </>
            )}
            {tab === 'queue' && (
                <Card title="Unmatched queue" right={can.match && queue.length > 0 && <button type="button" onClick={autoMatch} className={BTN_SECONDARY}>Run auto-match</button>}>
                    <ActualsTable actuals={queue} baseCurrency={baseCurrency} money={money} canMatch={can.match} onAction={action} showReason />
                </Card>
            )}
            {tab === 'import' && can.import && <GlImportPanel mappings={mappings} mappingKeys={mappingKeys} dateFormats={dateFormats} />}
            {tab === 'rules' && <RulesPanel rules={rules} budgetLines={budgetLines} costCentres={costCentres} glAccounts={glAccounts} vendors={vendors} contracts={contracts} canEdit={can.match} />}
            {tab === 'mappings' && <MappingsPanel mappings={mappings} mappingKeys={mappingKeys} dateFormats={dateFormats} canEdit={can.import} />}

            {matching && <MatchActualModal actual={matching} budgetLines={budgetLines} baseCurrency={baseCurrency} money={money} errors={errors ?? {}} onClose={() => setMatching(null)} />}
            <ReasonModal show={!!ack} onClose={() => setAck(null)} title="Acknowledge as unbudgeted spend" routeName="finance.actuals.unbudgeted" params={ack?.id} field="note" label="Note" submitLabel="Acknowledge" errors={errors ?? {}} />
            <ReasonModal show={!!dup} onClose={() => setDup(null)} title={dup?.kind === 'confirm-duplicate' ? 'Confirm duplicate of an invoice posting' : 'Not a duplicate'} routeName={dup ? `finance.actuals.${dup.kind}` : 'finance.actuals.confirm-duplicate'} params={dup?.a?.id} field="reason" label="Reason" submitLabel={dup?.kind === 'confirm-duplicate' ? 'Confirm duplicate' : 'Clear flag'} errors={errors ?? {}} />
        </AuthenticatedLayout>
    );
}

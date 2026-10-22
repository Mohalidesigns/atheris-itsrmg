import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import CsvImportForm from '@/Components/Finance/CsvImportForm';
import ImportReport from '@/Components/Finance/ImportReport';
import { FIELD, LABEL, formatRate, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { AddButton, Card, Check, Empty, FormModal, RowActions, Table } from './ui';
import useRecordForm, { destroy } from './useRecordForm';

export default function CurrenciesTab({ currencies, fxRates, latestRates, options, baseLockedReason, can }) {
    const cur = useRecordForm({
        blank: { code: '', name: '', symbol: '', decimals: 2, is_active: true, is_base: false },
        toData: (r) => ({ name: r.name, symbol: r.symbol ?? '', decimals: r.decimals, is_active: r.is_active, is_base: r.is_base }),
        storeRoute: 'finance.currencies.store',
        updateRoute: 'finance.currencies.update',
        transform: (d) => ({ ...d, symbol: d.symbol || null }),
    });
    const fx = useRecordForm({
        blank: { from_currency: '', to_currency: '', rate: '', rate_date: new Date().toISOString().slice(0, 10), rate_type: (options.fxRateTypes || [])[0] ?? 'spot' },
        storeRoute: 'finance.fx-rates.store',
    });
    const present = new Set(currencies.map((c) => c.code));
    const catalogue = options.currencyCatalogue.filter((c) => !present.has(c.code));
    const active = currencies.filter((c) => c.is_active);
    const pickCatalogue = (code) => {
        const c = options.currencyCatalogue.find((x) => x.code === code);
        cur.form.setData((d) => ({ ...d, code, name: c?.name ?? '', symbol: c?.symbol ?? '', decimals: c?.decimals ?? 2 }));
    };
    const editingBase = cur.target && cur.target !== 'new' && cur.target.is_base;
    const baseLocked = !can.changeBase;
    const rateTypes = options.fxRateTypes || [];
    const latestByType = latestRates.reduce((acc, r) => { (acc[r.rate_type || 'spot'] ||= []).push(r); return acc; }, {});

    return (
        <div className="space-y-4">
            <ImportReport type="fx_rates" />
            <Card title="Currencies" right={can.manage && <AddButton onClick={() => cur.openNew()}>Add currency</AddButton>}>
                <Table head={['Code', 'Name', 'Symbol', 'Decimals', 'Base', 'Active', ...(can.manage ? [''] : [])]} empty={currencies.length === 0 && <Empty>No currencies yet.</Empty>}>
                    {currencies.map((c) => (
                        <tr key={c.id} className={c.is_active ? '' : 'opacity-50'}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-[#4A5568]">{c.code}</td>
                            <td className="px-4 py-2 text-[#2D3748]">{c.name}</td>
                            <td className="px-4 py-2 text-xs">{c.symbol || '—'}</td>
                            <td className="px-4 py-2 text-xs">{c.decimals}</td>
                            <td className="px-4 py-2">{c.is_base && <Chip tone="tertiary" size="xs">Base</Chip>}</td>
                            <td className="px-4 py-2 text-xs">{c.is_active ? 'Yes' : 'No'}</td>
                            {can.manage && <td className="px-4 py-2"><RowActions onEdit={() => cur.openEdit(c)} /></td>}
                        </tr>
                    ))}
                </Table>
            </Card>

            {latestRates.length > 0 && (
                <div className="space-y-3">
                    <p className="text-xs font-medium text-[#4A5568]">Latest rates</p>
                    {Object.entries(latestByType).map(([type, list]) => (
                        <div key={type}>
                            <p className="mb-1 text-[11px] uppercase tracking-wide text-[#718096]">{humanize(type)}</p>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                {list.map((r) => (
                                    <div key={`${type}${r.from_currency}${r.to_currency}`} className="rounded-lg border border-gray-200 bg-white p-3">
                                        <p className="text-xs text-[#718096]">{r.from_currency} → {r.to_currency}</p>
                                        <p className="mt-1 text-base font-semibold text-[#0A1F44]">{formatRate(r.rate)}</p>
                                        <p className="text-[10px] text-[#718096]">{formatDate(r.rate_date)}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <Card title="Exchange rates" right={can.manage && <AddButton onClick={() => fx.openNew()}>Add rate</AddButton>}>
                <Table head={['Date', 'Pair', 'Type', 'Rate', 'Source', ...(can.manage ? [''] : [])]} empty={fxRates.length === 0 && <Empty>No exchange rates yet. Add a rate or import a CSV.</Empty>}>
                    {fxRates.map((r) => (
                        <tr key={r.id}>
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-[#4A5568]">{formatDate(r.rate_date)}</td>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs">{r.from_currency} → {r.to_currency}</td>
                            <td className="px-4 py-2 text-xs">{humanize(r.rate_type || 'spot')}</td>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs">{formatRate(r.rate)}</td>
                            <td className="px-4 py-2"><Chip size="xs" tone={r.source === 'manual' ? 'neutral' : 'info'}>{r.source}</Chip></td>
                            {can.manage && <td className="px-4 py-2"><RowActions onDelete={() => destroy('finance.fx-rates.destroy', r.id, `Delete the ${r.from_currency}→${r.to_currency} rate for ${r.rate_date}?`)} /></td>}
                        </tr>
                    ))}
                </Table>
            </Card>
            {can.import && <CsvImportForm routeName="finance.fx-rates.import" label="Import exchange rates (CSV)" hint="from_currency,to_currency,rate,rate_date,rate_type" />}

            <FormModal show={cur.target !== null} onClose={cur.close} onSubmit={cur.submit} processing={cur.form.processing} title={cur.isNew ? 'Add currency' : `Edit ${cur.target?.code ?? ''}`}>
                {cur.isNew && (
                    <div><label className={LABEL}>Currency</label>
                        <select value={cur.form.data.code} onChange={(e) => pickCatalogue(e.target.value)} className={FIELD}><option value="">Select…</option>{catalogue.map((c) => <option key={c.code} value={c.code}>{c.code} · {c.name}</option>)}</select>
                        <InputError message={cur.form.errors.code} /></div>
                )}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div className="sm:col-span-2"><label className={LABEL}>Name</label><input maxLength={64} value={cur.form.data.name} onChange={(e) => cur.form.setData('name', e.target.value)} className={FIELD} /><InputError message={cur.form.errors.name} /></div>
                    <div><label className={LABEL}>Symbol</label><input maxLength={8} value={cur.form.data.symbol} onChange={(e) => cur.form.setData('symbol', e.target.value)} className={FIELD} /><InputError message={cur.form.errors.symbol} /></div>
                </div>
                <div className="w-32"><label className={LABEL}>Decimals</label><input type="number" min="0" max="4" value={cur.form.data.decimals} onChange={(e) => cur.form.setData('decimals', e.target.value)} className={FIELD} /><InputError message={cur.form.errors.decimals} /></div>
                <div className="flex flex-wrap gap-6">
                    <Check label="Active" checked={cur.form.data.is_active} onChange={(v) => cur.form.setData('is_active', v)} disabled={editingBase} />
                    {!cur.isNew && <span title={baseLocked ? baseLockedReason ?? 'The base currency is locked' : undefined}><Check label="Make base currency" checked={cur.form.data.is_base} onChange={(v) => cur.form.setData('is_base', v)} disabled={editingBase || (baseLocked && !cur.target?.is_base)} /></span>}
                </div>
                {baseLocked && baseLockedReason && !cur.isNew && <p className="text-xs text-[#718096]">Base currency locked: {baseLockedReason}</p>}
                <InputError message={cur.form.errors.is_base || cur.form.errors.is_active} />
            </FormModal>

            <FormModal show={fx.target !== null} onClose={fx.close} onSubmit={fx.submit} processing={fx.form.processing} title="Add exchange rate">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label className={LABEL}>From</label><select value={fx.form.data.from_currency} onChange={(e) => fx.form.setData('from_currency', e.target.value)} className={FIELD}><option value="">Select…</option>{active.map((c) => <option key={c.id} value={c.code}>{c.code}</option>)}</select><InputError message={fx.form.errors.from_currency} /></div>
                    <div><label className={LABEL}>To</label><select value={fx.form.data.to_currency} onChange={(e) => fx.form.setData('to_currency', e.target.value)} className={FIELD}><option value="">Select…</option>{active.map((c) => <option key={c.id} value={c.code}>{c.code}</option>)}</select><InputError message={fx.form.errors.to_currency} /></div>
                    <div><label className={LABEL}>Rate</label><input type="number" step="any" min="0" value={fx.form.data.rate} onChange={(e) => fx.form.setData('rate', e.target.value)} className={FIELD} /><InputError message={fx.form.errors.rate} /></div>
                    <div><label className={LABEL}>Rate type</label><select value={fx.form.data.rate_type} onChange={(e) => fx.form.setData('rate_type', e.target.value)} className={FIELD}>{rateTypes.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select><InputError message={fx.form.errors.rate_type} /></div>
                    <div><label className={LABEL}>Rate date</label><input type="date" max={new Date().toISOString().slice(0, 10)} value={fx.form.data.rate_date} onChange={(e) => fx.form.setData('rate_date', e.target.value)} className={FIELD} /><InputError message={fx.form.errors.rate_date} /></div>
                </div>
                <p className="text-xs text-[#718096]">An existing rate for the same pair, date and type is replaced.</p>
            </FormModal>
        </div>
    );
}

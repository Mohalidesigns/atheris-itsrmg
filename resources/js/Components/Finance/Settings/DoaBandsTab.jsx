import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { DecisionModal } from '@/Components/Finance/Budgets/BudgetActions';
import { BTN_PRIMARY, DOA_TONES, FIELD, LABEL, formatMoney, humanize } from '@/Utils/finance';
import { formatDate } from '@/Utils/risk';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Card, Empty, Table } from './ui';

const today = () => new Date().toISOString().slice(0, 10);

function BandsTable({ bands, baseCurrency }) {
    return (
        <Table head={['Band', 'From', 'Up to (exclusive)']}>
            {bands.map((b, i) => (
                <tr key={i}>
                    <td className="px-4 py-2 text-[#2D3748]">{b.label}</td>
                    <td className="px-4 py-2 text-xs tabular-nums">{b.masked || b.min == null ? '—' : formatMoney(b.min, baseCurrency, { decimals: 0 })}</td>
                    <td className="px-4 py-2 text-xs tabular-nums">{b.masked ? '—' : b.max === null || b.max === undefined ? 'No upper limit' : formatMoney(b.max, baseCurrency, { decimals: 0 })}</td>
                </tr>
            ))}
        </Table>
    );
}

function SodCard({ settings, baseCurrency, can }) {
    const form = useForm({ section: 'doa', invoice_sod_from: settings.invoice_sod_from ?? '' });
    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, invoice_sod_from: d.invoice_sod_from === '' ? null : d.invoice_sod_from }));
        form.put(route('finance.settings.update'), { preserveScroll: true });
    };
    return (
        <Card title="Invoice segregation of duties">
            <form onSubmit={submit}>
                <div className="space-y-2 px-5 py-4">
                    <label className={LABEL} htmlFor="sod-from">Final invoice approver may not have approved the purchase order from ({baseCurrency})</label>
                    <input id="sod-from" type="number" min="0" step="0.01" disabled={!can.manage} value={form.data.invoice_sod_from} onChange={(e) => form.setData('invoice_sod_from', e.target.value)} className={FIELD} placeholder={settings.invoice_sod_default != null ? String(settings.invoice_sod_default) : ''} />
                    <p className="text-xs text-[#718096]">Leave blank to use the bank default{settings.invoice_sod_default != null ? ` (${formatMoney(settings.invoice_sod_default, baseCurrency, { decimals: 0 })})` : ''}.</p>
                    <InputError message={form.errors.invoice_sod_from} />
                </div>
                {can.manage && <div className="flex justify-end border-t border-gray-100 px-5 py-3"><button disabled={form.processing} className={BTN_PRIMARY}>Save</button></div>}
            </form>
        </Card>
    );
}

export default function DoaBandsTab({ settings, doaVersions = [], baseCurrency, can }) {
    const { auth, errors: pageErrors } = usePage().props;
    const active = doaVersions.find((v) => v.status === 'active');
    const seed = (active?.bands ?? settings.doa_bands);
    const [decision, setDecision] = useState(null);
    const form = useForm({
        bands: seed.map((b) => ({ label: b.label, min: b.min, max: b.max })),
        effective_from: today(), reason: '',
    });
    const hasPending = doaVersions.some((v) => v.status === 'pending');
    const bands = form.data.bands;
    const last = bands.length - 1;
    // Keep min == previous max, first min 0, last max null.
    const normalise = (list) => list.map((b, i) => ({ ...b, min: i === 0 ? 0 : list[i - 1].max, max: i === list.length - 1 ? null : b.max }));
    const setBands = (list) => form.setData('bands', normalise(list));
    const update = (i, patch) => setBands(bands.map((b, j) => (j === i ? { ...b, ...patch } : b)));
    const add = () => {
        const prev = bands[last];
        const split = Number(prev.min) > 0 ? Number(prev.min) * 2 : 1000000;
        const next = bands.map((b, j) => (j === last ? { ...b, max: split } : b));
        setBands([...next, { label: 'New band', min: split, max: null }]);
    };
    const remove = (i) => setBands(bands.filter((_, j) => j !== i));

    let clientError = null;
    bands.forEach((b, i) => {
        if (i < last && (b.max === null || b.max === '' || Number(b.max) <= Number(b.min))) clientError = 'Each band must end above where it starts.';
        if (!b.label) clientError = clientError || 'Every band needs a label.';
    });
    const nonAsc = bands.some((b, i) => i < last && i > 0 && Number(b.max) <= Number(bands[i - 1].max));
    if (nonAsc) clientError = 'Band limits must ascend.';

    const decide = (payload, opts) => router.post(route(`finance.doa-bands.${decision.action}`, decision.v.id), payload, { preserveScroll: true, ...opts });
    const withdraw = (v) => confirm('Withdraw this proposal?') && router.post(route('finance.doa-bands.withdraw', v.id), {}, { preserveScroll: true });

    return (
        <div className="space-y-4">
            <div className="rounded-lg border border-[#DFE6FB] bg-[#EEF2FD] px-4 py-3 text-sm text-[#1E3A8A]">
                Bands are maker-checker controlled. Changing them re-maps amount-band conditions in Workflow Studio when the version takes effect.
            </div>
            <Card title={active ? `Active bands: version ${active.version_no}` : 'Active bands'} right={active && <span className="text-xs text-[#718096]">Effective from {formatDate(active.effective_from)}. Amounts in {baseCurrency}</span>}>
                <BandsTable bands={seed} baseCurrency={baseCurrency} />
            </Card>

            <SodCard settings={settings} baseCurrency={baseCurrency} can={can} />

            <Card title="Versions">
                <Table head={['Version', 'Status', 'Effective from', 'Proposed by', 'Decided by', 'Note', ...(can.approveBands || can.manage ? [''] : [])]} empty={doaVersions.length === 0 && <Empty>No versions yet.</Empty>}>
                    {doaVersions.map((v) => {
                        const mine = v.proposed_by?.id === auth.user.id;
                        return (
                            <tr key={v.id} className="align-top">
                                <td className="px-4 py-2 font-mono text-xs">v{v.version_no}</td>
                                <td className="px-4 py-2"><Chip size="xs" tone={DOA_TONES[v.status] || 'neutral'}>{humanize(v.status)}</Chip></td>
                                <td className="whitespace-nowrap px-4 py-2 text-xs">{formatDate(v.effective_from)}</td>
                                <td className="px-4 py-2 text-xs">{v.proposed_by?.name ?? '—'}<span className="block text-[#718096]">{formatDate(v.proposed_at)}</span></td>
                                <td className="px-4 py-2 text-xs">{v.decided_by?.name ?? '—'}{v.decided_at && <span className="block text-[#718096]">{formatDate(v.decided_at)}</span>}</td>
                                <td className="min-w-[10rem] px-4 py-2 text-xs text-[#4A5568]">{v.decision_note || v.reason || '—'}</td>
                                {(can.approveBands || can.manage) && (
                                    <td className="px-4 py-2">
                                        {v.status === 'pending' && (
                                            <div className="flex justify-end gap-3 text-xs">
                                                {can.approveBands && !mine && <>
                                                    <button type="button" onClick={() => setDecision({ v, action: 'approve', title: `Approve bands v${v.version_no}`, req: false })} className="text-[#1B5530] underline">Approve</button>
                                                    <button type="button" onClick={() => setDecision({ v, action: 'reject', title: `Reject bands v${v.version_no}`, req: true })} className="text-[#B3261E] underline">Reject</button>
                                                </>}
                                                {mine && <>
                                                    <span className="text-[#718096]">Awaiting a second approver</span>
                                                    <button type="button" onClick={() => withdraw(v)} className="text-[#0A1F44] underline">Withdraw</button>
                                                </>}
                                                {!mine && !can.approveBands && <span className="text-[#718096]">Awaiting a second approver</span>}
                                            </div>
                                        )}
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </Table>
                {pageErrors?.workflow && <p className="px-5 pb-3 text-xs text-[#B3261E]">{pageErrors.workflow}</p>}
            </Card>

            {can.manage && (
                <Card title="Propose new bands">
                    <form onSubmit={(e) => { e.preventDefault(); if (!clientError) form.post(route('finance.doa-bands.store'), { preserveScroll: true }); }}>
                        <div className="space-y-3 px-5 py-4">
                            {hasPending && <p className="text-xs text-[#8C5A00]">A proposal is already pending. A new proposal may be refused until it is decided.</p>}
                            {bands.map((b, i) => (
                                <div key={i} className="grid grid-cols-2 items-end gap-3 rounded-lg border border-gray-100 p-3 sm:grid-cols-12">
                                    <div className="col-span-2 sm:col-span-4"><label className={LABEL}>Label</label><input maxLength={40} value={b.label} onChange={(e) => update(i, { label: e.target.value })} className={FIELD} /></div>
                                    <div className="sm:col-span-3"><label className={LABEL}>From</label><input readOnly value={formatMoney(b.min, baseCurrency, { decimals: 0 })} className={`${FIELD} bg-gray-50 text-[#718096]`} /></div>
                                    <div className="sm:col-span-3"><label className={LABEL}>Up to (exclusive)</label>
                                        {i === last ? <input readOnly value="No upper limit" className={`${FIELD} bg-gray-50 text-[#718096]`} />
                                            : <input type="number" min="0" step="1" value={b.max ?? ''} onChange={(e) => update(i, { max: e.target.value === '' ? '' : Number(e.target.value) })} className={FIELD} />}</div>
                                    <div className="text-right sm:col-span-2">{i > 0 && <button type="button" onClick={() => remove(i)} className="text-xs text-[#B3261E] underline">Remove</button>}</div>
                                    <div className="col-span-2 sm:col-span-12"><InputError message={form.errors[`bands.${i}.label`] || form.errors[`bands.${i}.min`] || form.errors[`bands.${i}.max`]} /></div>
                                </div>
                            ))}
                            <InputError message={form.errors.bands} />
                            {clientError && <p className="text-xs text-[#B3261E]">{clientError}</p>}
                            {bands.length < 8 && <button type="button" onClick={add} className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs">Add band</button>}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div><label className={LABEL} htmlFor="doa-eff">Effective from</label><input id="doa-eff" type="date" min={today()} value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} className={FIELD} /><InputError message={form.errors.effective_from} /></div>
                                <div className="sm:col-span-2"><label className={LABEL} htmlFor="doa-reason">Reason</label><input id="doa-reason" maxLength={2000} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={FIELD} /><InputError message={form.errors.reason} /></div>
                            </div>
                        </div>
                        <div className="flex justify-end border-t border-gray-100 px-5 py-3">
                            <button disabled={form.processing || !!clientError} className={BTN_PRIMARY}>{form.processing ? 'Submitting…' : 'Propose bands'}</button>
                        </div>
                    </form>
                </Card>
            )}
            {decision && <DecisionModal show title={decision.title} noteRequired={decision.req} errors={pageErrors || {}} onClose={() => setDecision(null)} onSubmit={decide} submitLabel={decision.action === 'approve' ? 'Approve' : 'Reject'} />}
        </div>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { BuildingLibraryIcon } from '@/Components/Material/Symbols';
import { fmtDateTime, Pill } from '@/Components/Monitoring/format';

const lastTone = { ok: ['success', 'check_circle'], findings: ['warning', 'warning'], failed: ['error', 'cancel'] };
const statusTone = { connected: ['success', 'check_circle'], pending: ['warning', 'schedule'], disabled: ['muted', 'do_not_disturb_on'] };

function IntegrationForm({ integration, products, environments, statuses, assets, owners, onDone }) {
    const form = useForm({
        product: integration?.product || 'finacle', name: integration?.name || '', environment: integration?.environment || 'production',
        status: integration?.status || 'connected', asset_id: integration?.asset_id || '', owner_id: integration?.owner_id || '',
    });
    const err = (k) => form.errors[k] && <p className="text-[11px] text-[#C53030]">{form.errors[k]}</p>;
    const submit = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onDone };
        integration ? form.put(route('core-banking.update', integration.id), opts) : form.post(route('core-banking.store'), opts);
    };
    const sel = (k, opts, blank) => (
        <select value={form.data[k]} onChange={(e) => form.setData(k, e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs">
            {blank && <option value="">{blank}</option>}
            {opts.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
    );
    return (
        <form onSubmit={submit} className="mb-4 grid grid-cols-1 gap-2 rounded-xl border border-gray-100 bg-white p-4 shadow-sm md:grid-cols-3">
            <label className="text-[11px] text-[#718096]">Product *{sel('product', Object.entries(products).map(([k, p]) => [k, p.label]))}{err('product')}</label>
            <label className="text-[11px] text-[#718096]">Name *<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="mt-1 w-full rounded-lg border-gray-300 text-xs" />{err('name')}</label>
            <label className="text-[11px] text-[#718096]">Environment *{sel('environment', environments.map((e) => [e, e]))}{err('environment')}</label>
            <label className="text-[11px] text-[#718096]">Status *{sel('status', statuses.map((e) => [e, e]))}{err('status')}</label>
            <label className="text-[11px] text-[#718096]">Linked asset{sel('asset_id', assets.map((a) => [a.id, `${a.asset_id_code} · ${a.name}`]), 'None')}{err('asset_id')}</label>
            <label className="text-[11px] text-[#718096]">Owner{sel('owner_id', owners.map((o) => [o.id, o.name]), 'Unassigned')}{err('owner_id')}</label>
            <div className="flex gap-2 md:col-span-3">
                <button type="submit" disabled={form.processing} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{integration ? 'Save changes' : 'Add integration'}</button>
                <button type="button" onClick={onDone} className="rounded-lg border border-gray-300 px-3 py-2 text-xs text-[#4A5568]">Cancel</button>
            </div>
        </form>
    );
}

export default function CoreBankingIndex({ integrations = [], products = {}, environments = [], statuses = [], assets = [], owners = [], can = {} }) {
    const { errors = {} } = usePage().props;
    const [editing, setEditing] = useState(null); // null | 'new' | integration
    const [busy, setBusy] = useState(null);
    const sync = (i) => router.post(route('core-banking.sync', i.id), {}, { preserveScroll: true, onStart: () => setBusy(i.id), onFinish: () => setBusy(null) });
    const formProps = { products, environments, statuses, assets, owners, onDone: () => setEditing(null) };

    return (
        <AuthenticatedLayout header="Core Banking Integrations">
            <Head title="Core Banking Integrations" />
            <PageHeader
                breadcrumbs={[{ label: 'Core Banking' }, { label: 'Integrations' }]}
                title="Core Banking — Read-Only Adapters"
                subtitle="Finacle, Flexcube, T24, BankOne + Interswitch / NIBSS NIP. Technical metadata only — strict customer-data embargo."
                actions={can.create && (
                    <button onClick={() => setEditing('new')} className="rounded-lg bg-[#0A1F44] px-3 py-2 text-xs font-semibold text-white">Add integration</button>
                )}
            />
            {errors.sync && <p className="mb-3 rounded-lg border border-[#C53030]/30 bg-[#FFF5F5] px-3 py-2 text-xs text-[#C53030]">{errors.sync}</p>}
            {editing === 'new' && <IntegrationForm {...formProps} />}
            {editing && editing !== 'new' && <IntegrationForm key={editing.id} integration={editing} {...formProps} />}
            {integrations.length === 0 && <p className="rounded-xl border border-gray-100 bg-white p-6 text-sm text-[#718096]">No adapters registered yet.</p>}
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                {integrations.map((i) => (
                    <div key={i.id} className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <BuildingLibraryIcon className="h-5 w-5 text-[#0A1F44]" />
                                    <Link href={route('core-banking.show', i.id)} className="truncate text-sm font-semibold text-[#0A1F44] hover:underline">{i.name}</Link>
                                </div>
                                <p className="mt-1 text-xs text-[#718096]">{products[i.product]?.label || i.product} · {i.environment} · Sandbox</p>
                                <p className="text-xs text-[#718096]">Last sync: {fmtDateTime(i.last_sync_at)}</p>
                                {i.asset && <p className="text-xs text-[#718096]">Asset: <Link href={route('assets.show', i.asset.id)} className="text-[#0A1F44] underline">{i.asset.asset_id_code}</Link></p>}
                            </div>
                            <Pill value={i.status} tones={statusTone} />
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            {i.last_status && <Pill value={i.last_status} tones={lastTone} />}
                            <span className={i.findings_count > 0 ? 'font-semibold text-[#B3261E]' : 'text-[#718096]'}>{i.findings_count} finding(s)</span>
                        </div>
                        {i.last_error && <p className="mt-2 text-[11px] text-[#C53030]">{i.last_error}</p>}
                        <div className="mt-3 flex gap-2">
                            {can.edit && (
                                <button disabled={busy === i.id} onClick={() => sync(i)} className="flex-1 rounded-lg border border-[#0A1F44]/20 px-3 py-2 text-sm text-[#0A1F44] hover:bg-[#0A1F44]/5 disabled:opacity-50">
                                    {busy === i.id ? 'Syncing…' : 'Sync technical metadata'}
                                </button>
                            )}
                            {can.edit && <button onClick={() => setEditing(i)} className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-[#4A5568]">Edit</button>}
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

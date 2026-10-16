import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { BTN_PRIMARY, FIELD, LABEL } from '@/Utils/finance';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { AddButton, Card, Check, Empty, FormModal, RowActions, Table } from './ui';
import useRecordForm, { destroy } from './useRecordForm';

const BLANK = { code: '', name: '', unit: '', is_active: true };

export default function DriversTab({ drivers, fiscalYears, can }) {
    const ed = useRecordForm({ blank: BLANK, toData: (r) => ({ code: r.code, name: r.name, unit: r.unit, is_active: r.is_active }), storeRoute: 'finance.drivers.store', updateRoute: 'finance.drivers.update' });
    const { form } = ed;
    const [selectedId, setSelectedId] = useState(null);
    const selected = drivers.find((d) => d.id === selectedId) || null;
    const fy = fiscalYears.find((f) => f.is_current) || fiscalYears[0] || null;
    const periods = fy ? fy.periods : [];

    const initial = useMemo(() => {
        const m = {};
        if (selected) selected.values.forEach((v) => { m[v.period_id] = v.value; });
        return m;
    }, [selected]);
    const [vals, setVals] = useState({});
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    useEffect(() => { setVals(initial); setErrors({}); }, [initial]);

    const save = () => {
        const payload = periods.map((p) => ({ period_id: p.id, value: vals[p.id] === '' || vals[p.id] === undefined ? null : vals[p.id] }));
        router.put(route('finance.drivers.values.update', selected.id), { values: payload }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (e) => setErrors(e),
        });
    };

    return (
        <div className="space-y-4">
            <Card title="Drivers" right={can.manage && <AddButton onClick={() => ed.openNew()}>Add driver</AddButton>}>
                <Table head={['Code', 'Name', 'Unit', 'Active', '']} empty={drivers.length === 0 && <Empty>No drivers yet.</Empty>}>
                    {drivers.map((d) => (
                        <tr key={d.id} className={`${d.is_active ? '' : 'opacity-50'} ${selectedId === d.id ? 'bg-[#F7F3E8]' : ''}`}>
                            <td className="whitespace-nowrap px-4 py-2 font-mono text-xs text-[#4A5568]">{d.code}</td>
                            <td className="px-4 py-2 text-[#2D3748]">{d.name}</td>
                            <td className="px-4 py-2 text-xs text-[#4A5568]">{d.unit}</td>
                            <td className="px-4 py-2 text-xs">{d.is_active ? 'Yes' : 'No'}</td>
                            <td className="px-4 py-2">
                                <div className="flex justify-end gap-3 text-xs">
                                    <button type="button" onClick={() => setSelectedId(selectedId === d.id ? null : d.id)} className="text-[#0A1F44] underline">{selectedId === d.id ? 'Hide values' : 'Values'}</button>
                                    {can.manage && <RowActions onEdit={() => ed.openEdit(d)} onDelete={() => destroy('finance.drivers.destroy', d.id, `Delete driver ${d.code} and its values?`)} />}
                                </div>
                            </td>
                        </tr>
                    ))}
                </Table>
            </Card>

            {selected && (
                <Card title={<span className="flex flex-wrap items-center gap-2">{selected.name} <span className="text-xs font-normal text-[#718096]">({selected.unit})</span>{fy && <Chip size="xs" tone="primary">{fy.year_label}</Chip>}</span>}>
                    {periods.length === 0 ? <Empty>Add a fiscal year first; driver values are entered per period.</Empty> : (
                        <div className="px-5 py-4">
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                {periods.map((p) => (
                                    <div key={p.id}>
                                        <label className={LABEL}>{p.name}</label>
                                        <input type="number" step="0.0001" min="0" disabled={!can.manage} value={vals[p.id] ?? ''} onChange={(e) => setVals({ ...vals, [p.id]: e.target.value })} className={FIELD} />
                                    </div>
                                ))}
                            </div>
                            <InputError message={Object.values(errors)[0]} />
                            {can.manage && <div className="mt-4 flex justify-end"><button type="button" onClick={save} disabled={saving} className={BTN_PRIMARY}>{saving ? 'Saving…' : 'Save values'}</button></div>}
                        </div>
                    )}
                </Card>
            )}

            <FormModal show={ed.target !== null} onClose={ed.close} onSubmit={ed.submit} processing={form.processing} title={ed.isNew ? 'Add driver' : 'Edit driver'}>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div><label className={LABEL}>Code</label><input maxLength={32} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={`${FIELD} font-mono`} /><InputError message={form.errors.code} /></div>
                    <div className="sm:col-span-2"><label className={LABEL}>Name</label><input maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={FIELD} /><InputError message={form.errors.name} /></div>
                </div>
                <div><label className={LABEL}>Unit</label><input maxLength={32} value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} className={FIELD} placeholder="e.g. FTE, users, TB" /><InputError message={form.errors.unit} /></div>
                <Check label="Active" checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} />
            </FormModal>
        </div>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ClockIcon } from '@/Components/Material/Symbols';

const blank = { name: '', report_type: 'executive', frequency: 'weekly', recipients: '' };
const fmt = (d) => (d ? new Date(d).toLocaleString() : '—');

export default function Index({ schedules = [], types = {}, frequencies = [] }) {
    const [editing, setEditing] = useState(null);
    const form = useForm(blank);

    const startEdit = (s) => {
        setEditing(s.id);
        form.setData({ name: s.name, report_type: s.report_type, frequency: s.frequency, recipients: (s.recipients || []).join(', ') });
    };
    const reset = () => { setEditing(null); form.setData(blank); form.clearErrors(); };

    const submit = (e) => {
        e.preventDefault();
        const recipients = form.data.recipients.split(/[,;\s]+/).filter(Boolean);
        form.transform((d) => ({ ...d, recipients }));
        const opts = { preserveScroll: true, onSuccess: reset };
        if (editing) form.put(route('reports.scheduled.update', editing), opts);
        else form.post(route('reports.scheduled.store'), opts);
    };

    return (
        <AuthenticatedLayout header="Scheduled Reports">
            <Head title="Scheduled Reports" />
            <div className="mb-4">
                <h2 className="text-lg font-semibold text-[#2D3748]">Scheduled Reports</h2>
                <p className="text-sm text-[#718096] mt-1">Each due schedule records a snapshot of the report&apos;s key figures. Delivery is not configured: nothing is emailed to recipients yet.</p>
            </div>

            <form onSubmit={submit} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4 grid grid-cols-1 md:grid-cols-5 gap-3 items-start">
                <div>
                    <input className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" placeholder="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    {form.errors.name && <p className="text-xs text-[#B3261E] mt-1">{form.errors.name}</p>}
                </div>
                <select className="text-sm rounded-lg border border-gray-200 px-3 py-2" value={form.data.report_type} onChange={(e) => form.setData('report_type', e.target.value)}>
                    {Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
                <select className="text-sm rounded-lg border border-gray-200 px-3 py-2" value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                    {frequencies.map((f) => <option key={f} value={f}>{f}</option>)}
                </select>
                <div>
                    <input className="w-full text-sm rounded-lg border border-gray-200 px-3 py-2" placeholder="Recipients (comma separated emails)" value={form.data.recipients} onChange={(e) => form.setData('recipients', e.target.value)} />
                    {(form.errors.recipients || Object.keys(form.errors).find((k) => k.startsWith('recipients.'))) && <p className="text-xs text-[#B3261E] mt-1">Enter valid email addresses.</p>}
                </div>
                <div className="flex gap-2">
                    <button type="submit" disabled={form.processing} className="px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm">{editing ? 'Save' : 'Add schedule'}</button>
                    {editing && <button type="button" onClick={reset} className="px-3 py-2 rounded-lg border border-gray-200 text-sm">Cancel</button>}
                </div>
            </form>

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto">
                {schedules.length === 0 ? (
                    <div className="p-12 text-center">
                        <ClockIcon className="w-12 h-12 text-gray-300 mx-auto" />
                        <h3 className="text-sm font-medium text-[#2D3748] mt-4">No scheduled reports yet</h3>
                    </div>
                ) : (
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]">
                                <th className="px-3 py-2">Name</th><th className="px-3 py-2">Report</th><th className="px-3 py-2">Frequency</th>
                                <th className="px-3 py-2">Recipients</th><th className="px-3 py-2">Next run</th><th className="px-3 py-2">Last run</th>
                                <th className="px-3 py-2">Active</th><th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {schedules.map((s) => (
                                <tr key={s.id} className={s.active ? '' : 'opacity-60'}>
                                    <td className="px-3 py-2 font-medium">{s.name}</td>
                                    <td className="px-3 py-2">{types[s.report_type] || s.report_type}</td>
                                    <td className="px-3 py-2">{s.frequency}</td>
                                    <td className="px-3 py-2 text-xs">{(s.recipients || []).join(', ') || '—'}</td>
                                    <td className="px-3 py-2 text-xs">{fmt(s.next_run_at)}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {fmt(s.last_run_at)}
                                        {(s.runs || []).length > 0 && <span className="block text-[#718096]">{s.runs.length} recent run(s) recorded</span>}
                                    </td>
                                    <td className="px-3 py-2">
                                        <button onClick={() => router.post(route('reports.scheduled.toggle', s.id), {}, { preserveScroll: true })}
                                            className={`w-10 h-5 rounded-full relative ${s.active ? 'bg-[#2D7D46]' : 'bg-gray-300'}`}>
                                            <span className={`absolute top-0.5 ${s.active ? 'right-0.5' : 'left-0.5'} w-4 h-4 rounded-full bg-white shadow transition-all`} />
                                        </button>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap text-xs">
                                        <button onClick={() => startEdit(s)} className="text-[#0A1F44] mr-3">Edit</button>
                                        <button onClick={() => confirm('Delete this schedule?') && router.delete(route('reports.scheduled.destroy', s.id), { preserveScroll: true })} className="text-[#B3261E]">Delete</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

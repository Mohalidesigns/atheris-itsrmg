import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { fmtDate, Pill } from '@/Components/Monitoring/format';

const packTone = { draft: 'bg-[#FEF3C7] text-[#8A6400]', approved: 'bg-[#D1FAE5] text-[#2D7D46]' };

export default function BoardPacksIndex({ templates = [], runs = [], canCreate = false }) {
    const form = useForm({ template_id: templates[0]?.id || '', period: new Date().toISOString().slice(0, 7) });
    const chosen = templates.find((t) => String(t.id) === String(form.data.template_id));
    return (
        <AuthenticatedLayout header="Board Packs">
            <Head title="Board Packs" />
            <PageHeader
                breadcrumbs={[{ label: 'KRIs & Dashboards' }, { label: 'Board Packs' }]}
                title="Board Packs"
                subtitle="Each pack is a snapshot of the bank's figures when it was generated, printable to PDF. It is prepared by one person and approved for the board by another."
            />
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {canCreate && (
                    <form onSubmit={(e) => { e.preventDefault(); form.post(route('board-packs.generate')); }} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 h-fit">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Generate a board pack</h3>
                        <label className="block text-xs text-[#718096] mt-3">Template
                            <select value={form.data.template_id} onChange={(e) => form.setData('template_id', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 text-sm px-3 py-2">
                                {templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </select>
                        </label>
                        {chosen && <ul className="mt-2 list-disc pl-5 text-[11px] text-[#718096]">{chosen.section_titles.map((s) => <li key={s}>{s}</li>)}</ul>}
                        <label className="block text-xs text-[#718096] mt-3">Reporting period
                            <input type="month" value={form.data.period} onChange={(e) => form.setData('period', e.target.value)} className="mt-1 w-full rounded-lg border border-gray-200 text-sm px-3 py-2" />
                        </label>
                        {form.errors.period && <p className="text-[11px] text-[#C53030]">{form.errors.period}</p>}
                        <button disabled={form.processing} className="mt-4 w-full px-3 py-2 rounded-lg bg-[#0A1F44] text-white text-sm hover:bg-[#1A2F54] disabled:opacity-50">Generate from current figures</button>
                    </form>
                )}
                <div className={`${canCreate ? 'lg:col-span-2' : 'lg:col-span-3'} bg-white rounded-xl border border-gray-100 shadow-sm overflow-x-auto`}>
                    <h3 className="p-4 text-sm font-semibold text-[#2D3748]">Packs</h3>
                    <table className="min-w-full divide-y divide-gray-100 text-sm">
                        <thead className="bg-[#F7FAFC]">
                            <tr className="text-left text-xs uppercase text-[#718096]"><th className="px-3 py-2">Pack</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Prepared</th><th className="px-3 py-2">Approved</th></tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {runs.length === 0 && <tr><td colSpan={4} className="px-3 py-6 text-center text-xs text-[#718096]">No packs yet.</td></tr>}
                            {runs.map((r) => (
                                <tr key={r.id}>
                                    <td className="px-3 py-2"><Link href={route('board-packs.show', r.id)} className="font-medium text-[#0A1F44] hover:underline">{r.title || r.template?.name}</Link><p className="text-xs text-[#718096]">{r.template?.name} · {r.period}</p></td>
                                    <td className="px-3 py-2"><Pill value={r.status} tones={packTone} /></td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{r.preparer?.name || '—'}<br />{fmtDate(r.generated_at)}</td>
                                    <td className="px-3 py-2 text-xs text-[#718096]">{r.approver ? <>{r.approver.name}<br />{fmtDate(r.approved_at)}</> : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

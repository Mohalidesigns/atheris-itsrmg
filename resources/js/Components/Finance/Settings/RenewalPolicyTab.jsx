import InputError from '@/Components/InputError';
import Chip from '@/Components/Material/Chip';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Card, SaveBar } from './ui';

export default function RenewalPolicyTab({ settings, jobTitles, can }) {
    const form = useForm({
        section: 'renewal',
        renewal_lead_days: settings.renewal_lead_days,
        renewal_notice_offsets: [...settings.renewal_notice_offsets].sort((a, b) => b - a),
        escalation_role: settings.escalation_role || '',
        renewal_cfo_from: settings.renewal_cfo_from ?? '',
        renewal_cfo_uplift_pct: settings.renewal_cfo_uplift_pct ?? '',
    });
    const d = settings.renewal_cfo_defaults ?? {};
    const [draft, setDraft] = useState('');
    const offsets = form.data.renewal_notice_offsets;
    const add = () => {
        const n = parseInt(draft, 10);
        if (Number.isNaN(n) || n < 0 || n > 730 || offsets.includes(n) || offsets.length >= 12) return;
        form.setData('renewal_notice_offsets', [...offsets, n].sort((a, b) => b - a));
        setDraft('');
    };
    const remove = (n) => form.setData('renewal_notice_offsets', offsets.filter((x) => x !== n));
    return (
        <Card title="Renewal policy">
            <form onSubmit={(e) => { e.preventDefault(); form.transform((d) => ({ ...d, escalation_role: d.escalation_role || null, renewal_cfo_from: d.renewal_cfo_from === '' ? null : d.renewal_cfo_from, renewal_cfo_uplift_pct: d.renewal_cfo_uplift_pct === '' ? null : d.renewal_cfo_uplift_pct })); form.put(route('finance.settings.update'), { preserveScroll: true }); }}>
                <fieldset disabled={!can.manage} className="space-y-4 px-5 py-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div><label className={LABEL}>Renewal lead days</label><input type="number" min="1" max="730" value={form.data.renewal_lead_days} onChange={(e) => form.setData('renewal_lead_days', e.target.value)} className={FIELD} /><InputError message={form.errors.renewal_lead_days} /></div>
                        <div><label className={LABEL}>Escalation target</label>
                            <input list="fin-job-titles" maxLength={64} value={form.data.escalation_role} onChange={(e) => form.setData('escalation_role', e.target.value)} className={FIELD} placeholder="e.g. Head of IT" />
                            <datalist id="fin-job-titles">{jobTitles.map((t) => <option key={t} value={t} />)}</datalist>
                            <p className="mt-1 text-[11px] text-[#718096]">Job title, e.g. Head of IT; falls back to Organization Admin.</p>
                            <InputError message={form.errors.escalation_role} /></div>
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div><label className={LABEL} htmlFor="rp-cfo-from">CFO approval from (base currency)</label><input id="rp-cfo-from" type="number" min="0" step="0.01" value={form.data.renewal_cfo_from} onChange={(e) => form.setData('renewal_cfo_from', e.target.value)} className={FIELD} placeholder={d.from != null ? String(d.from) : ''} />
                            <p className="mt-1 text-[11px] text-[#718096]">Leave blank to use the platform default{d.from != null ? ` (${Number(d.from).toLocaleString()})` : ''}.</p>
                            <InputError message={form.errors.renewal_cfo_from} /></div>
                        <div><label className={LABEL} htmlFor="rp-cfo-uplift">CFO approval uplift threshold (%)</label><input id="rp-cfo-uplift" type="number" min="0" max="1000" step="0.1" value={form.data.renewal_cfo_uplift_pct} onChange={(e) => form.setData('renewal_cfo_uplift_pct', e.target.value)} className={FIELD} placeholder={d.uplift_pct != null ? String(d.uplift_pct) : ''} />
                            <p className="mt-1 text-[11px] text-[#718096]">Leave blank to use the platform default{d.uplift_pct != null ? ` (${d.uplift_pct}%)` : ''}. Like-for-like uplift above this routes to the CFO.</p>
                            <InputError message={form.errors.renewal_cfo_uplift_pct} /></div>
                    </div>
                    <div>
                        <p className={LABEL}>Notice offsets (days before the deadline)</p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {offsets.map((n) => (
                                <span key={n} className="inline-flex items-center gap-1">
                                    <Chip tone="primary">{n === 0 ? 'On the day' : `${n} days`}</Chip>
                                    {can.manage && <button type="button" onClick={() => remove(n)} aria-label={`Remove ${n}`} className="text-xs text-[#B3261E]">×</button>}
                                </span>
                            ))}
                        </div>
                        {can.manage && (
                            <div className="mt-3 flex items-center gap-2">
                                <input type="number" min="0" max="730" value={draft} onChange={(e) => setDraft(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } }} className="w-28 rounded-lg border border-gray-200 px-3 py-2 text-sm" placeholder="Days" />
                                <button type="button" onClick={add} className="rounded-lg border border-gray-200 px-3 py-2 text-sm">Add offset</button>
                            </div>
                        )}
                        <InputError message={form.errors.renewal_notice_offsets} />
                    </div>
                </fieldset>
                {can.manage && <SaveBar processing={form.processing} dirty={offsets.length > 0} />}
            </form>
        </Card>
    );
}

import InputError from '@/Components/InputError';
import Icon from '@/Components/Material/Icon';
import { formatDate } from '@/Utils/risk';
import { router, useForm } from '@inertiajs/react';
import { Card, inputCls, labelCls, primaryBtn } from './shared';

export default function ContainmentPanel({ issue, capa, actions = [], canWork }) {
    const open = canWork && ['identification', 'containment'].includes(capa.stage);
    const form = useForm({ action: '', notes: '' });
    const remove = (a) => router.delete(route('issues.capa.containment.destroy', [issue.id, a.id]), { preserveScroll: true });

    return (
        <Card title="Containment log" icon="shield" aside={<span className="text-xs text-[#718096]">{actions.length} recorded</span>}>
            {actions.length === 0 && <p className="text-xs italic text-[#A0AEC0]">No containment action recorded yet. Record the immediate mitigation before leaving this stage.</p>}
            <ul className="space-y-2">
                {actions.map((a) => (
                    <li key={a.id} className="flex items-start gap-2 rounded-lg bg-[#F7FAFC] p-3 text-sm">
                        <Icon name="shield" size={16} className="mt-0.5 text-[#0A1F44]" fill />
                        <div className="min-w-0 flex-1">
                            <p className="whitespace-pre-line text-[#2D3748]">{a.action}</p>
                            {a.notes && <p className="mt-0.5 whitespace-pre-line text-xs text-[#4A5568]">{a.notes}</p>}
                            <p className="mt-0.5 text-[11px] text-[#718096]">{formatDate(a.performed_at, true)} · {a.performed_by?.name || 'Unknown'}</p>
                        </div>
                        {open && <button type="button" onClick={() => remove(a)} aria-label="Remove containment action" className="rounded-lg p-1 text-[#B3261E] hover:bg-[#B3261E]/10"><Icon name="delete" size={18} /></button>}
                    </li>
                ))}
            </ul>
            {open && (
                <form onSubmit={(e) => { e.preventDefault(); form.post(route('issues.capa.containment.store', issue.id), { preserveScroll: true, onSuccess: () => form.reset() }); }} className="mt-3 space-y-2 border-t border-gray-100 pt-3">
                    <div>
                        <label className={labelCls}>Immediate action taken</label>
                        <textarea value={form.data.action} onChange={(e) => form.setData('action', e.target.value)} rows={2} className={inputCls} placeholder="e.g. Failed batch node isolated; traffic failed over to DR" />
                        <InputError message={form.errors.action} className="mt-1" />
                    </div>
                    <div>
                        <label className={labelCls}>Notes (optional)</label>
                        <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={inputCls} />
                    </div>
                    <InputError message={form.errors.capa} />
                    <div className="flex justify-end"><button disabled={!form.data.action.trim() || form.processing} className={primaryBtn}><Icon name="add" size={16} />Add action</button></div>
                </form>
            )}
        </Card>
    );
}

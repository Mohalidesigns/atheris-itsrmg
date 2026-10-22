import InputError from '@/Components/InputError';
import { Card, SaveBar } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL } from '@/Utils/finance';
import { useForm } from '@inertiajs/react';

export default function NarrativeForm({ budget, can }) {
    const form = useForm({
        executive_summary: budget.executive_summary ?? '', assumptions: budget.assumptions ?? '', risks_summary: budget.risks_summary ?? '',
    });
    const fields = [['executive_summary', 'Executive summary'], ['assumptions', 'Assumptions'], ['risks_summary', 'Risks']];
    return (
        <Card title="Narrative">
            <form onSubmit={(e) => { e.preventDefault(); form.put(route('finance.budgets.update', { budget: budget.id, tab: 'narrative' }), { preserveScroll: true }); }}>
                <div className="space-y-3 px-5 py-4">
                    {fields.map(([key, label]) => (
                        <div key={key}><label className={LABEL} htmlFor={`n-${key}`}>{label}</label>
                            <textarea id={`n-${key}`} rows={5} disabled={!can.edit} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} className={FIELD} />
                            <InputError message={form.errors[key]} /></div>
                    ))}
                </div>
                {can.edit && <SaveBar processing={form.processing} dirty={form.isDirty} />}
            </form>
        </Card>
    );
}

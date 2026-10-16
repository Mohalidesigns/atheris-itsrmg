import InputError from '@/Components/InputError';
import { AddButton, Card, Empty, FormModal } from '@/Components/Finance/Settings/ui';
import { FIELD, LABEL, humanize } from '@/Utils/finance';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function TeamPanel({ contract, team, users, options, can }) {
    const [show, setShow] = useState(false);
    const form = useForm({ user_id: '', role: options.teamRoles[1]?.value ?? 'member' });
    const taken = new Set(team.map((m) => m.user.id));
    const submit = () => form.post(route('finance.contracts.team.store', contract.id), { preserveScroll: true, onSuccess: () => { form.reset(); setShow(false); } });
    const remove = (m) => confirm(`Remove ${m.user.name} from the team?`) && router.delete(route('finance.contracts.team.destroy', { contract: contract.id, member: m.id }), { preserveScroll: true });
    return (
        <Card title="Team" right={can.manageTeam && <AddButton onClick={() => setShow(true)}>Add member</AddButton>}>
            {team.length === 0 ? <Empty>No team members yet. Team members receive renewal reminders.</Empty> : (
                <ul className="divide-y divide-gray-50">
                    {team.map((m) => (
                        <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-sm">
                            <span><span className="text-[#2D3748]">{m.user.name}</span> <span className="text-xs text-[#718096]">{m.user.job_title ?? ''}{m.user.email ? ` · ${m.user.email}` : ''}</span></span>
                            <span className="flex items-center gap-3 text-xs"><span className="text-[#43474E]">{humanize(m.role)}</span>
                                {can.manageTeam && <button type="button" onClick={() => remove(m)} className="text-[#B3261E] underline">Remove</button>}</span>
                        </li>
                    ))}
                </ul>
            )}
            <FormModal show={show} onClose={() => setShow(false)} onSubmit={submit} processing={form.processing || !form.data.user_id} title="Add team member" submitLabel="Add">
                <div><label className={LABEL} htmlFor="tm-user">User</label>
                    <select id="tm-user" value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)} className={FIELD}><option value="">Select…</option>
                        {users.filter((u) => !taken.has(u.id)).map((u) => <option key={u.id} value={u.id}>{u.name}{u.job_title ? ` · ${u.job_title}` : ''}</option>)}</select>
                    <InputError message={form.errors.user_id} /></div>
                <div><label className={LABEL} htmlFor="tm-role">Role</label>
                    <select id="tm-role" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)} className={FIELD}>{options.teamRoles.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
                    <InputError message={form.errors.role} /></div>
            </FormModal>
        </Card>
    );
}

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { formatDate, humanize, lockMessage } from '@/Utils/csat';

function Err({ m }) { return m ? <p className="text-xs text-[#C53030] mt-1">{m}</p> : null; }
const scoreColor = (s) => s >= 6 ? '#C53030' : s >= 3 ? '#DD6B20' : '#2D7D46';

const statusColors = { identified: '#C53030', assigned: '#DD6B20', in_progress: '#D4AF37', remediated: '#319795', verified: '#2D7D46' };
const statusLabels = { identified: 'Identified', assigned: 'Assigned', in_progress: 'In Progress', remediated: 'Remediated', verified: 'Verified' };

export default function Vulnerabilities({ assessment, vulnerabilities, users, editable = true }) {
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        vulnerability_name: '', description: '', vulnerability_category: 'technology',
        likelihood_of_exploit: 'moderate', impact_if_exploited: 'moderate',
        mitigants_in_place: false, existing_mitigants: '', planned_mitigants: '',
        assigned_to: '', due_date: '', comment: '',
    });

    const openEdit = (v) => {
        setData({
            vulnerability_name: v.vulnerability_name, description: v.description || '',
            vulnerability_category: v.vulnerability_category, likelihood_of_exploit: v.likelihood_of_exploit,
            impact_if_exploited: v.impact_if_exploited, mitigants_in_place: v.mitigants_in_place,
            existing_mitigants: v.existing_mitigants || '', planned_mitigants: v.planned_mitigants || '',
            assigned_to: v.assigned_to || '', due_date: v.due_date_input || '', comment: v.comment || '',
        });
        setEditing(v.id);
        setShowForm(true);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('csat.vulnerabilities.update', [assessment.id, editing]), {
                preserveScroll: true, onSuccess: () => { setShowForm(false); reset(); },
            });
        } else {
            post(route('csat.vulnerabilities.store', assessment.id), {
                preserveScroll: true, onSuccess: () => { setShowForm(false); reset(); },
            });
        }
    };

    const updateStatus = (id, status) => {
        router.put(route('csat.vulnerabilities.update', [assessment.id, id]), { remediation_status: status }, { preserveScroll: true });
    };

    const destroy = (id) => {
        if (confirm('Remove this vulnerability from the register?')) {
            router.delete(route('csat.vulnerabilities.destroy', [assessment.id, id]), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Vulnerability Register">
            <Head title="Vulnerabilities" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                {editable && (
                    <button onClick={() => { reset(); setEditing(null); setShowForm(true); }}
                        className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                        + Add Vulnerability
                    </button>
                )}
            </div>
            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}

            {/* Form */}
            {showForm && (
                <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">{editing ? 'Edit' : 'Add'} Vulnerability</h3>
                    <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="md:col-span-2">
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Name</label>
                            <input type="text" value={data.vulnerability_name} onChange={e => setData('vulnerability_name', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            <Err m={errors.vulnerability_name} />
                        </div>
                        <div className="md:col-span-2">
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Description</label>
                            <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={2}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Category</label>
                            <select value={data.vulnerability_category} onChange={e => setData('vulnerability_category', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="people">People</option>
                                <option value="process">Process</option>
                                <option value="technology">Technology</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Likelihood of Exploit</label>
                            <select value={data.likelihood_of_exploit} onChange={e => setData('likelihood_of_exploit', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="high">High</option>
                                <option value="moderate">Moderate</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Impact if Exploited</label>
                            <select value={data.impact_if_exploited} onChange={e => setData('impact_if_exploited', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="high">High</option>
                                <option value="moderate">Moderate</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div className="flex items-center gap-2">
                            <input type="checkbox" checked={data.mitigants_in_place} onChange={e => setData('mitigants_in_place', e.target.checked)}
                                className="rounded border-gray-300" id="mitigants" />
                            <label htmlFor="mitigants" className="text-sm text-[#2D3748]">Mitigants in place?</label>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Existing Mitigants</label>
                            <textarea value={data.existing_mitigants} onChange={e => setData('existing_mitigants', e.target.value)} rows={2}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Planned Mitigants</label>
                            <textarea value={data.planned_mitigants} onChange={e => setData('planned_mitigants', e.target.value)} rows={2}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Assign To</label>
                            <select value={data.assigned_to} onChange={e => setData('assigned_to', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="">Unassigned</option>
                                {(users || []).map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Due Date</label>
                            <input type="date" value={data.due_date} onChange={e => setData('due_date', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            <Err m={errors.due_date} />
                        </div>
                        <div className="md:col-span-2">
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Comment</label>
                            <input type="text" value={data.comment} onChange={e => setData('comment', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            <Err m={errors.assigned_to} />
                        </div>
                        <div className="md:col-span-2 flex gap-2">
                            <button type="submit" disabled={processing}
                                className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] disabled:opacity-50">
                                {editing ? 'Update' : 'Add'} Vulnerability
                            </button>
                            <button type="button" onClick={() => { setShowForm(false); reset(); }}
                                className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                        </div>
                    </form>
                </div>
            )}

            {/* Vulnerability List */}
            <div className="space-y-3">
                {(vulnerabilities || []).map(v => (
                    <div key={v.id} className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <div className="flex items-center gap-2 mb-1">
                                    <h4 className="text-sm font-semibold text-[#2D3748]">{v.vulnerability_name}</h4>
                                    <span className="px-2 py-0.5 text-xs font-bold text-white rounded"
                                        style={{ backgroundColor: statusColors[v.remediation_status] || '#718096' }}>
                                        {statusLabels[v.remediation_status] || v.remediation_status}
                                    </span>
                                    {v.composite_score > 0 && (
                                        <span className="px-2 py-0.5 text-xs font-bold text-white rounded" style={{ backgroundColor: scoreColor(v.composite_score) }}>Score {v.composite_score}</span>
                                    )}
                                    {v.composite_score >= 6 && !v.assigned_to && <span className="text-xs text-[#C53030]">High score — assign an owner</span>}
                                </div>
                                {v.description && <p className="text-xs text-[#718096] mb-2">{v.description}</p>}
                                <div className="flex gap-4 text-xs text-[#718096]">
                                    <span>Category: <strong className="text-[#2D3748]">{humanize(v.vulnerability_category)}</strong></span>
                                    <span>Likelihood: <strong className="text-[#2D3748]">{humanize(v.likelihood_of_exploit)}</strong></span>
                                    <span>Impact: <strong className="text-[#2D3748]">{humanize(v.impact_if_exploited)}</strong></span>
                                    <span>Mitigants: <strong className="text-[#2D3748]">{v.mitigants_in_place ? 'In place' : 'None'}</strong></span>
                                    {v.assignee && <span>Assigned: {v.assignee.name}</span>}
                                    {v.due_date && <span>Due: {formatDate(v.due_date)}</span>}
                                </div>
                            </div>
                            {editable && (
                                <div className="flex gap-1 ml-4">
                                    <select value={v.remediation_status} onChange={e => updateStatus(v.id, e.target.value)}
                                        aria-label="Remediation status" className="px-2 py-1 border border-gray-200 rounded text-xs">
                                        {Object.entries(statusLabels).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                                    </select>
                                    <button onClick={() => openEdit(v)} className="px-2 py-1 text-xs text-[#1A365D] hover:bg-gray-50 rounded">Edit</button>
                                    <button onClick={() => destroy(v.id)} className="px-2 py-1 text-xs text-[#C53030] hover:bg-red-50 rounded">Remove</button>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
                {(vulnerabilities || []).length === 0 && !showForm && (
                    <div className="bg-white rounded-xl border border-gray-100 p-8 shadow-sm text-center">
                        <p className="text-sm text-[#718096]">No vulnerabilities registered yet.</p>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

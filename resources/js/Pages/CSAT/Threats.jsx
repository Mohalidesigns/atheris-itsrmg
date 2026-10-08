import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { humanize, lockMessage } from '@/Utils/csat';

// CBN threat scoring: High = 3, Moderate = 2, Low = 1; risk = likelihood × impact (1–9), ≥6 is high.
const LEVELS = ['high', 'moderate', 'low'];
const VALUE = { high: 3, moderate: 2, low: 1 };
const riskColor = (score) => score >= 6 ? '#C53030' : score >= 3 ? '#DD6B20' : '#2D7D46';

function Err({ m }) { return m ? <p className="text-xs text-[#C53030] mt-1">{m}</p> : null; }

/** BR-TR-03: interactive 3×3 likelihood × impact matrix — click a cell to filter the register. */
function ThreatMatrix({ threats, cell, onCell }) {
    return (
        <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
            <h3 className="text-sm font-semibold text-[#2D3748] mb-3">Threat Risk Matrix</h3>
            <table className="text-xs">
                <tbody>
                    {LEVELS.map(l => (
                        <tr key={l}>
                            <td className="pr-2 text-right text-[#718096] w-20">{humanize(l)}</td>
                            {[...LEVELS].reverse().map(i => {
                                const score = VALUE[l] * VALUE[i];
                                const n = threats.filter(t => t.likelihood === l && t.impact === i).length;
                                const active = cell && cell[0] === l && cell[1] === i;
                                return (
                                    <td key={i} className="p-0.5">
                                        <button onClick={() => onCell(active ? null : [l, i])}
                                            className={`w-20 h-14 rounded-md text-white font-bold flex flex-col items-center justify-center ${active ? 'ring-2 ring-[#0A1F44]' : ''}`}
                                            style={{ backgroundColor: riskColor(score) + (n ? 'E6' : '55') }}>
                                            <span className="text-lg">{n || ''}</span>
                                            <span className="text-[10px] font-normal">score {score}</span>
                                        </button>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                    <tr><td /><td colSpan={3} className="pt-1 text-center text-[#718096]">Low · Moderate · High → Impact</td></tr>
                </tbody>
            </table>
            <p className="text-[10px] text-[#718096] mt-1">Rows: likelihood. Click a cell to filter.</p>
        </div>
    );
}

export default function Threats({ assessment, threats, catalogue, editable = true }) {
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [cataloguePick, setCataloguePick] = useState(null);
    const [cell, setCell] = useState(null);
    const shown = cell ? threats.filter(t => t.likelihood === cell[0] && t.impact === cell[1]) : threats;

    const { data, setData, post, put, processing, reset, errors } = useForm({
        threat_name: '', catalogue_threat_id: '', description: '',
        threat_source: 'external', threat_category: 'technical',
        likelihood: 'moderate', impact: 'moderate',
        mitigating_controls_desc: '', residual_risk_score: '', comment: '',
    });

    const openCreate = (catItem = null) => {
        reset();
        if (catItem) {
            setData({
                threat_name: catItem.threat_name, catalogue_threat_id: catItem.id,
                description: catItem.description || '', threat_source: catItem.threat_source || 'external',
                threat_category: catItem.threat_category || 'technical',
                likelihood: catItem.typical_likelihood || 'moderate', impact: catItem.typical_impact || 'moderate',
                mitigating_controls_desc: '', residual_risk_score: '', comment: '',
            });
        }
        setEditing(null);
        setShowForm(true);
        setCataloguePick(null);
    };

    const openEdit = (t) => {
        setData({
            threat_name: t.threat_name, catalogue_threat_id: t.catalogue_threat_id || '',
            description: t.description || '', threat_source: t.threat_source,
            threat_category: t.threat_category, likelihood: t.likelihood, impact: t.impact,
            mitigating_controls_desc: t.mitigating_controls_desc || '',
            residual_risk_score: t.residual_risk_score || '', comment: t.comment || '',
        });
        setEditing(t.id);
        setShowForm(true);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('csat.threats.update', [assessment.id, editing]), {
                preserveScroll: true, onSuccess: () => { setShowForm(false); reset(); },
            });
        } else {
            post(route('csat.threats.store', assessment.id), {
                preserveScroll: true, onSuccess: () => { setShowForm(false); reset(); },
            });
        }
    };

    const destroy = (id) => {
        if (confirm('Remove this threat?')) {
            router.delete(route('csat.threats.destroy', [assessment.id, id]), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Threat Register">
            <Head title="Threats" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.overview', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Overview</Link>
                {editable && <div className="flex gap-2">
                    <button onClick={() => setCataloguePick(true)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 text-[#718096]">
                        From Catalogue
                    </button>
                    <button onClick={() => openCreate()} className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">
                        + Add Threat
                    </button>
                </div>}
            </div>
            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <ThreatMatrix threats={threats || []} cell={cell} onCell={setCell} />
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-100 p-5 shadow-sm text-sm text-[#718096]">
                    <h3 className="text-sm font-semibold text-[#2D3748] mb-2">Summary</h3>
                    <p>{threats.length} threats registered · {threats.filter(t => t.inherent_risk_score >= 6).length} high (score ≥ 6) · {threats.filter(t => !t.mitigating_controls_desc).length} without documented mitigating controls.</p>
                    {cell && <p className="mt-2 text-[#0A1F44]">Filtered to likelihood {humanize(cell[0])} × impact {humanize(cell[1])} — <button className="underline" onClick={() => setCell(null)}>clear</button></p>}
                </div>
            </div>

            {/* Catalogue Picker */}
            {cataloguePick && (
                <div className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm mb-6">
                    <div className="flex items-center justify-between mb-3">
                        <h3 className="text-sm font-semibold text-[#2D3748]">Nigerian Threat Catalogue</h3>
                        <button onClick={() => setCataloguePick(null)} className="text-xs text-[#718096] hover:underline">Close</button>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                        {(catalogue || []).map(c => (
                            <button key={c.id} onClick={() => openCreate(c)}
                                className="text-left p-3 rounded-lg border border-gray-100 hover:border-[#1A365D] hover:bg-[#1A365D]/5 transition-colors">
                                <p className="text-sm font-medium text-[#2D3748]">{c.threat_name}</p>
                                {c.cbn_relevance_note && <p className="text-xs text-[#718096] mt-1">{c.cbn_relevance_note}</p>}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* Threat Form */}
            {showForm && (
                <div className="bg-white rounded-xl border border-gray-100 p-6 shadow-sm mb-6">
                    <h3 className="text-base font-semibold text-[#2D3748] mb-4">{editing ? 'Edit Threat' : 'Add Threat'}</h3>
                    <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="md:col-span-2">
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Threat Name</label>
                            <input type="text" value={data.threat_name} onChange={e => setData('threat_name', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            <Err m={errors.threat_name} />
                        </div>
                        <div className="md:col-span-2">
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Description</label>
                            <textarea value={data.description} onChange={e => setData('description', e.target.value)} rows={2}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Source</label>
                            <select value={data.threat_source} onChange={e => setData('threat_source', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="internal">Internal</option>
                                <option value="external">External</option>
                                <option value="natural">Natural</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Category</label>
                            <select value={data.threat_category} onChange={e => setData('threat_category', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="technical">Technical</option>
                                <option value="human">Human</option>
                                <option value="environmental">Environmental</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Likelihood</label>
                            <select value={data.likelihood} onChange={e => setData('likelihood', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="high">High</option>
                                <option value="moderate">Moderate</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Impact</label>
                            <select value={data.impact} onChange={e => setData('impact', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="high">High</option>
                                <option value="moderate">Moderate</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Mitigating Controls</label>
                            <textarea value={data.mitigating_controls_desc} onChange={e => setData('mitigating_controls_desc', e.target.value)} rows={2}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Residual risk score after controls (1–9)</label>
                            <input type="number" min="1" max="9" value={data.residual_risk_score} onChange={e => setData('residual_risk_score', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            <Err m={errors.residual_risk_score} />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Comment</label>
                            <input type="text" value={data.comment} onChange={e => setData('comment', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        </div>
                        <div className="md:col-span-2 text-xs text-[#718096]">
                            Inherent score: <strong className="text-[#2D3748]">{VALUE[data.likelihood] * VALUE[data.impact]}</strong> (likelihood × impact)
                            {Object.keys(errors).length > 0 && <span className="ml-3 text-[#C53030]">Please fix the highlighted fields.</span>}
                        </div>
                        <div className="md:col-span-2 flex gap-2">
                            <button type="submit" disabled={processing}
                                className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A] disabled:opacity-50">
                                {editing ? 'Update' : 'Add'} Threat
                            </button>
                            <button type="button" onClick={() => { setShowForm(false); reset(); }}
                                className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                        </div>
                    </form>
                </div>
            )}

            {/* Threat List */}
            <div className="space-y-3">
                {shown.map(t => (
                    <div key={t.id} className="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <div className="flex items-center gap-2 mb-1">
                                    <h4 className="text-sm font-semibold text-[#2D3748]">{t.threat_name}</h4>
                                    <span className="px-2 py-0.5 text-xs font-bold text-white rounded"
                                        style={{ backgroundColor: riskColor(t.inherent_risk_score) }}>
                                        Risk: {t.inherent_risk_score}
                                    </span>
                                </div>
                                {t.description && <p className="text-xs text-[#718096] mb-2">{t.description}</p>}
                                <p className={`text-xs mb-2 ${t.mitigating_controls_desc ? 'text-[#2D3748]' : 'text-[#C53030]'}`}>
                                    Mitigating controls: {t.mitigating_controls_desc || 'none documented'}
                                </p>
                                <div className="flex gap-4 text-xs text-[#718096]">
                                    <span>Source: <strong className="text-[#2D3748]">{humanize(t.threat_source)}</strong></span>
                                    <span>Category: <strong className="text-[#2D3748]">{humanize(t.threat_category)}</strong></span>
                                    <span>Likelihood: <strong className="text-[#2D3748]">{humanize(t.likelihood)}</strong></span>
                                    <span>Impact: <strong className="text-[#2D3748]">{humanize(t.impact)}</strong></span>
                                    {t.residual_risk_score && <span>Residual: <strong className="text-[#2D3748]">{t.residual_risk_score}</strong></span>}
                                    {t.created_by_name && <span>Added by: {t.created_by_name}</span>}
                                </div>
                            </div>
                            {editable && <div className="flex gap-1 ml-4">
                                <button onClick={() => openEdit(t)} className="px-2 py-1 text-xs text-[#1A365D] hover:bg-gray-50 rounded">Edit</button>
                                <button onClick={() => destroy(t.id)} className="px-2 py-1 text-xs text-[#C53030] hover:bg-red-50 rounded">Remove</button>
                            </div>}
                        </div>
                    </div>
                ))}
                {shown.length === 0 && !showForm && (
                    <div className="bg-white rounded-xl border border-gray-100 p-8 shadow-sm text-center">
                        <p className="text-sm text-[#718096]">No threats registered yet. Add threats from the catalogue or create custom ones.</p>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

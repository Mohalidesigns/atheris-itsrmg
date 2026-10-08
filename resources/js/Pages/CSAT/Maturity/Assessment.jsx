import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { LockClosedIcon } from '@heroicons/react/24/outline';
import { MATURITY_COLORS, MATURITY_LABELS, lockMessage } from '@/Utils/csat';

function CommentBox({ disabled, initial, onSave }) {
    const [value, setValue] = useState(initial || '');
    return (
        <input type="text" value={value} disabled={disabled} placeholder="Comment / evidence reference"
            onChange={(e) => setValue(e.target.value)}
            onBlur={() => value !== (initial || '') && onSave(value)}
            className="flex-1 min-w-[200px] text-xs border-gray-200 rounded-lg py-1 disabled:bg-gray-50" />
    );
}

function CompensatingControlModal({ assessment, response, onClose }) {
    const existing = response?.compensating_control;
    const { data, setData, post, processing, errors } = useForm({
        response_id: response?.id,
        control_name: existing?.control_name || '',
        control_description: existing?.control_description || '',
        effectiveness_level: existing?.effectiveness_level || 'medium',
        planned_permanent_date: existing?.planned_permanent_date ? String(existing.planned_permanent_date).slice(0, 10) : '',
    });
    const save = (e) => {
        e.preventDefault();
        post(route('csat.ma.cc.save', assessment.id), { preserveScroll: true, onSuccess: onClose });
    };
    const err = (k) => errors[k] && <p className="text-xs text-[#C53030] mt-1">{errors[k]}</p>;
    return (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50" onClick={onClose}>
            <form onSubmit={save} className="bg-white rounded-xl p-6 w-full max-w-lg shadow-xl" onClick={e => e.stopPropagation()}>
                <h3 className="text-lg font-semibold text-[#2D3748]">Compensating Control</h3>
                <p className="text-xs text-[#718096] mb-4">Required for every Yes [CC] answer (BR-MA-03) — the answer counts as met only while this control stands in for the permanent fix.</p>
                <div className="space-y-3">
                    <div>
                        <label className="block text-sm font-medium text-[#2D3748] mb-1">Control name *</label>
                        <input type="text" value={data.control_name} onChange={e => setData('control_name', e.target.value)} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        {err('control_name')}{err('response_id')}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-[#2D3748] mb-1">Description *</label>
                        <textarea value={data.control_description} onChange={e => setData('control_description', e.target.value)} rows={3} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                        {err('control_description')}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Effectiveness *</label>
                            <select value={data.effectiveness_level} onChange={e => setData('effectiveness_level', e.target.value)} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                <option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-[#2D3748] mb-1">Permanent fix date *</label>
                            <input type="date" value={data.planned_permanent_date} onChange={e => setData('planned_permanent_date', e.target.value)} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            {err('planned_permanent_date')}
                        </div>
                    </div>
                </div>
                <div className="flex justify-end gap-2 mt-4">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">Later</button>
                    <button type="submit" disabled={processing} className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg disabled:opacity-50">{processing ? 'Saving…' : 'Save control'}</button>
                </div>
            </form>
        </div>
    );
}

const responseOptions = [
    { value: 'yes', label: 'Yes', color: '#2D7D46', bg: 'bg-green-50 border-green-300' },
    { value: 'yes_cc', label: 'Yes (CC)', color: '#D4AF37', bg: 'bg-yellow-50 border-yellow-300' },
    { value: 'no', label: 'No', color: '#C53030', bg: 'bg-red-50 border-red-300' },
    { value: 'na', label: 'N/A', color: '#718096', bg: 'bg-gray-50 border-gray-300' },
];

export default function MaturityAssessment({ assessment, statements, responses, domainNames, domainProgress = {}, currentDomain, editable = true }) {
    const activeDomain = currentDomain || 1;
    const [expandedComponent, setExpandedComponent] = useState(null);
    const [saving, setSaving] = useState(null);
    const [ccFor, setCcFor] = useState(null); // statement id whose compensating control is being captured

    const hierarchy = useMemo(() => {
        const tree = {};
        (statements || []).forEach(s => {
            if (!tree[s.domain_code]) tree[s.domain_code] = { factors: {} };
            if (!tree[s.domain_code].factors[s.factor_code]) tree[s.domain_code].factors[s.factor_code] = { name: s.factor_name, components: {} };
            if (!tree[s.domain_code].factors[s.factor_code].components[s.component_code])
                tree[s.domain_code].factors[s.factor_code].components[s.component_code] = { name: s.component_name, statements: [] };
            tree[s.domain_code].factors[s.factor_code].components[s.component_code].statements.push(s);
        });
        return tree;
    }, [statements]);

    // Comments are only sent when edited, so changing an answer never erases them.
    const saveResponse = (statementId, response, comment) => {
        setSaving(statementId);
        router.post(route('csat.ma.response.save', assessment.id), {
            statement_id: statementId, response, ...(comment !== undefined ? { comment } : {}),
        }, {
            preserveScroll: true,
            onFinish: () => setSaving(null),
            onSuccess: () => { if (response === 'yes_cc') setCcFor(statementId); },
        });
    };

    const changeDomain = (code) => {
        setExpandedComponent(null);
        router.get(route('csat.ma.assessment', assessment.id), { domain: code }, { preserveScroll: true });
    };

    const ccResponse = ccFor ? responses[ccFor] : null;

    const domainTree = hierarchy[activeDomain];

    return (
        <AuthenticatedLayout header="Maturity Assessment Tool">
            <Head title="Maturity Assessment" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.ma.dashboard', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Maturity Dashboard</Link>
                <span className="text-xs text-[#718096]">Levels: {[1, 2, 3, 4, 5].map(l => <span key={l} className="ml-2"><span className="inline-block w-2 h-2 rounded-full mr-1" style={{ backgroundColor: MATURITY_COLORS[l] }} />{MATURITY_LABELS[l]}</span>)}</span>
            </div>
            {!editable && (
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <LockClosedIcon className="w-4 h-4" /> {lockMessage(assessment)}
                </div>
            )}

            <div className="flex gap-6">
                {/* Domain Sidebar */}
                <div className="w-60 shrink-0">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm sticky top-20">
                        <div className="p-3">
                            <p className="text-xs text-[#718096] uppercase font-medium mb-2">Domains</p>
                            {Object.entries(domainNames || {}).map(([code, name]) => {
                                const dCode = parseInt(code);
                                const stat = domainProgress[dCode] || { total: 0, answered: 0 };
                                return (
                                    <button key={code} onClick={() => changeDomain(dCode)}
                                        className={`w-full text-left px-3 py-2 rounded-lg text-sm mb-1 transition-colors ${
                                            activeDomain === dCode ? 'bg-[#1A365D] text-white' : 'text-[#2D3748] hover:bg-gray-50'
                                        }`}>
                                        <span className="block truncate text-xs">{name}</span>
                                        <span className={`text-xs ${activeDomain === dCode ? 'text-white/70' : 'text-[#718096]'}`}>
                                            {stat.answered}/{stat.total}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Statements Area */}
                <div className="flex-1 space-y-4">
                    <h2 className="text-lg font-semibold text-[#2D3748]">{domainNames[activeDomain]}</h2>

                    {domainTree && Object.entries(domainTree.factors).map(([fCode, factor]) => (
                        <div key={fCode} className="space-y-3">
                            <h3 className="text-sm font-semibold text-[#1A365D] border-b border-gray-200 pb-1">{factor.name}</h3>

                            {Object.entries(factor.components).map(([cCode, comp]) => {
                                const isExpanded = expandedComponent === cCode;
                                const compAnswered = comp.statements.filter(s => responses[s.id]).length;
                                return (
                                    <div key={cCode} className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                                        <button onClick={() => setExpandedComponent(isExpanded ? null : cCode)}
                                            className="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50">
                                            <div>
                                                <span className="text-sm font-medium text-[#2D3748]">{comp.name}</span>
                                                <span className="ml-2 text-xs text-[#718096]">{compAnswered}/{comp.statements.length}</span>
                                            </div>
                                            <svg className={`w-4 h-4 text-[#718096] transition-transform ${isExpanded ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>

                                        {isExpanded && (
                                            <div className="border-t border-gray-100 divide-y divide-gray-50">
                                                {comp.statements.map(stmt => {
                                                    const resp = responses[stmt.id];
                                                    const selectedResp = resp?.response;
                                                    return (
                                                        <div key={stmt.id} className="px-4 py-3">
                                                            <div className="flex items-start gap-3 mb-2">
                                                                <span className="text-xs font-bold text-white w-5 h-5 rounded-full flex items-center justify-center shrink-0"
                                                                    title={MATURITY_LABELS[stmt.maturity_level]}
                                                                    style={{ backgroundColor: MATURITY_COLORS[stmt.maturity_level] || '#718096' }}>
                                                                    {stmt.maturity_level}
                                                                </span>
                                                                <p className="text-sm text-[#2D3748]">{stmt.statement_text}</p>
                                                            </div>
                                                            <div className="flex flex-wrap items-center gap-2 ml-8">
                                                                {responseOptions.map(opt => (
                                                                    <button key={opt.value}
                                                                        onClick={() => opt.value !== selectedResp && saveResponse(stmt.id, opt.value)}
                                                                        disabled={!editable || saving === stmt.id}
                                                                        className={`px-3 py-1.5 rounded-lg border text-xs font-semibold transition-all ${
                                                                            selectedResp === opt.value
                                                                                ? `${opt.bg} border-2 shadow-sm`
                                                                                : 'border-gray-200 text-[#2D3748] hover:bg-gray-50'
                                                                        }`}
                                                                        style={selectedResp === opt.value ? { color: opt.color } : {}}>
                                                                        {opt.label}
                                                                    </button>
                                                                ))}
                                                                {selectedResp === 'yes_cc' && resp && (
                                                                    <button onClick={() => setCcFor(stmt.id)}
                                                                        className={`px-3 py-1.5 rounded-lg border text-xs font-semibold ${resp.compensating_control ? 'border-green-300 text-green-700 bg-green-50' : 'border-red-300 text-red-700 bg-red-50 animate-pulse'}`}>
                                                                        {resp.compensating_control ? `CC: ${resp.compensating_control.control_name.slice(0, 30)}` : 'CC required — document'}
                                                                    </button>
                                                                )}
                                                                {resp && (
                                                                    <CommentBox key={`${stmt.id}-${resp.updated_at}`} disabled={!editable} initial={resp.comment}
                                                                        onSave={(comment) => saveResponse(stmt.id, selectedResp, comment)} />
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    ))}

                    {!domainTree && (
                        <div className="bg-white rounded-xl border border-gray-100 p-8 shadow-sm text-center">
                            <p className="text-sm text-[#718096]">Select a domain to begin assessment.</p>
                        </div>
                    )}
                </div>
            </div>

            {ccResponse && editable && (
                <CompensatingControlModal key={ccResponse.id} assessment={assessment} response={ccResponse} onClose={() => setCcFor(null)} />
            )}
        </AuthenticatedLayout>
    );
}

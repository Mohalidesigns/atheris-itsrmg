import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';

const responseOptions = [
    { value: 'yes', label: 'Yes', color: '#2D7D46', bg: 'bg-green-50 border-green-300' },
    { value: 'yes_cc', label: 'Yes (CC)', color: '#D4AF37', bg: 'bg-yellow-50 border-yellow-300' },
    { value: 'no', label: 'No', color: '#C53030', bg: 'bg-red-50 border-red-300' },
    { value: 'na', label: 'N/A', color: '#718096', bg: 'bg-gray-50 border-gray-300' },
];

const maturityColors = { 1: '#C53030', 2: '#DD6B20', 3: '#D4AF37', 4: '#319795', 5: '#1A365D' };

export default function MaturityAssessment({ assessment, statements, responses, domainNames, maturityLevels, currentDomain }) {
    const [activeDomain, setActiveDomain] = useState(currentDomain || 1);
    const [expandedComponent, setExpandedComponent] = useState(null);
    const [saving, setSaving] = useState(null);
    const [ccModal, setCcModal] = useState(null);
    const [ccForm, setCcForm] = useState({ control_name: '', control_description: '', effectiveness_level: 'medium', planned_permanent_date: '' });

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

    const domainStats = useMemo(() => {
        const stats = {};
        Object.entries(domainNames || {}).forEach(([code]) => {
            const dCode = parseInt(code);
            const domainStmts = (statements || []).filter(s => s.domain_code === dCode);
            const answered = domainStmts.filter(s => responses[s.id]).length;
            stats[dCode] = { total: domainStmts.length, answered };
        });
        return stats;
    }, [statements, responses, domainNames]);

    const saveResponse = (statementId, response) => {
        setSaving(statementId);
        router.post(route('csat.ma.response.save', assessment.id), {
            statement_id: statementId, response, comment: null,
        }, { preserveScroll: true, onFinish: () => setSaving(null) });
    };

    const saveCc = () => {
        router.post(route('csat.ma.cc.save', assessment.id), {
            response_id: ccModal, ...ccForm,
        }, { preserveScroll: true, onFinish: () => setCcModal(null) });
    };

    const changeDomain = (code) => {
        setActiveDomain(code);
        router.get(route('csat.ma.assessment', assessment.id), { domain: code }, { preserveScroll: true, preserveState: true });
    };

    const domainTree = hierarchy[activeDomain];

    return (
        <AuthenticatedLayout header="Maturity Assessment Tool">
            <Head title="Maturity Assessment" />

            <div className="mb-4 flex items-center justify-between">
                <Link href={route('csat.ma.dashboard', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Maturity Dashboard</Link>
            </div>

            <div className="flex gap-6">
                {/* Domain Sidebar */}
                <div className="w-60 shrink-0">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm sticky top-20">
                        <div className="p-3">
                            <p className="text-xs text-[#718096] uppercase font-medium mb-2">Domains</p>
                            {Object.entries(domainNames || {}).map(([code, name]) => {
                                const dCode = parseInt(code);
                                const stat = domainStats[dCode] || { total: 0, answered: 0 };
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
                                                                    style={{ backgroundColor: maturityColors[stmt.maturity_level] || '#718096' }}>
                                                                    {stmt.maturity_level}
                                                                </span>
                                                                <p className="text-sm text-[#2D3748]">{stmt.statement_text}</p>
                                                            </div>
                                                            <div className="flex gap-2 ml-8">
                                                                {responseOptions.map(opt => (
                                                                    <button key={opt.value}
                                                                        onClick={() => saveResponse(stmt.id, opt.value)}
                                                                        disabled={saving === stmt.id}
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
                                                                    <button onClick={() => { setCcModal(resp.id); setCcForm({ control_name: '', control_description: '', effectiveness_level: 'medium', planned_permanent_date: '' }); }}
                                                                        className="px-3 py-1.5 rounded-lg border border-[#D4AF37] text-xs font-semibold text-[#D4AF37] hover:bg-[#D4AF37]/10">
                                                                        + Comp. Control
                                                                    </button>
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

            {/* Compensating Control Modal */}
            {ccModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50" onClick={() => setCcModal(null)}>
                    <div className="bg-white rounded-xl p-6 w-full max-w-lg shadow-xl" onClick={e => e.stopPropagation()}>
                        <h3 className="text-lg font-semibold text-[#2D3748] mb-4">Compensating Control</h3>
                        <div className="space-y-3">
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">Control Name</label>
                                <input type="text" value={ccForm.control_name} onChange={e => setCcForm({ ...ccForm, control_name: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">Description</label>
                                <textarea value={ccForm.control_description} onChange={e => setCcForm({ ...ccForm, control_description: e.target.value })}
                                    rows={3} className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">Effectiveness</label>
                                <select value={ccForm.effectiveness_level} onChange={e => setCcForm({ ...ccForm, effectiveness_level: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                    <option value="high">High</option>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-[#2D3748] mb-1">Planned Permanent Fix Date</label>
                                <input type="date" value={ccForm.planned_permanent_date} onChange={e => setCcForm({ ...ccForm, planned_permanent_date: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                            </div>
                        </div>
                        <div className="flex justify-end gap-2 mt-4">
                            <button onClick={() => setCcModal(null)} className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50">Cancel</button>
                            <button onClick={saveCc} className="px-4 py-2 text-sm font-semibold text-white bg-[#1A365D] rounded-lg hover:bg-[#2D4A7A]">Save Control</button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

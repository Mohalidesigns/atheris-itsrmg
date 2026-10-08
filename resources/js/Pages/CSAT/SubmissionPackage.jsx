import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { PrinterIcon } from '@heroicons/react/24/outline';
import { IR_LEVEL_COLORS, LICENCE_LABELS, MATURITY_COLORS, STATUS_LABELS, formatDate, humanize } from '@/Utils/csat';

function Section({ letter, title, children }) {
    return (
        <section className="mb-8 break-inside-avoid">
            <h2 className="text-base font-bold text-[#0A1F44] border-b-2 border-[#C9A86A] pb-1 mb-3">({letter}) {title}</h2>
            {children}
        </section>
    );
}

const th = 'text-left py-1.5 px-2 text-[11px] uppercase text-[#718096] font-semibold border-b border-gray-200';
const td = 'py-1.5 px-2 text-xs border-b border-gray-100 align-top';

export default function SubmissionPackage({ assessment, profile, stakeholders = [], ir, domainScores = [], factorScores = [], threats = [], vulnerabilities = [], approvalRecords = [], stages = [], checklist = [], maturityLevels = {} }) {
    // Signatories come from the current submission cycle only (a returned cycle's signatures lapse).
    const lastSubmit = [...approvalRecords].reverse().find(r => r.stage_number === 1 && r.action === 'approved');
    const cycle = lastSubmit ? approvalRecords.filter(r => r.id >= lastSubmit.id) : [];
    const returned = cycle.some(r => r.action !== 'approved');
    const signedOff = returned ? [] : cycle.filter(r => r.action === 'approved' && r.stage_number > 1);
    const ready = checklist.every(i => i.done);

    return (
        <AuthenticatedLayout header="CBN Submission Package">
            <Head title={`CBN-CSAT ${assessment.assessment_year} Submission Package`} />

            <div className="mb-4 flex items-center justify-between print:hidden">
                <Link href={route('csat.workflow', assessment.id)} className="text-sm text-[#1A365D] hover:underline">&larr; Back to Workflow</Link>
                <button onClick={() => window.print()} className="inline-flex items-center gap-1 px-3 py-2 text-sm rounded-lg bg-[#1A365D] text-white">
                    <PrinterIcon className="w-4 h-4" /> Print / Save as PDF
                </button>
            </div>
            {!ready && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 print:hidden">
                    Draft package — {checklist.filter(i => !i.done).length} checklist item(s) are still outstanding, so this cannot yet be lodged with the CBN.
                </div>
            )}

            <article className="bg-white rounded-xl border border-gray-100 shadow-sm p-10 max-w-4xl mx-auto print:shadow-none print:border-0 print:p-0">
                {/* Cover letter (Sheet 8) */}
                <section className="mb-10 text-sm text-[#2D3748] leading-relaxed break-after-page">
                    <p className="text-right text-xs text-[#718096]">{formatDate(assessment.submitted_at || new Date())}</p>
                    <p className="mt-6">The Director,<br />Banking Supervision Department,<br />Central Bank of Nigeria,<br />Abuja.</p>
                    <p className="mt-6 font-semibold">SUBMISSION OF {assessment.assessment_year} CYBERSECURITY SELF-ASSESSMENT — {profile?.institution_name?.toUpperCase() || 'THE INSTITUTION'}</p>
                    <p className="mt-4">
                        In compliance with Section 3.9.3 of the CBN Risk-Based Cybersecurity Framework and Guidelines, {profile?.institution_name || 'the institution'}
                        {profile?.cbn_licence_type ? ` (${LICENCE_LABELS[profile.cbn_licence_type] || humanize(profile.cbn_licence_type)})` : ''} hereby submits its cybersecurity self-assessment for {assessment.assessment_year}.
                    </p>
                    <p className="mt-3">
                        The assessment rates the institution's composite inherent risk as <strong>{ir?.level ? humanize(ir.level) : 'not rated'}</strong> ({Number(ir?.score || 0).toFixed(2)})
                        and its overall cybersecurity maturity as <strong>{humanize(assessment.overall_maturity_level)}</strong>. Supporting results, registers and the internal approval record are enclosed.
                    </p>
                    <p className="mt-6">Yours faithfully,</p>
                    <div className="mt-6 grid grid-cols-2 gap-8">
                        {signedOff.slice(-2).map(r => (
                            <div key={r.id}>
                                <p className="font-semibold">{r.approver_name}</p>
                                <p className="text-xs text-[#718096]">{r.approver_role} · signed {formatDate(r.actioned_at)}</p>
                            </div>
                        ))}
                        {signedOff.length === 0 && <p className="text-xs text-[#718096]">Awaiting authorised signatories.</p>}
                    </div>
                </section>

                <Section letter="a" title="Assessment summary">
                    <table className="w-full"><tbody>
                        {[
                            ['Institution', profile?.institution_name], ['Licence type', LICENCE_LABELS[profile?.cbn_licence_type] || humanize(profile?.cbn_licence_type)],
                            ['Head office', profile?.head_office_address], ['CISO', profile ? `${profile.ciso_name} · ${profile.ciso_email} · ${profile.ciso_phone}` : null],
                            ['CISO reporting line', profile?.ciso_reporting_line], ['Assessment year / framework', `${assessment.assessment_year} · ${assessment.framework_version}`],
                            ['Status', STATUS_LABELS[assessment.status]], ['CBN deadline', formatDate(assessment.submission_deadline)],
                        ].map(([k, v]) => <tr key={k}><td className={`${td} w-48 text-[#718096]`}>{k}</td><td className={td}>{v || '—'}</td></tr>)}
                    </tbody></table>
                    <h3 className="text-xs font-semibold text-[#2D3748] mt-4 mb-1">Stakeholder engagement</h3>
                    <table className="w-full"><thead><tr><th className={th}>Role</th><th className={th}>Name</th><th className={th}>Engagement</th><th className={th}>Comment</th></tr></thead>
                        <tbody>{stakeholders.map(s => <tr key={s.id}><td className={td}>{s.role_label}</td><td className={td}>{s.name_of_person || '—'}</td><td className={td}>{humanize(s.engagement_status)}</td><td className={td}>{s.comment || ''}</td></tr>)}</tbody>
                    </table>
                </Section>

                <Section letter="b" title="Inherent risk results">
                    <p className="text-xs mb-2">Composite: <strong style={{ color: IR_LEVEL_COLORS[ir?.level] }}>{humanize(ir?.level)} ({Number(ir?.score || 0).toFixed(3)})</strong> · {ir?.answered}/{ir?.total} questions answered</p>
                    <table className="w-full"><thead><tr><th className={th}>Category</th><th className={th}>Answered</th><th className={th}>Average</th><th className={th}>Level</th></tr></thead>
                        <tbody>{(ir?.category_scores || []).map(c => <tr key={c.category_code}><td className={td}>{c.category_name}</td><td className={td}>{c.answered_count}/{c.question_count}</td><td className={td}>{Number(c.average_score).toFixed(2)}</td><td className={td}>{humanize(c.risk_level)}</td></tr>)}</tbody>
                    </table>
                </Section>

                <Section letter="c" title="Maturity results">
                    <table className="w-full"><thead><tr><th className={th}>Domain / factor</th><th className={th}>Achieved</th><th className={th}>Target</th><th className={th}>Answered</th></tr></thead>
                        <tbody>
                            {domainScores.map(d => [
                                <tr key={d.scope_code} className="bg-gray-50"><td className={`${td} font-semibold`}>{d.scope_code} {d.scope_name}</td>
                                    <td className={td} style={{ color: MATURITY_COLORS[d.achieved_maturity_level] }}>{maturityLevels[d.achieved_maturity_level]}</td>
                                    <td className={td}>{d.target_maturity_level ? maturityLevels[d.target_maturity_level] : '—'}</td><td className={td}>{Number(d.completion_pct).toFixed(0)}%</td></tr>,
                                ...factorScores.filter(f => f.scope_code.startsWith(`${d.scope_code}-`)).map(f => (
                                    <tr key={f.scope_code}><td className={`${td} pl-6`}>{f.scope_name}</td><td className={td}>{maturityLevels[f.achieved_maturity_level]}</td><td className={td} /><td className={td}>{Number(f.completion_pct).toFixed(0)}%</td></tr>
                                )),
                            ])}
                        </tbody>
                    </table>
                </Section>

                <Section letter="d" title="Threat and vulnerability register">
                    <table className="w-full mb-4"><thead><tr><th className={th}>Threat</th><th className={th}>Source</th><th className={th}>L × I</th><th className={th}>Score</th><th className={th}>Mitigating controls</th></tr></thead>
                        <tbody>{threats.map(t => <tr key={t.id}><td className={td}>{t.threat_name}</td><td className={td}>{humanize(t.threat_source)}</td><td className={td}>{humanize(t.likelihood)} × {humanize(t.impact)}</td><td className={td}>{t.inherent_risk_score}</td><td className={td}>{t.mitigating_controls_desc || '—'}</td></tr>)}</tbody>
                    </table>
                    <table className="w-full"><thead><tr><th className={th}>Vulnerability</th><th className={th}>Category</th><th className={th}>Score</th><th className={th}>Status</th><th className={th}>Owner / due</th></tr></thead>
                        <tbody>{vulnerabilities.map(v => <tr key={v.id}><td className={td}>{v.vulnerability_name}</td><td className={td}>{humanize(v.vulnerability_category)}</td><td className={td}>{v.composite_score}</td><td className={td}>{humanize(v.remediation_status)}</td><td className={td}>{v.assignee?.name || '—'} · {formatDate(v.due_date)}</td></tr>)}</tbody>
                    </table>
                </Section>

                <Section letter="e" title="Attestation">
                    <p className="text-xs mb-2">Internal approval routing — each stage signed by a different officer (HMAC-SHA256 signature tokens).</p>
                    <table className="w-full"><thead><tr><th className={th}>Stage</th><th className={th}>Officer</th><th className={th}>Action</th><th className={th}>Date</th><th className={th}>Signature</th></tr></thead>
                        <tbody>{approvalRecords.map(r => <tr key={r.id}><td className={td}>{stages.find(s => s.number === r.stage_number)?.name || r.stage_number}</td><td className={td}>{r.approver_name} ({r.approver_role})</td><td className={td}>{humanize(r.action)}</td><td className={td}>{formatDate(r.actioned_at, true)}</td><td className={`${td} font-mono text-[10px] break-all`}>{r.digital_signature_token?.slice(0, 32)}</td></tr>)}</tbody>
                    </table>
                    {approvalRecords.length === 0 && <p className="text-xs text-[#718096]">Not yet submitted for approval.</p>}
                </Section>

                <Section letter="f" title="Supporting evidence index (Items to Submit)">
                    <ul className="text-xs space-y-1">
                        {checklist.map(i => <li key={i.key}>{i.done ? '✔' : '✘'} {i.label} — <span className="text-[#718096]">{i.detail}</span></li>)}
                    </ul>
                </Section>
            </article>
        </AuthenticatedLayout>
    );
}

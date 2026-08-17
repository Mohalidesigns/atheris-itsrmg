import StatusBadge from '@/Components/StatusBadge';
import QualitySealBadge from '@/Components/Ea/QualitySealBadge';
import { Link } from '@inertiajs/react';

/**
 * The EA view of a physical asset — ATH-EAR-002 §7.4, contract I-3.
 *
 * §7.2 splits ownership deliberately: EA is master for the *logical*
 * application, the Assets module for the *physical* instance, hard-linked via
 * `asset_id`. This panel answers the question an asset owner cannot otherwise
 * answer from their own screen: what business capability does this serve, how
 * exposed is it, and is anything running on it out of support.
 */
export default function EaAssetPanel({ ea }) {
    if (!ea) {
        return (
            <div className="rounded-xl border border-dashed border-gray-200 bg-white p-5">
                <h3 className="text-sm font-semibold text-[#0A1F44]">Enterprise Architecture</h3>
                <p className="mt-2 text-xs text-[#718096]">
                    This asset is not linked to an application in the architecture repository, so no capability,
                    criticality or control-coverage context is available for it. Link it from the Application
                    Portfolio to see what it supports and what breaks without it.
                </p>
            </div>
        );
    }

    const { application: app, control_coverage: coverage } = ea;

    return (
        <div className="rounded-xl border border-gray-100 bg-white shadow-sm">
            <div className="flex items-start justify-between border-b border-gray-100 px-5 py-4">
                <div>
                    <h3 className="text-sm font-semibold text-[#0A1F44]">Enterprise Architecture</h3>
                    <Link href={app.href} className="text-xs text-[#0A1F44] hover:underline">
                        {app.code} — {app.name}
                    </Link>
                </div>
                <QualitySealBadge seal={ea.seal} showCompleteness />
            </div>

            <div className="grid grid-cols-2 gap-4 px-5 py-4 md:grid-cols-4">
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Criticality</p>
                    <div className="mt-1">
                        <StatusBadge
                            status={
                                app.criticality === 'critical'
                                    ? 'critical'
                                    : app.criticality === 'high'
                                      ? 'high'
                                      : 'moderate'
                            }
                            label={app.criticality}
                        />
                    </div>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Lifecycle</p>
                    <p className="mt-1 text-sm capitalize text-[#2D3748]">{app.lifecycle || '—'}</p>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">TIME</p>
                    <p className="mt-1 text-sm text-[#2D3748]">{app.time_score || '—'}</p>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Fit (B/T)</p>
                    <p className="mt-1 text-sm text-[#2D3748]">
                        {app.business_fit || '—'} / {app.technical_fit || '—'}
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 border-t border-gray-100 px-5 py-4 md:grid-cols-3">
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Supports capabilities</p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        {ea.capabilities?.length
                            ? ea.capabilities.map((c) => c.name).join(', ')
                            : 'Not mapped to any capability'}
                    </p>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Security zone</p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        {ea.zone ? `${ea.zone.code} — ${ea.zone.name}` : 'Not assigned to a zone'}
                    </p>
                </div>
                <div>
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Connections</p>
                    <p className="mt-1 text-xs text-[#2D3748]">
                        {ea.interface_count} documented connection{ea.interface_count === 1 ? '' : 's'}
                    </p>
                </div>
            </div>

            {ea.tech_components?.length > 0 && (
                <div className="border-t border-gray-100 px-5 py-4">
                    <p className="text-[10px] uppercase tracking-wide text-[#718096]">Technology running on it</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {ea.tech_components.map((t) => (
                            <span
                                key={t.id}
                                className={`rounded-full border px-2 py-0.5 text-[11px] ${
                                    t.obsolescence_flag
                                        ? 'border-[#B3261E]/30 bg-[#B3261E]/10 text-[#B3261E]'
                                        : 'border-gray-200 bg-white text-[#718096]'
                                }`}
                                title={t.eol_date ? `End of life ${t.eol_date}` : undefined}
                            >
                                {t.name}
                                {t.obsolescence_flag ? ' · EOL' : ''}
                            </span>
                        ))}
                    </div>
                    {ea.obsolete_components > 0 && (
                        <p className="mt-2 text-xs text-[#B3261E]">
                            {ea.obsolete_components} component(s) past or approaching end of life. An obsolescence
                            risk has been opened in the risk register.
                        </p>
                    )}
                </div>
            )}

            {coverage && coverage.mapped > 0 && (
                <div className="border-t border-gray-100 bg-[#F7FAFC] px-5 py-3">
                    <p className="text-xs text-[#2D3748]">
                        Control coverage:{' '}
                        <span className="font-semibold">{coverage.citable_percent}% citable</span>{' '}
                        <span className="text-[#718096]">
                            ({coverage.effective} of {coverage.mapped} mappings resolve to an implemented control
                            {coverage.dangling > 0 ? `; ${coverage.dangling} do not resolve at all` : ''}
                            {coverage.untested > 0 ? `; ${coverage.untested} never tested` : ''})
                        </span>
                    </p>
                </div>
            )}
        </div>
    );
}

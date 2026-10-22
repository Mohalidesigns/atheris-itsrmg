import Icon from '@/Components/Material/Icon';
import { router } from '@inertiajs/react';

/** Drill trail built from the server's breadcrumbs[]; the last crumb is the current level. */
export default function DrillBreadcrumbs({ crumbs = [] }) {
    if (crumbs.length === 0) return null;
    return (
        <nav aria-label="Drill path" className="mb-3 flex flex-wrap items-center gap-1 text-sm">
            {crumbs.map((c, i) => {
                const last = i === crumbs.length - 1;
                return (
                    <span key={i} className="flex items-center gap-1">
                        {i > 0 && <Icon name="chevron_right" size={16} className="text-[#A0AEC0]" />}
                        {last ? <span className="font-semibold text-[#0A1F44]">{c.label}</span>
                            : <button type="button" onClick={() => router.get(route('finance.monitor.bva'), c.params ?? {}, { preserveScroll: true })} className="text-[#1D4ED8] underline">{c.label}</button>}
                    </span>
                );
            })}
        </nav>
    );
}

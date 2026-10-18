import Icon from '@/Components/Material/Icon';
import { Link } from '@inertiajs/react';

/** Compact KPI tile. `value` is rendered as given (already formatted or masked); `href` makes it a link. */
export default function KpiTile({ label, value, sub, icon, tint = 'bg-[#EAF0FB] text-[#1D4ED8]', href, alert = false }) {
    const body = (
        <>
            <div className="min-w-0">
                <p className="truncate text-xs text-[#4A5568]">{label}</p>
                <p className={`mt-1 truncate text-xl font-semibold tabular-nums ${alert ? 'text-[#B3261E]' : 'text-[#1B1B1F]'}`}>{value}</p>
                {sub && <p className="mt-0.5 truncate text-[11px] text-[#718096]">{sub}</p>}
            </div>
            {icon && <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${tint}`}><Icon name={icon} size={20} /></span>}
        </>
    );
    const cls = 'flex items-start justify-between gap-2 rounded-xl border border-gray-100 bg-white p-4 text-left shadow-sm';
    return href ? <Link href={href} className={`${cls} transition hover:shadow`}>{body}</Link> : <div className={cls}>{body}</div>;
}

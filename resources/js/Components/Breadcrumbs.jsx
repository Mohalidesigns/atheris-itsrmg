import { Link } from '@inertiajs/react';
import { HomeIcon, ChevronRightIcon } from '@heroicons/react/24/outline';

/**
 * Breadcrumbs — consistent, clickable, collapsing navigation.
 * Pattern: Home > Module > Sub-module > Record > Sub-tab
 *
 * Usage:
 *   <Breadcrumbs items={[
 *     { label: 'Risk Management', href: route('risks.index') },
 *     { label: 'Risk Register', href: route('risks.index') },
 *     { label: risk.code },
 *   ]} />
 */
export default function Breadcrumbs({ items = [] }) {
    const crumbs = [{ label: 'Home', href: route('dashboard'), icon: HomeIcon }, ...items];
    // On small screens collapse middle crumbs behind "…"
    const collapsed = crumbs.length > 4
        ? [crumbs[0], { label: '…', href: null, collapsed: true }, crumbs[crumbs.length - 2], crumbs[crumbs.length - 1]]
        : crumbs;

    return (
        <nav aria-label="Breadcrumb" className="mb-3">
            <ol className="flex flex-wrap items-center gap-1 text-xs text-[#718096]">
                {collapsed.map((c, i) => {
                    const isLast = i === collapsed.length - 1;
                    const Icon = c.icon;
                    const inner = (
                        <span className={`inline-flex items-center gap-1 ${isLast ? 'text-[#0A1F44] font-semibold' : 'hover:text-[#0A1F44]'}`}>
                            {Icon && <Icon className="w-3.5 h-3.5" />}
                            <span className={c.collapsed ? 'hidden sm:inline' : ''}>{c.label}</span>
                        </span>
                    );
                    return (
                        <li key={i} className="flex items-center gap-1">
                            {c.href && !isLast ? (
                                <Link href={c.href} className="inline-flex items-center gap-1">{inner}</Link>
                            ) : inner}
                            {!isLast && <ChevronRightIcon className="w-3 h-3 text-[#A0AEC0]" />}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

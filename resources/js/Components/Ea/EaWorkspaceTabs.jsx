import { Link } from '@inertiajs/react';

/**
 * EaWorkspaceTabs — the workspace-level tab pattern from ATH-EAR-002 §5.5.
 *
 * The module presented 40 sibling sidebar links with no hierarchy. Navigation
 * is now grouped into 8 workspaces; this renders the peer surfaces inside the
 * current workspace so the user can move between "Interfaces", "APIs" and
 * "Impact" without going back to the sidebar. The definitions live in
 * Config/eaWorkspaces.js so the sidebar and the tab bar cannot drift apart.
 */
export default function EaWorkspaceTabs({ tabs = [], current }) {
    if (!tabs.length) return null;

    return (
        <div className="mb-5 flex flex-wrap items-center gap-1 border-b border-gray-200">
            {tabs.map((t) => {
                const active = t.href === current;
                return (
                    <Link
                        key={t.href}
                        href={route(t.href)}
                        className={`-mb-px border-b-2 px-3 py-2 text-xs font-medium transition ${
                            active
                                ? 'border-[#C9A86A] text-[#0A1F44]'
                                : 'border-transparent text-[#718096] hover:border-gray-300 hover:text-[#2D3748]'
                        }`}
                    >
                        {t.name}
                    </Link>
                );
            })}
        </div>
    );
}

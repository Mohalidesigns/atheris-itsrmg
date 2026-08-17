import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    ChevronDoubleLeftIcon,
    ChevronDoubleRightIcon,
    ShieldCheckIcon,
} from '@heroicons/react/24/outline';
import SidebarNavItem from './SidebarNavItem';
import navigation from '@/Config/navigation';

export default function Sidebar() {
    const [collapsed, setCollapsed] = useState(false);
    const { auth } = usePage().props;

    // Permission-based menu gating. Super Admin sees everything.
    const permissions = auth?.user?.permissions || [];
    const roles = auth?.user?.roles || [];
    const isSuperAdmin = Array.from(roles).includes('Super Admin');
    const can = (permission) => !permission || isSuperAdmin || permissions.includes(permission);

    const visibleNavigation = navigation
        .map((item) => {
            // Group-level permission hides the whole item/group.
            if (!can(item.permission)) return null;

            if (item.children) {
                const children = item.children.filter((child) => can(child.permission));
                if (children.length === 0) return null; // hide empty groups
                return { ...item, children };
            }

            return item;
        })
        .filter(Boolean);

    return (
        <aside
            className={`fixed left-0 top-0 h-screen bg-[#1A365D] flex flex-col z-30 transition-all duration-300 shadow-xl
                ${collapsed ? 'w-16' : 'w-64'}`}
        >
            {/* Logo */}
            <div className="flex items-center gap-3 px-4 py-4 border-b border-white/10">
                <div className="bg-[#D4AF37] p-1.5 rounded-lg shrink-0">
                    <ShieldCheckIcon className="w-6 h-6 text-[#1A365D]" />
                </div>
                {!collapsed && (
                    <div className="overflow-hidden">
                        <h1 className="text-white font-bold text-base leading-tight tracking-tight">
                            IT Risk Mgt
                        </h1>
                        <span className="text-[#D4AF37] text-xs font-medium">
                            GRC Suite
                        </span>
                    </div>
                )}
            </div>

            {/* Navigation */}
            <nav className="flex-1 overflow-y-auto scrollbar-thin py-3 px-2 space-y-1">
                {visibleNavigation.map((item) => (
                    <SidebarNavItem
                        key={item.name}
                        item={item}
                        collapsed={collapsed}
                    />
                ))}
            </nav>

            {/* Organization Badge */}
            {!collapsed && auth?.organization && (
                <div className="px-3 py-2 border-t border-white/10">
                    <div className="bg-white/5 rounded-lg px-3 py-2">
                        <p className="text-xs text-gray-400 uppercase tracking-wider">Organization</p>
                        <p className="text-sm text-white font-medium truncate">{auth.organization.name}</p>
                    </div>
                </div>
            )}

            {/* Collapse Toggle */}
            <div className="px-3 py-3 border-t border-white/10">
                <button
                    onClick={() => setCollapsed(!collapsed)}
                    className="flex items-center justify-center w-full py-1.5 rounded-lg text-gray-400 hover:text-white hover:bg-white/5 transition-colors"
                >
                    {collapsed ? (
                        <ChevronDoubleRightIcon className="w-5 h-5" />
                    ) : (
                        <ChevronDoubleLeftIcon className="w-5 h-5" />
                    )}
                </button>
            </div>
        </aside>
    );
}

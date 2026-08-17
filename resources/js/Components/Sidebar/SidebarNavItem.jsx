import { Link } from '@inertiajs/react';
import { ChevronDownIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';

export default function SidebarNavItem({ item, currentRoute, collapsed }) {
    const hasChildren = item.children && item.children.length > 0;
    const isActiveParent = hasChildren && item.children.some(child => {
        try { return route().current(child.href); } catch { return false; }
    });
    const isActive = !hasChildren && (() => {
        try { return route().current(item.href); } catch { return false; }
    })();

    const [open, setOpen] = useState(isActiveParent);

    const Icon = item.icon;

    if (!hasChildren) {
        return (
            <Link
                href={(() => { try { return route(item.href); } catch { return '#'; } })()}
                className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200
                    ${isActive
                        ? 'bg-[#D4AF37]/15 text-[#D4AF37] border-l-3 border-[#D4AF37]'
                        : 'text-gray-300 hover:bg-white/5 hover:text-white'
                    }`}
            >
                {Icon && <Icon className="w-5 h-5 shrink-0" />}
                {!collapsed && <span className="truncate">{item.name}</span>}
            </Link>
        );
    }

    return (
        <div>
            <button
                onClick={() => setOpen(!open)}
                className={`flex items-center justify-between w-full px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200
                    ${isActiveParent
                        ? 'text-[#D4AF37] bg-[#D4AF37]/5'
                        : 'text-gray-300 hover:bg-white/5 hover:text-white'
                    }`}
            >
                <div className="flex items-center gap-3">
                    {Icon && <Icon className="w-5 h-5 shrink-0" />}
                    {!collapsed && <span className="truncate">{item.name}</span>}
                </div>
                {!collapsed && (
                    <ChevronDownIcon
                        className={`w-4 h-4 shrink-0 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                    />
                )}
            </button>

            {!collapsed && open && (
                <div className="ml-4 mt-1 space-y-0.5 border-l border-gray-600/50 pl-4">
                    {item.children.map((child) => {
                        let childActive = false;
                        try { childActive = route().current(child.href); } catch {}
                        return (
                            <Link
                                key={child.href}
                                href={(() => { try { return route(child.href); } catch { return '#'; } })()}
                                className={`block py-1.5 px-2 rounded text-sm transition-colors duration-150
                                    ${childActive
                                        ? 'text-[#D4AF37] font-medium bg-[#D4AF37]/10'
                                        : 'text-gray-400 hover:text-gray-200 hover:bg-white/5'
                                    }`}
                            >
                                {child.name}
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

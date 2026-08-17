import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    BellIcon,
    MagnifyingGlassIcon,
    Bars3Icon,
    XMarkIcon,
} from '@heroicons/react/24/outline';
import Sidebar from '@/Components/Sidebar/Sidebar';
import Dropdown from '@/Components/Dropdown';

export default function AuthenticatedLayout({ header, children }) {
    const { auth, flash } = usePage().props;
    const user = auth.user;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);

    const initials = user?.name
        ? user.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
        : '??';

    return (
        <div className="min-h-screen bg-[#F7FAFC]">
            {/* Sidebar (hidden on mobile) */}
            <div className="hidden lg:block">
                <Sidebar />
            </div>

            {/* Mobile sidebar overlay */}
            {mobileMenuOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/50" onClick={() => setMobileMenuOpen(false)} />
                    <div className="fixed left-0 top-0 h-full w-64 z-50">
                        <Sidebar />
                    </div>
                </div>
            )}

            {/* Main Content Area */}
            <div className="lg:pl-64 transition-all duration-300">
                {/* Top Bar */}
                <header className="sticky top-0 z-20 bg-white border-b border-gray-200 shadow-sm">
                    <div className="flex items-center justify-between h-16 px-4 sm:px-6">
                        {/* Left: Mobile menu + Breadcrumb */}
                        <div className="flex items-center gap-3">
                            <button
                                onClick={() => setMobileMenuOpen(true)}
                                className="lg:hidden text-gray-500 hover:text-gray-700"
                            >
                                <Bars3Icon className="w-6 h-6" />
                            </button>

                            {header && (
                                <div className="text-lg font-semibold text-[#2D3748]">
                                    {header}
                                </div>
                            )}
                        </div>

                        {/* Right: Search, Notifications, User */}
                        <div className="flex items-center gap-2 sm:gap-4">
                            {/* Search */}
                            <button
                                onClick={() => setSearchOpen(!searchOpen)}
                                className="p-2 text-[#718096] hover:text-[#2D3748] hover:bg-gray-100 rounded-lg transition-colors"
                            >
                                <MagnifyingGlassIcon className="w-5 h-5" />
                            </button>

                            {/* Notifications */}
                            <button className="relative p-2 text-[#718096] hover:text-[#2D3748] hover:bg-gray-100 rounded-lg transition-colors">
                                <BellIcon className="w-5 h-5" />
                                <span className="absolute top-1 right-1 w-2 h-2 bg-[#C53030] rounded-full" />
                            </button>

                            {/* User Dropdown */}
                            <div className="relative">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button className="flex items-center gap-2 p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                                            <div className="w-8 h-8 bg-[#1A365D] rounded-full flex items-center justify-center">
                                                <span className="text-xs font-bold text-white">{initials}</span>
                                            </div>
                                            <div className="hidden sm:block text-left">
                                                <p className="text-sm font-medium text-[#2D3748] leading-tight">{user?.name}</p>
                                                <p className="text-xs text-[#718096] leading-tight">{user?.job_title || user?.roles?.[0] || 'User'}</p>
                                            </div>
                                        </button>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content align="right" width="48">
                                        <div className="px-4 py-2 border-b border-gray-100">
                                            <p className="text-sm font-medium text-[#2D3748]">{user?.name}</p>
                                            <p className="text-xs text-[#718096]">{user?.email}</p>
                                        </div>
                                        <Dropdown.Link href={route('profile.edit')}>
                                            Profile Settings
                                        </Dropdown.Link>
                                        <Dropdown.Link href={route('logout')} method="post" as="button">
                                            Sign Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>
                    </div>

                    {/* Search Bar (expandable) */}
                    {searchOpen && (
                        <div className="px-4 pb-3">
                            <div className="relative">
                                <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                                <input
                                    type="text"
                                    placeholder="Search risks, controls, incidents..."
                                    className="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[#1A365D]/30 focus:border-[#1A365D]"
                                    autoFocus
                                />
                                <button
                                    onClick={() => setSearchOpen(false)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2"
                                >
                                    <XMarkIcon className="w-4 h-4 text-gray-400" />
                                </button>
                            </div>
                        </div>
                    )}
                </header>

                {/* Flash Messages */}
                {flash?.success && (
                    <div className="mx-4 sm:mx-6 mt-4 px-4 py-3 bg-[#2D7D46]/10 border border-[#2D7D46]/20 rounded-lg text-sm text-[#2D7D46] font-medium">
                        {flash.success}
                    </div>
                )}
                {flash?.warning && (
                    <div className="mx-4 sm:mx-6 mt-4 px-4 py-3 bg-[#E5A100]/10 border border-[#E5A100]/25 rounded-lg text-sm text-[#8a6100] font-medium">
                        {flash.warning}
                    </div>
                )}
                {flash?.error && (
                    <div className="mx-4 sm:mx-6 mt-4 px-4 py-3 bg-[#C53030]/10 border border-[#C53030]/20 rounded-lg text-sm text-[#C53030] font-medium">
                        {flash.error}
                    </div>
                )}

                {/* Page Content */}
                <main className="p-4 sm:p-6">
                    {children}
                </main>
            </div>
        </div>
    );
}

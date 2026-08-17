import { Link } from '@inertiajs/react';
import { ShieldCheckIcon } from '@heroicons/react/24/outline';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen">
            {/* Left Panel - Branding */}
            <div className="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-[#1A365D] to-[#0F2440] flex-col justify-between p-12">
                <div>
                    <div className="flex items-center gap-3">
                        <div className="bg-[#D4AF37] p-2 rounded-xl">
                            <ShieldCheckIcon className="w-8 h-8 text-[#1A365D]" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold text-white">IT Risk Mgt</h1>
                            <span className="text-[#D4AF37] text-sm font-medium">GRC Suite</span>
                        </div>
                    </div>
                </div>

                <div>
                    <h2 className="text-3xl font-bold text-white leading-tight">
                        IT & Security Risk Management
                    </h2>
                    <p className="text-blue-200 mt-4 text-base leading-relaxed">
                        Enterprise-grade governance, risk, and compliance platform built for the African market.
                        Manage risks, ensure compliance, and protect your organization.
                    </p>
                    <div className="mt-8 grid grid-cols-2 gap-4">
                        {[
                            'NDPA 2023 Ready',
                            'CBN Compliant',
                            'ISO 27001',
                            'NIST CSF 2.0',
                        ].map((badge) => (
                            <div key={badge} className="flex items-center gap-2 text-blue-200 text-sm">
                                <div className="w-1.5 h-1.5 bg-[#D4AF37] rounded-full" />
                                {badge}
                            </div>
                        ))}
                    </div>
                </div>

                <p className="text-blue-300/50 text-xs">
                    &copy; {new Date().getFullYear()} IT Risk Mgt GRC Suite. All rights reserved.
                </p>
            </div>

            {/* Right Panel - Form */}
            <div className="w-full lg:w-1/2 flex flex-col items-center justify-center bg-[#F7FAFC] p-6 sm:p-12">
                {/* Mobile Logo */}
                <div className="lg:hidden mb-8 flex items-center gap-3">
                    <div className="bg-[#D4AF37] p-2 rounded-xl">
                        <ShieldCheckIcon className="w-7 h-7 text-[#1A365D]" />
                    </div>
                    <div>
                        <h1 className="text-xl font-bold text-[#1A365D]">IT Risk Mgt</h1>
                        <span className="text-[#D4AF37] text-xs font-medium">GRC Suite</span>
                    </div>
                </div>

                <div className="w-full max-w-md">
                    <div className="bg-white rounded-xl shadow-lg border border-gray-100 px-8 py-8">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
